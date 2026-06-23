# Fediverse — Download & store remote media (avatars, banners, post images)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax. **DO NOT COMMIT — the controller commits on the human's explicit word.** This feature downloads remote URLs — implement the safety guards EXACTLY.

**Goal:** Fetch remote media and store it natively in HumHub: a remote actor's avatar → the shadow user's profile image, their header → the banner image, and images attached to a remote post → real HumHub file attachments. Also refresh name/bio/avatar/banner when a remote profile changes (inbound `Update{Person}`).

**Architecture:** A new SSRF-guarded `RemoteMediaFetcher` (HTTPS-only via `FederationHttpClient`, image-only by `Content-Type`, size-capped) returns raw bytes + mime. `RemoteActorResolver` applies avatar/banner on shadow-user creation; `ProcessInboxActivity::ingestRemotePost` downloads a Note's image attachments into `File`s attached to the Post; `ProcessInboxActivity::handleUpdate` gains an actor branch that refreshes the shadow user (name/bio/avatar/banner) on `Update{Person}`.

**Tech Stack:** PHP 8, Yii2, HumHub `AssetImage` (`$user->image` / `$user->bannerImage` → `setByFile($path)`), `humhub\modules\file\models\File` (`setStoredFileContent` + `FileManager::attach`), existing `UrlSafety` + `FederationHttpClient`. Runs in the inbox queue worker (all APIs are queue-safe).

---

## Scope

- **In:** download + store remote avatar (`icon`), banner (`image`), and post image attachments (`attachment[]`) on ingest; refresh name/bio/avatar/banner on inbound `Update{Person}`.
- **Out (→ later):** non-image attachments (video/audio/PDF); custom-emoji images; updating attachments on `Update{Note}` (edit just changes text); backfilling media for the 17 pre-existing shadow users (they refresh on their next `Update{Person}` or could be done via a one-off command later).

## Safety (mandatory — this fetches arbitrary remote URLs)

- **SSRF:** every URL passes `UrlSafety::isSafePublicUrl()` before fetch (same guard as actor fetches).
- **HTTPS-only + no redirects:** enforced by the existing `FederationHttpClient`.
- **Image-only:** reject unless the response `Content-Type` starts with `image/`.
- **Size cap:** reject if `Content-Length` (when present) or the body length exceeds `MAX_BYTES` (10 MB).
- **Attachment count cap:** ingest at most `MAX_ATTACHMENTS` (8) images per post.
- **Failures never break ingest:** any fetch/convert/save error is caught, logged at `warning`, and skipped — the post/comment/actor still saves.

## Prerequisites

- Inbound ingest + `Update{Note}` shipped (`ProcessInboxActivity::ingestRemotePost`, `handleUpdate`, `authenticate`, `objectId`). `RemoteActorResolver::createShadowUser` creates shadow users from an actor doc. `UrlSafety::isSafePublicUrl`, `FederationHttpClient` exist. `$user->image` / `$user->bannerImage` are `AssetImage` with `setByFile(string $path)`; `Post->fileManager->attach(File)`.
- Live env `https://test.fedihub.social`; admin follows `@cmburns` (who has an avatar + posts with images). Queue `isolate=true` → worker picks up new code; no cache flush.

---

## File structure

```
fediverse/
├── services/RemoteMediaFetcher.php        # NEW: safe image download
├── services/RemoteActorResolver.php       # MODIFY: applyProfileImages() + call on creation
├── jobs/ProcessInboxActivity.php          # MODIFY: ingest attachments + Update{Person} refresh
└── tests/codeception/unit/RemoteMediaFetcherTest.php   # NEW
```

---

## Task 1: `RemoteMediaFetcher` service + test

**Files:** Create `services/RemoteMediaFetcher.php`; Create `tests/codeception/unit/RemoteMediaFetcherTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\RemoteMediaFetcher;
use tests\codeception\_support\HumHubDbTestCase;

class RemoteMediaFetcherTest extends HumHubDbTestCase
{
    public function testRejectsEmptyAndUnsafeUrls()
    {
        $f = new RemoteMediaFetcher();
        $this->assertNull($f->fetchImage(null));
        $this->assertNull($f->fetchImage(''));
        $this->assertNull($f->fetchImage('not-a-url'));
        $this->assertNull($f->fetchImage('http://127.0.0.1/x.png')); // not https / not public
    }

    public function testFilenameForDerivesExtensionFromMime()
    {
        $f = new RemoteMediaFetcher();
        $this->assertSame('avatar.png', $f->filenameFor('https://x.test/path/avatar.png', 'image/png'));
        $this->assertStringEndsWith('.jpg', $f->filenameFor('https://x.test/no-ext', 'image/jpeg'));
    }
}
```

- [ ] **Step 2: Run** `codecept run unit unit/RemoteMediaFetcherTest.php -c tests -v` (best-effort; `php -l` is the required bar). Expected FAIL.

- [ ] **Step 3: Create `services/RemoteMediaFetcher.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use Yii;

/**
 * Downloads remote media (images) for ingest. SSRF-guarded, HTTPS-only (via
 * FederationHttpClient), image-only by Content-Type, and size-capped. Returns
 * raw bytes + mime, or null on any rejection/failure (never throws).
 */
class RemoteMediaFetcher
{
    public const MAX_BYTES = 10485760; // 10 MB

    /** @return array{content:string,mime:string}|null */
    public function fetchImage(?string $url): ?array
    {
        if (!is_string($url) || $url === '' || !UrlSafety::isSafePublicUrl($url)) {
            return null;
        }
        try {
            $response = (new FederationHttpClient())->createRequest()
                ->setMethod('GET')
                ->setUrl($url)
                ->send();
            if (!$response->getIsOk()) {
                return null;
            }
            $declared = (int) $response->getHeaders()->get('content-length', 0);
            if ($declared > self::MAX_BYTES) {
                return null;
            }
            $mime = strtolower(trim(explode(';', (string) $response->getHeaders()->get('content-type', ''))[0]));
            if (strncmp($mime, 'image/', 6) !== 0) {
                return null; // images only
            }
            $content = (string) $response->getContent();
            if ($content === '' || strlen($content) > self::MAX_BYTES) {
                return null;
            }
            return ['content' => $content, 'mime' => $mime];
        } catch (\Throwable $e) {
            Yii::warning('Fediverse media fetch failed for ' . $url . ': ' . $e->getMessage(), 'fediverse');
            return null;
        }
    }

    /** A safe filename (with a sensible extension) for a fetched image. */
    public function filenameFor(string $url, string $mime): string
    {
        $base = basename((string) parse_url($url, PHP_URL_PATH));
        if ($base === '' || $base === '/') {
            $base = 'image';
        }
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $base)) {
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'img',
            };
            $base .= '.' . $ext;
        }
        return mb_substr(preg_replace('/[^\w.\-]/', '_', $base), 0, 200);
    }
}
```

- [ ] **Step 4: Run test** (best-effort) → PASS. Always: `php -l services/RemoteMediaFetcher.php && php -l tests/codeception/unit/RemoteMediaFetcherTest.php`.

- [ ] **Step 5: STOP — do not commit.** Report diff stat.

---

## Task 2: ingest a remote post's image attachments

**Files:** Modify `jobs/ProcessInboxActivity.php`

- [ ] **Step 1: Add imports** (with the existing `use` block): `use humhub\modules\file\models\File;` and `use humhub\modules\fediverse\services\RemoteMediaFetcher;`.

- [ ] **Step 2: Call the attachment helper at the end of `ingestRemotePost`** — right after the `FederationObject` mapping for the post is saved (`$obj->save();`), before the method closes, add:

```php
        $this->attachRemoteImages($post, $note);
```

- [ ] **Step 3: Add the helper** (private method in the class):

```php
    private const MAX_ATTACHMENTS = 8;

    /** Download a remote Note's image attachments and attach them to the ingested post. */
    private function attachRemoteImages(Post $post, array $note): void
    {
        $attachments = $note['attachment'] ?? null;
        if (!is_array($attachments)) {
            return;
        }
        $fetcher = new RemoteMediaFetcher();
        $count = 0;
        foreach ($attachments as $att) {
            if ($count >= self::MAX_ATTACHMENTS) {
                break;
            }
            $url = is_array($att) ? ($att['url'] ?? null) : null;
            if (!is_string($url)) {
                continue;
            }
            $media = $fetcher->fetchImage($url);
            if ($media === null) {
                continue; // non-image / unsafe / too big / failed
            }
            try {
                $file = new File();
                $file->file_name = $fetcher->filenameFor($url, $media['mime']);
                $file->mime_type = $media['mime'];
                $file->size = strlen($media['content']);
                $file->created_by = (int) $post->content->created_by; // shadow user; queue has no logged-in user
                if (!$file->save()) {
                    Yii::warning('Fediverse attachment File save failed: ' . implode(', ', $file->getErrorSummary(true)), 'fediverse');
                    continue;
                }
                $file->setStoredFileContent($media['content']);
                $post->fileManager->attach($file);
                $count++;
            } catch (\Throwable $e) {
                Yii::warning('Fediverse attachment error for ' . $url . ': ' . $e->getMessage(), 'fediverse');
            }
        }
    }
```

- [ ] **Step 4: Verify** `php -l jobs/ProcessInboxActivity.php`. Confirm one `attachRemoteImages`, the call inside `ingestRemotePost`, and the two new imports.

- [ ] **Step 5: STOP — do not commit.** Report the diff.

---

## Task 3: avatar + banner on shadow-user creation

**Files:** Modify `services/RemoteActorResolver.php`

- [ ] **Step 1: Add a reusable media-apply method** (public, so the inbox Update handler reuses it). All referenced classes are in the same `services` namespace except `User` (already imported).

```php
    /** Download the actor doc's avatar (icon) + banner (image) and set them on the user. Best-effort. */
    public function applyProfileImages(User $user, array $doc): void
    {
        $fetcher = new RemoteMediaFetcher();
        $this->applyOneImage($user->image, is_array($doc['icon'] ?? null) ? ($doc['icon']['url'] ?? null) : null, $fetcher);
        $this->applyOneImage($user->bannerImage, is_array($doc['image'] ?? null) ? ($doc['image']['url'] ?? null) : null, $fetcher);
    }

    /** @param mixed $assetImage a humhub AssetImage ($user->image / $user->bannerImage) */
    private function applyOneImage($assetImage, ?string $url, RemoteMediaFetcher $fetcher): void
    {
        $media = $fetcher->fetchImage($url);
        if ($media === null) {
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fedimg');
        if ($tmp === false) {
            return;
        }
        try {
            file_put_contents($tmp, $media['content']);
            $assetImage->setByFile($tmp);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse profile image set failed: ' . $e->getMessage(), 'fediverse');
        } finally {
            @unlink($tmp);
        }
    }
```

- [ ] **Step 2: Call it at the end of `createShadowUser`**, right before `return $user;`:

```php
        $this->applyProfileImages($user, $doc);

        return $user;
```

(`createShadowUser` already has `$doc` and the saved `$user`. `RemoteMediaFetcher` is in the same namespace — no import needed; `Yii` is already imported.)

- [ ] **Step 3: Verify** `php -l services/RemoteActorResolver.php`. Confirm one `applyProfileImages`, one `applyOneImage`, and the call in `createShadowUser`.

- [ ] **Step 4: STOP — do not commit.** Report the diff.

---

## Task 4: inbound `Update{Person}` → refresh name/bio/avatar/banner

**Files:** Modify `jobs/ProcessInboxActivity.php`

- [ ] **Step 1: Add imports** if missing: `use humhub\modules\fediverse\services\RemoteActorResolver;` and `use humhub\modules\user\models\Profile;` (`RemoteActorResolver` may already be imported — check first; do not duplicate).

- [ ] **Step 2: Extend `handleUpdate`** to branch on the object type. The current method handles only `Note`; restructure its top so non-Note actor docs route to a refresh:

Replace the current early `if (!is_array($object) || ($object['type'] ?? '') !== 'Note') { return; }` guard with:

```php
        if (!is_array($object)) {
            return;
        }
        $objType = $object['type'] ?? '';
        if (in_array($objType, ['Person', 'Application', 'Service', 'Group', 'Organization'], true)) {
            $this->refreshRemoteActor($object, $remote);
            return;
        }
        if ($objType !== 'Note') {
            return;
        }
```

(Leave the rest of `handleUpdate` — the Note resolve/author-check/message-update — unchanged below this.)

- [ ] **Step 3: Add `refreshRemoteActor`** (private method):

```php
    /** Inbound Update{Person} → refresh the remote actor's shadow user (name, bio, avatar, banner). */
    private function refreshRemoteActor(array $doc, RemoteActor $remote): void
    {
        // An actor may only update itself.
        if ((string) ($doc['id'] ?? '') !== $remote->ap_id) {
            Yii::warning('Fediverse Update{Person}: object id does not match the signing actor', 'fediverse');
            return;
        }

        if (isset($doc['preferredUsername'])) {
            $remote->preferred_username = (string) $doc['preferredUsername'];
        }
        if (!empty($doc['publicKey']['publicKeyPem'])) {
            $remote->public_key = (string) $doc['publicKey']['publicKeyPem'];
        }
        if (is_array($doc['icon'] ?? null) && !empty($doc['icon']['url'])) {
            $remote->icon_url = (string) $doc['icon']['url'];
        }
        $remote->last_refreshed_at = date('Y-m-d H:i:s');
        $remote->save();

        $user = User::findOne($remote->user_id);
        if ($user === null) {
            return;
        }

        $profile = $user->profile;
        $name = trim((string) ($doc['name'] ?? '')) ?: (string) ($doc['preferredUsername'] ?? '');
        if ($name !== '' && $profile !== null) {
            $profile->firstname = mb_substr($name, 0, 255);
            $summary = trim(strip_tags((string) ($doc['summary'] ?? '')));
            if ($summary !== '') {
                $profile->about = $summary;
            }
            if (!$profile->save()) {
                Yii::warning('Fediverse Update{Person}: profile save failed: ' . implode(', ', $profile->getErrorSummary(true)), 'fediverse');
            }
        }

        (new RemoteActorResolver())->applyProfileImages($user, $doc);
    }
```

- [ ] **Step 4: Verify** `php -l jobs/ProcessInboxActivity.php`. Confirm one `refreshRemoteActor`, the actor branch in `handleUpdate`, and the Note path still intact below it.

- [ ] **Step 5: STOP — do not commit.** Report the diff.

---

## Deploy + live verification (controller + human)

1. (controller) No cache flush / restart (queue `isolate=true`).
2. **Cmburns** (Mastodon, `@cmburns@mastodon.social`): (a) post something **with an image** (admin follows → ingested); (b) change the **display name and/or bio** and (if easy) the **avatar/header**; (c) ensure cmburns has an avatar + header set.
3. **Admin** (HumHub): the ingested post shows the **image** as an attachment; cmburns's shadow profile shows the **avatar** (and **banner** on the profile page); after (b), the name/bio (and avatar/banner) **update**.
   - To also see avatar/banner on FIRST contact: follow a *new* remote account that has an avatar/header, then open its profile in HumHub.
4. (controller) Check: `File` rows created + attached to the post (`object_model=Post`), the shadow user's profile image/banner files exist, and **no** media-fetch warnings for legitimate images; confirm the `Update{Person}` refresh path logged nothing bad.

## Done criteria

- `php -l` clean on all files; `RemoteMediaFetcher` unit test added.
- Live: remote post images appear as HumHub attachments; remote avatars/banners appear on shadow profiles; an `Update{Person}` refreshes name/bio/avatar/banner. Non-image/oversized/unsafe URLs are skipped (logged), and a media failure never breaks ingest.

## Self-review risks to confirm live

- **SSRF/safety:** every fetched URL goes through `UrlSafety::isSafePublicUrl` + HTTPS-only client; confirm a non-image or oversized URL is rejected and that ingest still succeeds when media fails.
- **`AssetImage::setByFile` in the queue worker:** image resizing needs GD/Imagine in the CLI PHP — confirm avatars/banners actually get set (watch for "image set failed" warnings).
- **`File` attach ownership:** the attachment `File` has `created_by` = the shadow user and ends up with `object_model = Post`, `object_id = <post id>`, visible on the post.
- **Update{Person} authorization:** `object.id` must equal `$remote->ap_id` (actor updates only itself); confirm a mismatch is rejected.
- **No re-federation:** updating the shadow user's profile must NOT emit an outbound `Update{Person}` — `onProfileUpdated` calls `federatableActor()` which returns null for shadow users (they have a `RemoteActor`). Confirm no outbound loop when a remote profile refresh saves the shadow `Profile`.
