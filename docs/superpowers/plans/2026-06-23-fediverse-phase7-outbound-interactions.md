# Fediverse Phase 7 — Outbound Interactions

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a local user likes or comments on a post that originated from a remote Fediverse account, send the interaction back: a `Like` (with `Undo{Like}` on unlike) and a `Create{Note, inReplyTo}` reply, delivered signed to the remote author. Completes the Mastodon MVP (§6) in both directions.

**Architecture:** Hooks HumHub's `Like`/`Comment` `afterInsert` (and `Like` `beforeDelete`) events. A `RemoteTargetResolver` maps the liked/commented `Content` to the original remote object id + its `RemoteActor` (via `fediverse_object` `direction=remote` + the shadow-user container). Handlers build the activity, signed by the local actor (reusing Plan 6's `DeliverToInbox` directed-delivery job). **Loop guard:** inbound comments/likes (Plan 5, authored by shadow users) fire the same `afterInsert` events — handlers skip any actor that is itself a federated shadow user, and any target that is not remote-origin.

**Tech Stack:** PHP 8, Yii2, HumHub like/comment events + RichText, OpenSSL signing + queue (Plan 3/6), Codeception. Live-tested: admin likes/replies to cmburns's ingested post → shows on Mastodon.

---

## Roadmap position (plan 7 of 7 — MVP complete)

1–6 ✅ (foundation → outbound posts → inbound interactions → local→remote follow, all verified vs real Mastodon)
7. **Outbound interactions (this plan)** — local like/comment on a remote post → remote author

Spec: `docs/superpowers/specs/2026-06-22-fediverse-module-design.md` (§6 likes/comments, §10).

## Scope

- **In:** a local (federatable) user likes a remote-origin **post** → `Like`; unlikes → `Undo{Like}`; comments on a remote-origin post → `Create{Note, inReplyTo}`. All signed by the local actor, delivered to the remote author's inbox, idempotently mapped.
- **Out (→ later):** liking/replying to remote **comments** (only posts in this phase); federating a local comment on a *local* federated post out to that post's followers (fan-out); `Announce`/boost; editing a federated comment (`Update`).

## Key guards (must hold)

- **Loop prevention:** skip when the acting user is a federated shadow user (`RemoteActor` exists for them) — those are inbound interactions from Plan 5, not local actions.
- **Remote-origin only:** act only when the target `Content` resolves to a `fediverse_object` `direction=remote` (`object_type=Note`, a `Post`). Local-origin targets are skipped.
- **Federatable actor:** the liker/commenter must satisfy `DiscoveryPolicy::isDiscoverable` (fetchable actor so the remote can verify the signature). Otherwise the local like/comment stays local (skipped, logged at debug).
- **Posts only:** likes with a non-empty `content_addon_record_id` (likes on comments) are skipped in this phase.

## Prerequisites

- Plans 1–6 shipped on `develop`; local→remote follow + ingest verified live (admin follows cmburns; cmburns's post is ingested as a Post on the shadow profile and is in admin's dashboard). `DeliverToInbox`, `KeyManager`, `FederationUrl`, `RemoteActor`, `FederationObject`, `DiscoveryPolicy`, `Events.php` exist.
- Live env `https://test.fedihub.social` (restart `fedihub.service` after Events/job changes). admin is federatable; admin follows cmburns and has cmburns's ingested post available to like/comment.

---

## File structure (created/modified)

```
fediverse/
├── config.php                          # MODIFY: register Like afterInsert/beforeDelete + Comment afterInsert
├── Events.php                          # MODIFY: onLikeCreated / onLikeRemoved / onCommentCreated
├── services/
│   └── RemoteTargetResolver.php        # NEW: Content → [remote Note ap_id, RemoteActor] | null
└── tests/codeception/unit/
    └── RemoteTargetResolverTest.php
```

---

## Task 1: `RemoteTargetResolver` — is this content a remote post, and whose?

**Files:** Create `services/RemoteTargetResolver.php`; Test `tests/codeception/unit/RemoteTargetResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\RemoteTargetResolver;
use tests\codeception\_support\HumHubDbTestCase;

class RemoteTargetResolverTest extends HumHubDbTestCase
{
    public function testUnknownContentResolvesNull()
    {
        $this->assertNull((new RemoteTargetResolver())->resolve(999999));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/RemoteTargetResolverTest.php -c tests -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `services/RemoteTargetResolver.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\RemoteActor;

/**
 * For a given Content id, resolves the original remote ActivityPub object id and
 * its RemoteActor — i.e. "this post came from a remote account". Returns null
 * for local-origin or unknown content.
 */
class RemoteTargetResolver
{
    /** @return array{noteApId:string,remote:RemoteActor}|null */
    public function resolve(int $contentId): ?array
    {
        $content = Content::findOne($contentId);
        if ($content === null) {
            return null;
        }
        $record = $content->getPolymorphicRelation();
        if ($record === null) {
            return null;
        }

        $obj = FederationObject::findOne([
            'model_class' => get_class($record),
            'model_id' => $record->id,
            'direction' => FederationObject::DIRECTION_REMOTE,
            'object_type' => 'Note',
        ]);
        if ($obj === null) {
            return null; // local-origin (or not a federated note)
        }

        $container = $content->getContainer();
        if ($container === null) {
            return null;
        }
        $remote = RemoteActor::findOne(['user_id' => $container->id]);
        if ($remote === null || $remote->inbox_url === '') {
            return null;
        }
        return ['noteApId' => $obj->ap_id, 'remote' => $remote];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/RemoteTargetResolverTest.php -c tests -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/RemoteTargetResolver.php tests/codeception/unit/RemoteTargetResolverTest.php
git commit -m "Add RemoteTargetResolver mapping content to its remote author"
```

---

## Task 2: Outbound `Like` / `Undo{Like}`

**Files:** Modify `Events.php`, `config.php`

- [ ] **Step 1: Add handlers to `Events.php`**

Add imports if missing: `use humhub\modules\fediverse\services\RemoteTargetResolver;`, `use humhub\modules\like\models\Like;` (and ensure `DeliverToInbox`, `FederationFollow`→not needed, `RemoteActor`, `FederationObject`, `DiscoveryPolicy`, `LocalActor`, `KeyManager`, `User`, `Yii` are imported — most are from Plans 4/6).

```php
    /** Local user liked a remote-origin post → send Like to the remote author. */
    public static function onLikeCreated($event): void
    {
        $like = $event->sender;
        if (!empty($like->content_addon_record_id)) {
            return; // likes on comments are out of scope this phase
        }
        $actor = self::federatableActor((int) $like->created_by);
        if ($actor === null) {
            return; // not a local federatable user (shadow user or non-discoverable)
        }
        $target = (new RemoteTargetResolver())->resolve((int) $like->content_id);
        if ($target === null) {
            return; // not a remote-origin post
        }

        $likeApId = $actor->ap_id . '/likes/' . bin2hex(random_bytes(8));
        self::recordObject($likeApId, 'Like', Like::class, (int) $like->id);

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $likeApId,
            'type' => 'Like',
            'actor' => $actor->ap_id,
            'object' => $target['noteApId'],
        ];
        Yii::$app->queue->push(new DeliverToInbox([
            'signerUserId' => (int) $like->created_by,
            'inboxUrl' => $target['remote']->inbox_url,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** Local user removed a like on a remote post → send Undo{Like}. */
    public static function onLikeRemoved($event): void
    {
        $like = $event->sender;
        $obj = FederationObject::findOne([
            'model_class' => Like::class,
            'model_id' => (int) $like->id,
            'direction' => FederationObject::DIRECTION_LOCAL,
            'object_type' => 'Like',
        ]);
        if ($obj === null) {
            return; // this like was never federated
        }
        $signer = LocalActor::findOne(['user_id' => (int) $like->created_by]);
        $target = (new RemoteTargetResolver())->resolve((int) $like->content_id);
        if ($signer !== null && $target !== null) {
            $undo = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $signer->ap_id . '/undos/' . bin2hex(random_bytes(8)),
                'type' => 'Undo',
                'actor' => $signer->ap_id,
                'object' => [
                    'id' => $obj->ap_id,
                    'type' => 'Like',
                    'actor' => $signer->ap_id,
                    'object' => $target['noteApId'],
                ],
            ];
            Yii::$app->queue->push(new DeliverToInbox([
                'signerUserId' => (int) $like->created_by,
                'inboxUrl' => $target['remote']->inbox_url,
                'activityJson' => json_encode($undo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]));
        }
        $obj->delete();
    }
```

Add ONLY this one private helper to `Events.php`:
```php
    /** A LocalActor for a user that may federate as an actor, or null (shadow user / not discoverable). */
    private static function federatableActor(int $userId): ?LocalActor
    {
        $user = User::findOne($userId);
        if ($user === null) {
            return null;
        }
        if (RemoteActor::findOne(['user_id' => $user->id]) !== null) {
            return null; // a federated shadow user — its actions are inbound, never re-federated (loop guard)
        }
        if (!DiscoveryPolicy::isDiscoverable($user)) {
            return null; // no fetchable actor → remote can't verify; skip
        }
        return (new KeyManager())->getOrCreateForUser($user);
    }
```
> **Do NOT add a `recordObject` method** — Plan 4 already defines `private static function recordObject(string $apId, string $type, string $modelClass, int $modelId): void` in `Events.php`; the new handlers reuse it. Adding a second copy is a fatal duplicate-method declaration. Verify there is exactly one `recordObject` in the file after your edits.

- [ ] **Step 2: Register the events in `config.php`**

Add to `events`:
```php
        ['class' => 'humhub\modules\like\models\Like', 'event' => 'afterInsert', 'callback' => ['humhub\modules\fediverse\Events', 'onLikeCreated']],
        ['class' => 'humhub\modules\like\models\Like', 'event' => 'beforeDelete', 'callback' => ['humhub\modules\fediverse\Events', 'onLikeRemoved']],
```

- [ ] **Step 3: Verify syntax**

Run: `php -l Events.php && php -l config.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add Events.php config.php
git commit -m "Send Like/Undo when a local user likes a remote post"
```

---

## Task 3: Outbound `Create{Note, inReplyTo}` for comments

**Files:** Modify `Events.php`, `config.php`

- [ ] **Step 1: Add the handler to `Events.php`**

Add import if missing: `use humhub\modules\comment\models\Comment;` (RichText is already imported from Plan 4: `use humhub\modules\content\widgets\richtext\RichText;`).

```php
    /** Local user commented on a remote-origin post → send Create{Note, inReplyTo}. */
    public static function onCommentCreated($event): void
    {
        $comment = $event->sender;
        $actor = self::federatableActor((int) $comment->created_by);
        if ($actor === null) {
            return; // shadow user (inbound comment — loop guard) or non-federatable
        }
        $target = (new RemoteTargetResolver())->resolve((int) $comment->content_id);
        if ($target === null) {
            return; // not a reply to a remote-origin post
        }

        $noteApId = $actor->ap_id . '/comments/' . (int) $comment->id;
        self::recordObject($noteApId, 'Note', Comment::class, (int) $comment->id);

        $note = [
            'id' => $noteApId,
            'type' => 'Note',
            'attributedTo' => $actor->ap_id,
            'content' => RichText::output((string) $comment->message),
            'inReplyTo' => $target['noteApId'],
            'to' => [$target['remote']->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $noteApId . '/activity',
            'type' => 'Create',
            'actor' => $actor->ap_id,
            'to' => [$target['remote']->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => $note,
        ];
        Yii::$app->queue->push(new DeliverToInbox([
            'signerUserId' => (int) $comment->created_by,
            'inboxUrl' => $target['remote']->inbox_url,
            'activityJson' => json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }
```

- [ ] **Step 2: Register the event in `config.php`**

Add to `events`:
```php
        ['class' => 'humhub\modules\comment\models\Comment', 'event' => 'afterInsert', 'callback' => ['humhub\modules\fediverse\Events', 'onCommentCreated']],
```

- [ ] **Step 3: Verify syntax**

Run: `php -l Events.php && php -l config.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add Events.php config.php
git commit -m "Send Create{Note,inReplyTo} when a local user comments on a remote post"
```

- [ ] **Step 5: Live end-to-end check (human + me)**

After deploy + worker restart:
1. As admin (federatable, following cmburns), open the dashboard where cmburns's ingested post ("Something Public") shows.
2. **Like** that post, then **comment** on it.
3. Expected on `@cmburns@mastodon.social`'s Mastodon: a favourite from `@admin@test.fedihub.social` appears on the original post, and the comment appears as a **reply** to it. Verify our side: `fediverse_object` rows (`direction=local`, `Like`→Like and `Note`→Comment) exist; no delivery warning.
4. **Unlike** in HumHub → the favourite is removed on Mastodon (`Undo{Like}`).

---

## Done criteria

- Unit test green: `RemoteTargetResolverTest`.
- Live: a local like on a remote-origin post shows as a favourite on the origin (and unlike removes it); a local comment shows as a reply on the origin. The loop guard holds — inbound shadow-user likes/comments do NOT get re-federated (no duplicate/echo).

## Known follow-ups (later)

- Interact with remote **comments** (not just posts); fan-out of a local comment on a *local* federated post to that post's followers; `Announce`/boost; `Update` of edited comments.
- The deferred items from earlier phases: shadow `firstname` length handling, HIDDEN→REGISTERED migration of old shadow users, People-directory filter for federated users, profile/avatar sync.

## Self-review risks to confirm live

- **Loop guard:** an inbound comment/like (Plan 5, by a shadow user) must NOT trigger an outbound activity — `federatableActor()` returns null for shadow users (they have a `RemoteActor`). Confirm no echo when cmburns replies again.
- **`afterInsert` fires** for programmatic likes/comments too — but the loop guard + remote-origin guard keep those safe. Confirm a NORMAL local like on a remote post does fire and deliver.
- **Single `recordObject`/helper copy** in `Events.php` — ensure no duplicate method declaration (fatal) if Plan 4 already defined one.
