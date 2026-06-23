# Fediverse — Avatar & Bio sync

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Include a local user's bio (`summary`) and avatar (`icon`) in their ActivityPub actor document, and push an `Update{Person}` when the bio changes — so Mastodon shows the current bio/avatar.

**Architecture:** Extend `PersonSerializer::serialize()` with optional `summary`/`icon`, plus a static `profileExtras(User)` helper that derives them (bio from `Profile.about` via `RichText::output`; avatar from `$user->getProfileImage()->getUrl(null, true)`). The two per-user callers — `ActorController::actionIndex` and `Events::onProfileUpdated` — pass the extras; the instance-actor path passes nothing (defaults → unchanged). `onProfileUpdated` additionally fires on `about` changes (not just first/last name).

**Tech Stack:** PHP 8, Yii2, HumHub Profile/ProfileImage + RichText, the existing `Update{Person}` + `DeliverActivity` fan-out. One-directional (HumHub → Fediverse).

---

## Scope

- **In:** `summary` (bio) and `icon` (avatar URL) in the actor doc (served at `/actor` and in `Update{Person}`); `Update{Person}` pushed on first/last-name OR bio (`about`) change.
- **Out (→ optional follow-up):** a dedicated trigger on a pure avatar change (HumHub avatar upload doesn't go through `Profile::save()` and has no clean event) — the new avatar propagates on the next name/bio update or when Mastodon refetches the actor. Username/`preferredUsername` changes; `summary`/`icon` on the instance Application actor.

## Prerequisites

- Display-name sync shipped (`PersonSerializer::serialize`, `PersonSerializer::updateActivity`, `Events::onProfileUpdated` bound to `Profile afterUpdate`, `Events::federatableActor`, `DeliverActivity` fan-out). `ActorController::actionIndex` serves the per-user actor; `actionInstance` serves the Application actor (no `User`).
- Live env `https://test.fedihub.social`; admin federatable with follower `@cmburns`. No `config.php` change (event already registered) → no cache flush needed; opcache 2s.

---

## File structure (modified)

```
fediverse/
├── services/PersonSerializer.php                          # MODIFY: optional summary/icon + profileExtras()
├── controllers/ActorController.php                        # MODIFY: actionIndex passes profileExtras
├── Events.php                                             # MODIFY: onProfileUpdated trigger on 'about' + pass extras
└── tests/codeception/unit/PersonSerializerUpdateTest.php  # MODIFY: serialize summary/icon test
```

---

## Task 1: `PersonSerializer` — optional `summary`/`icon` + `profileExtras()`

**Files:** Modify `services/PersonSerializer.php`; Modify `tests/codeception/unit/PersonSerializerUpdateTest.php`

- [ ] **Step 1: Add the failing test** (new method in the existing `PersonSerializerUpdateTest` class):

```php
    public function testSerializeIncludesSummaryAndIconWhenProvided()
    {
        $actor = new LocalActor();
        $actor->ap_id = 'https://test.fedihub.social/fediverse/actor/abc';
        $actor->type = 'Person';
        $actor->preferred_username = 'abc';
        $actor->public_key = 'KEY';

        $icon = ['type' => 'Image', 'url' => 'https://test.fedihub.social/a.jpg'];
        $doc = (new PersonSerializer())->serialize($actor, 'Name', 'https://test.fedihub.social/u/abc', '<p>bio</p>', $icon);
        $this->assertSame('<p>bio</p>', $doc['summary']);
        $this->assertSame($icon, $doc['icon']);

        $bare = (new PersonSerializer())->serialize($actor, 'Name', 'https://test.fedihub.social/u/abc');
        $this->assertArrayNotHasKey('summary', $bare);
        $this->assertArrayNotHasKey('icon', $bare);
    }
```

- [ ] **Step 2: Run it** (best-effort; codeception runs via core/CI — `php -l` is the required bar). Expected: FAIL (extra args ignored / keys absent) before the change.

- [ ] **Step 3: Edit `services/PersonSerializer.php`**

Add imports (with the existing `use humhub\modules\fediverse\models\LocalActor;`):
```php
use humhub\modules\content\widgets\richtext\RichText;
use humhub\modules\user\models\User;
```

Change the `serialize()` signature and append the optional fields just before `return`:
```php
    public function serialize(LocalActor $actor, string $name, string $profileUrl, ?string $summary = null, ?array $icon = null): array
    {
        $id = $actor->ap_id;

        $doc = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $id,
            'type' => $actor->type,
            'preferredUsername' => $actor->preferred_username,
            'name' => $name,
            'url' => $profileUrl,
            'inbox' => $id . '/inbox',
            'outbox' => $id . '/outbox',
            'followers' => $id . '/followers',
            'following' => $id . '/following',
            'endpoints' => [
                'sharedInbox' => FederationUrl::sharedInboxUrl(),
            ],
            'publicKey' => [
                'id' => $id . '#main-key',
                'owner' => $id,
                'publicKeyPem' => $actor->public_key,
            ],
        ];
        if ($summary !== null && $summary !== '') {
            $doc['summary'] = $summary;
        }
        if ($icon !== null) {
            $doc['icon'] = $icon;
        }
        return $doc;
    }
```
(Keep the existing doc fields exactly; only the signature and the two trailing `if` blocks are new. `updateActivity()` is unchanged.)

Add this static helper to the class:
```php
    /**
     * Derive a user's actor-document extras: bio (summary) and avatar (icon).
     * `summary` is null when the user has no bio. Call from contexts with a
     * request (web), so the avatar URL resolves to an absolute https URL.
     *
     * @return array{summary:?string,icon:array}
     */
    public static function profileExtras(User $user): array
    {
        $about = trim((string) ($user->profile?->about ?? ''));
        return [
            'summary' => $about !== '' ? RichText::output($about) : null,
            'icon' => ['type' => 'Image', 'url' => $user->getProfileImage()->getUrl(null, true)],
        ];
    }
```

- [ ] **Step 4: Run the test** (best-effort) → PASS. Always: `php -l services/PersonSerializer.php && php -l tests/codeception/unit/PersonSerializerUpdateTest.php` → `No syntax errors detected`.

- [ ] **Step 5: STOP — do not commit.** Report `git diff --stat`.

---

## Task 2: Wire the per-user callers + bio trigger

**Files:** Modify `controllers/ActorController.php`, `Events.php`

- [ ] **Step 1: `ActorController::actionIndex`** — pass the extras. Change:
```php
        $actor = (new KeyManager())->getOrCreateForUser($user);
        $doc = (new PersonSerializer())->serialize($actor, $user->displayName, $user->getUrl(true));
```
to:
```php
        $actor = (new KeyManager())->getOrCreateForUser($user);
        $extras = PersonSerializer::profileExtras($user);
        $doc = (new PersonSerializer())->serialize($actor, $user->displayName, $user->getUrl(true), $extras['summary'], $extras['icon']);
```
Leave `actionInstance()` UNCHANGED (the instance Application actor has no `User`; its `serialize()` call passes no extras, so summary/icon are simply absent).

- [ ] **Step 2: `Events::onProfileUpdated`** — fire on bio too, and pass the extras.

Change the change-detection guard:
```php
        $changed = $event->changedAttributes ?? [];
        if (!array_key_exists('firstname', $changed) && !array_key_exists('lastname', $changed)) {
            return; // display name (first/last) unchanged
        }
```
to:
```php
        $changed = $event->changedAttributes ?? [];
        if (!array_key_exists('firstname', $changed)
            && !array_key_exists('lastname', $changed)
            && !array_key_exists('about', $changed)) {
            return; // nothing federatable changed (name or bio)
        }
```

Change the document build:
```php
        $serializer = new PersonSerializer();
        $document = $serializer->serialize($actor, $user->displayName, $user->getUrl(true));
        $activity = $serializer->updateActivity($actor, $document, $actor->ap_id . '#updates/' . bin2hex(random_bytes(8)));
```
to:
```php
        $serializer = new PersonSerializer();
        $extras = PersonSerializer::profileExtras($user);
        $document = $serializer->serialize($actor, $user->displayName, $user->getUrl(true), $extras['summary'], $extras['icon']);
        $activity = $serializer->updateActivity($actor, $document, $actor->ap_id . '#updates/' . bin2hex(random_bytes(8)));
```
(`PersonSerializer` is already imported in `Events.php`.)

- [ ] **Step 3: Verify** `php -l controllers/ActorController.php && php -l Events.php` → `No syntax errors detected`.

- [ ] **Step 4: STOP — do not commit.** Report the `git diff` of both files.

---

## Deploy + live verification (controller + human)

1. (controller) No cache flush / restart needed (no `config.php` change; opcache 2s).
2. **Admin** (https://test.fedihub.social): edit the profile **About/bio** text and (optionally) upload a new **avatar**; save.
3. **Cmburns** (Mastodon, `@cmburns@mastodon.social`): open `@admin@test.fedihub.social`'s profile → the **bio** updates (the `about` change pushes an `Update{Person}`); the **avatar** shows the current image (delivered with that same Update, or on Mastodon's next refetch).
4. (controller) Confirm no `DeliverActivity` warning; optionally GET `/fediverse/actor/<guid>` and check the JSON now has `summary` + `icon`.

## Done criteria

- `php -l` clean; `serialize()` summary/icon unit test added.
- Live: editing the bio updates it on a follower's Mastodon view; the actor JSON includes `summary` + `icon`. A profile save that changes neither name nor bio still emits nothing; the instance actor doc is unchanged.

## Self-review risks to confirm live

- **Absolute avatar URL:** `getProfileImage()->getUrl(null, true)` must be an absolute, publicly-fetchable https URL (built in web context). Confirm Mastodon can load the avatar (or at least that the URL is absolute in the actor JSON).
- **Instance actor unaffected:** `actionInstance()` still serializes without summary/icon (no `User`); confirm `/fediverse/instance-actor` still returns 200 and unchanged shape.
- **Bio rendering:** `RichText::output($about)` yields HTML Mastodon accepts in `summary`; confirm no raw markup/`<img>`-only artifacts (emoji caveat is the separate Polish item).
- **No over-trigger:** only name/bio changes federate; an unrelated profile field save (e.g. phone) emits no `Update`.
