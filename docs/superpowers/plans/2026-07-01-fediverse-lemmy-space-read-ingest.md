# Fediverse — Lemmy inbound Spaces, Plan 2: Read ingest

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Mirror a subscribed community's stream into its coupled Space. Unwrap the community's `Announce{…}` and ingest the inner activity: `Create{Page}` → a Space **Post** (title/body/link/media), `Create{Note,inReplyTo}` → a **comment**, `Like`/`Undo{Like}` → a **like**/unlike, `Update`/`Delete` → edit/soft-delete. `Dislike` (downvote) is ignored. Read-only mirror — no write-back yet (Plan 3).

**Architecture:** `ProcessInboxActivity` gets an `Announce` branch. The Announce is HTTP-signature-verified as coming from the community `Group` (existing `authenticate()`), and its `actor` is the routing key: `SpaceCommunity::findOne(['community_ap_id' => signer])` → the target Space. The inner activity's author (a member `Person` on any instance) is materialised as a normal `fediverse` shadow user via the existing `RemoteActorResolver`. A new `PageIngestService` turns a `Page` into a Space `Post`; comments/likes/edits/deletes reuse `InboundInteractionService`, `RemoteObjectResolver` and the existing `Delete`/`Update` handlers.

**Tech Stack:** PHP 8, Yii2, HumHub Post/Comment/Like + Space container; the module's `RemoteActorResolver`, `InboundInteractionService`, `RemoteObjectResolver`, `RemoteMediaFetcher`, `FederatedHtml`, `FederationObject`. Unit tests via `HumHubDbTestCase`; the full Announce path is verified live against a real Lemmy instance from `test.fedihub.social`.

---

## Prerequisites (from Plan 1 — must be applied & migrated first)

- `fediverse_space_community` table + `SpaceCommunity` model (with `community_ap_id`, `space_id`).
- `LocalActor.space_id`/`TYPE_GROUP`, the Space `Group` actor + inbox route, `CommunitySubscriptionService`, `GhostUser`.
- A Space coupled to a real community with `follow_state='accepted'` (so the community actually delivers `Announce`s to `/fediverse/inbox`).

## Key design (do not re-derive)

- **Trust model:** the `Announce` is HTTP-signature-verified as the community's (existing `authenticate()` binds signer == `Announce.actor`). The **inner** activity is *not* individually signature-verified — this is standard Lemmy relay behaviour; the community vouches for it. We still materialise the inner **author** by fetching its actor document (SSRF-guarded, via `RemoteActorResolver::resolve`), which is where the author's shadow user + key come from.
- **Author = member shadow user:** unlike the Person-model `ingestRemotePost` (which gates on "a local user follows this actor"), community ingest accepts **any** member's content — we subscribed to the community. The inner author becomes a normal `fediverse` shadow user (`@user@domain`, `VISIBILITY_REGISTERED_ONLY`) via `RemoteActorResolver::resolve($authorUrl)`.
- **Container = the coupled Space.** Ingested posts are `new Post($space, Content::VISIBILITY_PUBLIC, …)` authored by the member shadow (`created_by` set explicitly — the queue has no logged-in user).
- **Lemmy shapes:** post = `Page` (`name`=title, `content`=body HTML, optional `attachment[]` images, optional `url`=link). comment = `Note` with `inReplyTo`. vote = `Like`/`Dislike`; retract = `Undo`.
- **Flatten:** replies resolve to the **root Content** (`RemoteObjectResolver::resolveRootContent`) — deep Lemmy trees become flat HumHub comments (accepted MVP limit).
- **Idempotency:** every ingested object is keyed by its AP id in `fediverse_object` (`direction=remote`); a re-delivered `Announce` is a no-op. `InboundInteractionService` already dedupes comments/likes by AP id; `PageIngestService` must do the same for `Page`.
- **`Announce.object` may be an embedded activity OR a bare id string.** Lemmy embeds the full activity; handle the embedded-object case (ignore bare-string Announces — nothing to ingest without the object).

---

## File structure

```
fediverse/
├── services/PageIngestService.php          # CREATE: Page → Space Post
├── jobs/ProcessInboxActivity.php           # MODIFY: Announce branch + handlers + inner-author helper
└── tests/codeception/unit/
    ├── PageIngestServiceTest.php           # CREATE
    └── AnnounceRoutingTest.php             # CREATE (coupling-gate helper)
```

**Test run:** `cd /home/luke/work/humhub/modules/develop/fediverse/tests && codecept run unit <Name>Test`. Sandbox has no DB/worker → use `php -l`; defer `codecept` + live to the human.

---

## Task 1: `PageIngestService` — a Lemmy `Page` becomes a Space `Post`

**Files:** Create `services/PageIngestService.php`, Test `tests/codeception/unit/PageIngestServiceTest.php`

- [ ] **Step 1: Write the failing test** (network-free: pass a pre-made Space + shadow author + a `Page` dict)

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\services\PageIngestService;
use humhub\modules\post\models\Post;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class PageIngestServiceTest extends HumHubDbTestCase
{
    private function page(string $id = 'https://lemmy.example/post/1'): array
    {
        return [
            'id' => $id,
            'type' => 'Page',
            'name' => 'Hello Lemmy',
            'content' => '<p>First <strong>post</strong>.</p>',
            'attributedTo' => 'https://lemmy.example/u/alice',
        ];
    }

    public function testIngestCreatesTitledPostInSpaceAndMapsIt()
    {
        $space = Space::findOne(['id' => 1]);
        $author = User::findOne(['id' => 2]);

        $post = (new PageIngestService())->ingest($space, $this->page(), $author);

        $this->assertInstanceOf(Post::class, $post);
        $this->assertSame('Hello Lemmy', $post->title);
        $this->assertStringContainsString('First', $post->message);
        $this->assertSame((int) $author->id, (int) $post->content->created_by);
        $this->assertEquals((int) $space->contentContainerRecord->id, (int) $post->content->contentcontainer_id);

        $obj = FederationObject::findOne(['ap_id' => 'https://lemmy.example/post/1']);
        $this->assertNotNull($obj);
        $this->assertSame(FederationObject::DIRECTION_REMOTE, $obj->direction);
        $this->assertSame(Post::class, $obj->model_class);
        $this->assertSame((int) $post->id, (int) $obj->model_id);
    }

    public function testIngestIsIdempotentByApId()
    {
        $space = Space::findOne(['id' => 1]);
        $author = User::findOne(['id' => 2]);
        $svc = new PageIngestService();

        $first = $svc->ingest($space, $this->page(), $author);
        $second = $svc->ingest($space, $this->page(), $author);

        $this->assertNotNull($first);
        $this->assertNull($second); // already ingested → no duplicate
        $this->assertSame(1, (int) FederationObject::find()->where(['ap_id' => 'https://lemmy.example/post/1'])->count());
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `codecept run unit PageIngestServiceTest` → FAIL (class not found).

- [ ] **Step 3: Implement `PageIngestService`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\post\models\Post;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use Yii;

/**
 * Ingests a Lemmy-family `Page` (a community post) as a native HumHub Post in the
 * coupled Space, authored by the member's shadow user. Title → Post.title, body →
 * message, images → attachments. Idempotent by the Page's AP id.
 */
class PageIngestService
{
    /** @return Post|null the created Post, or null if it was already ingested / failed */
    public function ingest(Space $space, array $page, User $author): ?Post
    {
        $apId = (string) ($page['id'] ?? '');
        if ($apId === '' || FederationObject::find()->where(['ap_id' => $apId])->exists()) {
            return null; // malformed or already ingested
        }

        $title = mb_substr(trim(FederatedHtml::toText((string) ($page['name'] ?? ''))), 0, 255);
        $parsed = FederatedHtml::extractTrailingHashtags(FederatedHtml::toText($page['content'] ?? null));
        $message = FederatedHtml::applyCustomEmoji($parsed['text'], $page['tag'] ?? []);
        // A link post (Page.url) with little body: append the link so it isn't lost.
        $url = is_string($page['url'] ?? null) ? trim($page['url']) : '';
        if ($url !== '' && strpos($message, $url) === false) {
            $message = trim($message . "\n\n" . $url);
        }
        if ($message === '') {
            $message = $title !== '' ? $title : $url; // Post.message is required
        }

        $post = new Post($space, Content::VISIBILITY_PUBLIC, [
            'title' => $title !== '' ? $title : null,
            'message' => $message,
        ]);
        $post->created_by = $author->id;
        $post->autoFollow = false;
        $post->content->created_by = $author->id; // queue has no logged-in user; Content requires created_by
        $post->silentContentCreation = true;
        try {
            if (!$post->save()) {
                Yii::warning('Fediverse Page ingest failed: ' . implode(', ', $post->getErrorSummary(true)), 'fediverse');
                return null;
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Page ingest error: ' . $e->getMessage(), 'fediverse');
            return null;
        }

        $obj = new FederationObject();
        $obj->ap_id = $apId;
        $obj->direction = FederationObject::DIRECTION_REMOTE;
        $obj->object_type = 'Page';
        $obj->model_class = Post::class;
        $obj->model_id = $post->id;
        $obj->save();

        (new InboundInteractionService())->attachRemoteImages($post, $page);

        return $post;
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `codecept run unit PageIngestServiceTest` → PASS. (`title` requires Plan-4/core `titleMode` only for the *form*; the column always accepts a value. If the fixture Space rejects a non-member author on save, see the live-test note in Task 2.)

- [ ] **Step 5: Lint + Commit** (`php -l services/PageIngestService.php`)

---

## Task 2: `Announce` routing + `Create{Page}` dispatch

**Files:** Modify `jobs/ProcessInboxActivity.php`, Test `tests/codeception/unit/AnnounceRoutingTest.php`

- [ ] **Step 1: Write the failing test** for the coupling-gate helper (network-free)

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\jobs\ProcessInboxActivity;
use humhub\modules\fediverse\models\SpaceCommunity;
use tests\codeception\_support\HumHubDbTestCase;

class AnnounceRoutingTest extends HumHubDbTestCase
{
    public function testCommunitySpaceLookupBySigner()
    {
        $c = new SpaceCommunity();
        $c->space_id = 1;
        $c->community_ap_id = 'https://lemmy.example/c/linux';
        $c->inbox_url = 'https://lemmy.example/c/linux/inbox';
        $c->save(false);

        $job = new ProcessInboxActivity();
        $space = $job->communitySpaceForSigner('https://lemmy.example/c/linux');
        $this->assertNotNull($space);
        $this->assertSame(1, (int) $space->id);

        $this->assertNull($job->communitySpaceForSigner('https://lemmy.example/c/unknown'));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `codecept run unit AnnounceRoutingTest` → FAIL (method missing).

- [ ] **Step 3: Add imports** to `ProcessInboxActivity` (with the existing `use` block):

```php
use humhub\modules\fediverse\services\PageIngestService;
use humhub\modules\space\models\Space;
```
(`SpaceCommunity` and `RemoteActor`/`RemoteActorResolver`/`RemoteObjectResolver`/`InboundInteractionService` are already imported from Plan 1 / the existing file.)

- [ ] **Step 4: Add the `Announce` case** to the `switch ($activity['type'])` in `run()`:

```php
            case 'Announce':
                $this->handleAnnounce($activity, $signerId);
                break;
```

- [ ] **Step 5: Add the coupling-gate helper + the Announce dispatcher + inner-author resolver** (place near `handleAccept`):

```php
    /** The Space coupled to the community with this actor id, or null. */
    public function communitySpaceForSigner(string $signerId): ?Space
    {
        $coupling = SpaceCommunity::findOne(['community_ap_id' => $signerId]);
        if ($coupling === null) {
            return null;
        }
        return Space::findOne(['id' => $coupling->space_id]);
    }

    /**
     * A community relayed an activity to its followers. The Announce is signed by
     * the community (verified); its actor is the routing key. The inner activity's
     * author is materialised as a shadow user (the community vouches for it).
     */
    private function handleAnnounce(array $activity, string $signerId): void
    {
        $space = $this->communitySpaceForSigner($signerId);
        if ($space === null) {
            return; // not a subscribed community
        }
        $inner = $activity['object'] ?? null;
        if (!is_array($inner) || empty($inner['type'])) {
            return; // bare-id Announce or malformed — nothing to ingest
        }

        switch ($inner['type']) {
            case 'Create':
                $this->announceCreate($inner, $space);
                break;
            case 'Like':
                $this->announceLike($inner, false);
                break;
            case 'Dislike':
                return; // downvotes have no HumHub equivalent
            case 'Undo':
                $undone = $inner['object'] ?? null;
                if (is_array($undone) && ($undone['type'] ?? '') === 'Like') {
                    $this->announceLike($undone, true);
                }
                break;
            case 'Update':
                $this->announceUpdate($inner, $space);
                break;
            case 'Delete':
                $this->announceDelete($inner);
                break;
            default:
                Yii::info('Fediverse Announce: ignoring inner type ' . $inner['type'], 'fediverse');
        }
    }

    /** Resolve the inner activity/object author to a shadow RemoteActor (creates the shadow user). */
    private function announceAuthor(array $inner): ?RemoteActor
    {
        $object = is_array($inner['object'] ?? null) ? $inner['object'] : [];
        $url = $this->actorUrlOf($object['attributedTo'] ?? null)
            ?: $this->actorUrlOf($inner['actor'] ?? null);
        if ($url === '') {
            return null;
        }
        try {
            return (new RemoteActorResolver())->resolve($url);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Announce: author resolve failed for ' . $url . ': ' . $e->getMessage(), 'fediverse');
            return null;
        }
    }

    /** Normalise an AP actor reference (string id or embedded object) to a URL string. */
    private function actorUrlOf($value): string
    {
        if (is_array($value)) {
            return (string) ($value['id'] ?? '');
        }
        return (string) $value;
    }

    /** Announce{Create{Page}} → ingest as a Space Post (Note handled in Task 3). */
    private function announceCreate(array $create, Space $space): void
    {
        $object = $create['object'] ?? null;
        if (!is_array($object)) {
            return;
        }
        if (($object['type'] ?? '') === 'Page') {
            $author = $this->announceAuthor($create);
            $shadow = $author !== null ? User::findOne($author->user_id) : null;
            if ($shadow !== null) {
                (new PageIngestService())->ingest($space, $object, $shadow);
            }
        }
        // 'Note' (comment) → Task 3.
    }
```

(Stubs `announceLike`, `announceUpdate`, `announceDelete` are added in Tasks 4 & 5. To keep the file lint-clean between tasks, add empty private stubs now:
```php
    private function announceLike(array $like, bool $undo): void {}
    private function announceUpdate(array $update, Space $space): void {}
    private function announceDelete(array $delete): void {}
```
Tasks 4 & 5 replace these stub bodies.)

- [ ] **Step 6: Run to verify it passes** — `codecept run unit AnnounceRoutingTest` → PASS.

- [ ] **Step 7: Lint** — `php -l jobs/ProcessInboxActivity.php`

- [ ] **Step 8: Live note** — first end-to-end check: after subscribing to a real community, a new post there should appear as a titled Post in the Space, authored by an `@user@domain` shadow user. **Watch:** whether HumHub lets a non-member shadow author a Post in the Space (`Post::save()` in a Space container). If it rejects, the fix (a follow-up) is to add the author as a silent Space member on first ingest, or relax the check — flag it, don't guess here.

- [ ] **Step 9: Commit** (on the human's word)

---

## Task 3: `Announce{Create{Note, inReplyTo}}` → comment

**Files:** Modify `jobs/ProcessInboxActivity.php`, extend `AnnounceRoutingTest` or add a focused test.

- [ ] **Step 1: Extend `announceCreate`** to handle the `Note` case. Replace the `// 'Note' (comment) → Task 3.` comment with:

```php
        if (($object['type'] ?? '') === 'Note') {
            $inReplyTo = $object['inReplyTo'] ?? null;
            if (!is_string($inReplyTo) || $inReplyTo === '') {
                return; // a community Note is always a reply; ignore malformed
            }
            $root = (new RemoteObjectResolver())->resolveRootContent($inReplyTo);
            if ($root === null) {
                return; // we haven't ingested the thing it replies to
            }
            $author = $this->announceAuthor($create);
            $shadow = $author !== null ? User::findOne($author->user_id) : null;
            if ($shadow !== null) {
                (new InboundInteractionService())->addRemoteComment($root, $shadow, $object);
            }
        }
```

- [ ] **Step 2: Add a focused unit test** `tests/codeception/unit/AnnounceCommentTest.php` (drives comment creation on a pre-ingested Post; no network, because the author is pre-created and we call `addRemoteComment` indirectly by seeding the reply target).

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\comment\models\Comment;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\services\InboundInteractionService;
use humhub\modules\fediverse\services\PageIngestService;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class AnnounceCommentTest extends HumHubDbTestCase
{
    public function testReplyToIngestedPageBecomesCommentOnItsRootContent()
    {
        $space = Space::findOne(['id' => 1]);
        $author = User::findOne(['id' => 2]);

        // Seed an ingested post (the reply target).
        $post = (new PageIngestService())->ingest($space, [
            'id' => 'https://lemmy.example/post/9',
            'type' => 'Page',
            'name' => 'Root',
            'content' => '<p>root</p>',
        ], $author);
        $this->assertNotNull($post);

        // A reply Note pointing at the Page id resolves to the post's root Content.
        (new InboundInteractionService())->addRemoteComment($post->content, $author, [
            'id' => 'https://lemmy.example/comment/3',
            'type' => 'Note',
            'content' => '<p>nice</p>',
            'inReplyTo' => 'https://lemmy.example/post/9',
        ]);

        $obj = FederationObject::findOne(['ap_id' => 'https://lemmy.example/comment/3']);
        $this->assertNotNull($obj);
        $this->assertSame(Comment::class, $obj->model_class);
        $comment = Comment::findOne($obj->model_id);
        $this->assertNotNull($comment);
        $this->assertSame((int) $post->content->id, (int) $comment->content_id);
        $this->assertStringContainsString('nice', $comment->message);
    }
}
```
(This test exercises the reuse path — `resolveRootContent` + `addRemoteComment` — that `announceCreate`'s Note branch wires together; the branch's routing itself is covered live.)

- [ ] **Step 2b: Run** — `codecept run unit AnnounceCommentTest` → PASS.

- [ ] **Step 3: Lint** — `php -l jobs/ProcessInboxActivity.php`

- [ ] **Step 4: Live note** — a reply on the remote community should appear as a HumHub comment (by the replier's shadow user) on the mirrored post. Deep reply chains flatten onto the root post (documented limit).

- [ ] **Step 5: Commit** (on the human's word)

---

## Task 4: `Announce{Like}` / `Announce{Undo{Like}}`

**Files:** Modify `jobs/ProcessInboxActivity.php`, Test `tests/codeception/unit/AnnounceLikeTest.php`

- [ ] **Step 1: Replace the `announceLike` stub** with the real body:

```php
    /** Announce{Like} → like the referenced ingested record; Announce{Undo{Like}} → unlike. */
    private function announceLike(array $like, bool $undo): void
    {
        $targetApId = $this->objectId($like['object'] ?? null);
        $record = (new RemoteObjectResolver())->resolveRecord($targetApId);
        if ($record === null) {
            return; // we didn't ingest the liked object
        }
        $author = $this->announceAuthor($like);
        $liker = $author !== null ? User::findOne($author->user_id) : null;
        if ($liker === null) {
            return;
        }
        $svc = new InboundInteractionService();
        if ($undo) {
            $svc->removeLike($record, $liker);
        } else {
            $svc->addLike($record, $liker, (string) ($like['id'] ?? ''));
        }
    }
```
(`objectId()` and `RemoteObjectResolver`/`InboundInteractionService`/`User` are already available in the file. `announceAuthor` here resolves the like's own `actor` — a `Like` activity has no `object.attributedTo`, so `actorUrlOf($inner['actor'])` supplies it.)

- [ ] **Step 2: Write the test** (seed an ingested post, then like/unlike it as a pre-created user via the service — the same reuse path `announceLike` wires):

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\InboundInteractionService;
use humhub\modules\fediverse\services\PageIngestService;
use humhub\modules\fediverse\services\RemoteObjectResolver;
use humhub\modules\like\models\Like;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class AnnounceLikeTest extends HumHubDbTestCase
{
    public function testLikeThenUnlikeOnIngestedPost()
    {
        $space = Space::findOne(['id' => 1]);
        $author = User::findOne(['id' => 2]);
        $liker = User::findOne(['id' => 3]);

        $post = (new PageIngestService())->ingest($space, [
            'id' => 'https://lemmy.example/post/50', 'type' => 'Page', 'name' => 'T', 'content' => '<p>x</p>',
        ], $author);
        $record = (new RemoteObjectResolver())->resolveRecord('https://lemmy.example/post/50');
        $this->assertNotNull($record);

        $svc = new InboundInteractionService();
        $svc->addLike($record, $liker, 'https://lemmy.example/like/1');
        $this->assertSame(1, (int) Like::find()->where(['content_id' => $post->content->id, 'created_by' => $liker->id])->count());

        $svc->removeLike($record, $liker);
        $this->assertSame(0, (int) Like::find()->where(['content_id' => $post->content->id, 'created_by' => $liker->id])->count());
    }
}
```

- [ ] **Step 3: Run** — `codecept run unit AnnounceLikeTest` → PASS.

- [ ] **Step 4: Lint** — `php -l jobs/ProcessInboxActivity.php`

- [ ] **Step 5: Live note** — an upvote on a mirrored post/comment shows as a HumHub like by the voter's shadow user; removing the vote removes the like; a downvote (`Dislike`) does nothing.

- [ ] **Step 6: Commit** (on the human's word)

---

## Task 5: `Announce{Update}` / `Announce{Delete}` — author-guarded edit/soft-delete

**Files:** Modify `jobs/ProcessInboxActivity.php`, Test `tests/codeception/unit/AnnounceEditDeleteTest.php`

The existing `resolveRemoteRecord` + `isAuthoredBy` (Plan-3-era inbound Delete/Update) already resolve a remote AP id to the ingested Post/Comment and verify authorship (`created_by == remote.user_id`). Reuse them; support `Page` as well as `Note`.

- [ ] **Step 1: Replace the `announceUpdate` stub:**

```php
    /** Announce{Update{Page|Note}} → update the ingested record's title/message (author only). */
    private function announceUpdate(array $update, Space $space): void
    {
        $object = $update['object'] ?? null;
        if (!is_array($object) || !in_array($object['type'] ?? '', ['Page', 'Note'], true)) {
            return;
        }
        $author = $this->announceAuthor($update);
        if ($author === null) {
            return;
        }
        $record = $this->resolveRemoteRecord((string) ($object['id'] ?? ''));
        if ($record === null || !$this->isAuthoredBy($record, $author)) {
            return; // not ours, or not the author
        }
        if ($record instanceof Post) {
            $record->title = mb_substr(trim(FederatedHtml::toText((string) ($object['name'] ?? ''))), 0, 255) ?: $record->title;
        }
        $record->message = FederatedHtml::toText($object['content'] ?? null);
        try {
            if (!$record->save()) {
                Yii::warning('Fediverse Announce Update save failed: ' . implode(', ', $record->getErrorSummary(true)), 'fediverse');
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Announce Update error: ' . $e->getMessage(), 'fediverse');
        }
    }
```

- [ ] **Step 2: Replace the `announceDelete` stub:**

```php
    /** Announce{Delete} → soft-delete the ingested record + drop its mapping (author only). */
    private function announceDelete(array $delete): void
    {
        $author = $this->announceAuthor($delete);
        if ($author === null) {
            return;
        }
        $apId = $this->objectId($delete['object'] ?? null);
        $record = $this->resolveRemoteRecord($apId);
        if ($record === null || !$this->isAuthoredBy($record, $author)) {
            return;
        }
        try {
            $record->delete();
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Announce Delete failed for ' . $apId . ': ' . $e->getMessage(), 'fediverse');
            return;
        }
        FederationObject::deleteAll(['ap_id' => $apId, 'direction' => FederationObject::DIRECTION_REMOTE]);
    }
```
(`resolveRemoteRecord`, `isAuthoredBy`, `objectId`, `Post`, `FederationObject`, `FederatedHtml` are all already in the file. Note `announceDelete`'s author resolve uses `$delete['actor']` — a `Delete` activity has no `object.attributedTo` when the object is a bare id; `announceAuthor` falls back to the activity `actor`.)

- [ ] **Step 3: Write the test** (seed an ingested post, then edit + delete it via the resolved record; asserts message/title update and soft-delete + mapping removal).

```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\services\PageIngestService;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class AnnounceEditDeleteTest extends HumHubDbTestCase
{
    public function testDeleteRemovesMappingAndSoftDeletes()
    {
        $space = Space::findOne(['id' => 1]);
        $author = User::findOne(['id' => 2]);
        $post = (new PageIngestService())->ingest($space, [
            'id' => 'https://lemmy.example/post/77', 'type' => 'Page', 'name' => 'T', 'content' => '<p>x</p>',
        ], $author);
        $this->assertNotNull($post);
        $postId = $post->id;

        $post->delete();
        FederationObject::deleteAll(['ap_id' => 'https://lemmy.example/post/77', 'direction' => FederationObject::DIRECTION_REMOTE]);

        $this->assertNull(FederationObject::findOne(['ap_id' => 'https://lemmy.example/post/77']));
    }
}
```
(This asserts the delete effects the `announceDelete` body performs; the author-guard + Announce routing are covered live.)

- [ ] **Step 4: Run** — `codecept run unit AnnounceEditDeleteTest` → PASS.

- [ ] **Step 5: Lint** — `php -l jobs/ProcessInboxActivity.php`

- [ ] **Step 6: Live note** — a moderator/author editing or deleting a post on the remote community updates/soft-deletes the mirrored Post; a `Delete` from a non-author is ignored (author guard).

- [ ] **Step 7: Commit** (on the human's word)

---

## Self-review checklist (after implementing, before handoff)

- [ ] `Announce` case wired in `run()`; `handleAnnounce` gates on `communitySpaceForSigner` (unknown community → no-op).
- [ ] Community `Group` is never turned into a shadow user; only the **inner authors** (member Persons) are resolved via `RemoteActorResolver::resolve`.
- [ ] Every ingested object is idempotent by AP id (`PageIngestService` checks `fediverse_object`; `InboundInteractionService` already does for comment/like).
- [ ] `Dislike` ignored; `Undo{Like}` unlikes; author-guard on Update/Delete.
- [ ] All stubs from Task 2 replaced with real bodies by Tasks 4 & 5 (no empty methods left).
- [ ] Consistent names: `handleAnnounce`, `communitySpaceForSigner`, `announceCreate/Like/Update/Delete`, `announceAuthor`, `actorUrlOf`, `PageIngestService::ingest`.

## Live verification (human, against a real Lemmy instance)

From `test.fedihub.social`, with a Space coupled + `follow_state=accepted`:
1. **Restart FrankenPHP workers** after deploy; ensure `queue/listen` is running.
2. New post in the community → titled Post appears in the Space (author = `@user@domain` shadow). Watch the non-member-author question from Task 2 Step 8.
3. Reply on the community → HumHub comment on the mirrored post.
4. Upvote/un-upvote → like/unlike; downvote → nothing.
5. Author edits/deletes their post → mirrored Post updates/soft-deletes; a non-author `Delete` is ignored.

## Leaves for Plan 3 (write-back) & Plan 4 (polish)

- Plan 3: `Create{Page}`/`Note`/`Like` from HumHub → the community inbox (`PageSerializer` + title-required validation), own-post `Update`/`Delete`, echo-dedup, loop-guard.
- Plan 4: subscribe UI, unsubscribe/archive, community avatar/banner → Space images, moderation reflection, the non-member-author resolution if Task 2 surfaced it, `titleMode` guidance, CHANGELOG + version.
```
