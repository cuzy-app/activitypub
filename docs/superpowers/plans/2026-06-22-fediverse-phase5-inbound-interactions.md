# Fediverse Phase 5 — Inbound Interactions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let remote Fediverse users *interact* with local content — an inbound `Create{Note, inReplyTo}` becomes a native HumHub **comment** authored by the remote shadow user, and an inbound `Like` / `Undo{Like}` becomes (removes) a native HumHub **like**. Completes the "HumHub as a federation destination" story.

**Architecture:** Extends the Plan-3 `ProcessInboxActivity` dispatcher (which already verifies signatures and resolves the remote shadow user) with `Create`, `Like`, and `Undo{Like}` handlers. A `LocalObjectResolver` maps an ActivityPub object id back to its local `Content` via the `fediverse_object` table (populated by Plan 4 when we federated the post). An `InboundInteractionService` creates the comment/like as the shadow user (idempotently) and records the inbound object for dedupe + later undo.

**Tech Stack:** PHP 8, Yii2, HumHub comment/like models + `LikeService`, the Plan-3 inbox/queue, Codeception. Live-tested: `@cmburns@mastodon.social` replies to / likes `@admin@test.fedihub.social`'s post.

---

## Roadmap position (plan 5 of 6)

1. Foundation ✅ 2. Identity & Discovery ✅ 3. Inbound Follow/Accept ✅ 4. Outbound posts ✅ (post reached real Mastodon)
5. **Inbound interactions (this plan)** — remote replies/likes → local comments/likes
6. Outbound interactions & local→remote follow — local likes/comments out, HumHub user follows a Mastodon account

Spec: `docs/superpowers/specs/2026-06-22-fediverse-module-design.md` (§6 comments/likes, §10 flow B).

## Scope

- **In:** inbound `Create{Note, inReplyTo}` → HumHub comment; inbound `Like` → HumHub like; inbound `Undo{Like}` → unlike. All authored by the remote shadow user, idempotent, attached to the local `Content` the AP object id resolves to.
- **Out (→ Plan 6):** outbound likes/comments (local → remote), local→remote follow, inbound `Delete` of a remote comment, threaded replies-to-comments, rich HTML→markdown conversion (we store the reply as plain text for now).

## Prerequisites

- Plans 1–4 shipped on `develop`; outbound posts verified live. `ProcessInboxActivity` (signature verify + shadow-user resolution), `RemoteActor`, `FederationObject`, `FederationFollow` exist. `fediverse_object` holds a `direction=local`, `object_type=Note`, `model_class=Post` row for each federated post (Plan 4).
- Live env `https://test.fedihub.social` (queue worker `fedihub.service`; restart after changing job/service classes). cmburns follows admin (Plan 3).

---

## File structure (created/modified by this plan)

```
fediverse/
├── services/
│   ├── LocalObjectResolver.php         # NEW: AP object id → local Content
│   └── InboundInteractionService.php   # NEW: create comment/like as shadow user (idempotent)
├── jobs/
│   └── ProcessInboxActivity.php        # MODIFY: dispatch Create / Like / Undo{Like}
└── tests/codeception/unit/
    └── LocalObjectResolverTest.php
```

---

## Task 1: `LocalObjectResolver` — AP id → local Content

**Files:** Create `services/LocalObjectResolver.php`; Test `tests/codeception/unit/LocalObjectResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\services\LocalObjectResolver;
use tests\codeception\_support\HumHubDbTestCase;

class LocalObjectResolverTest extends HumHubDbTestCase
{
    public function testUnknownApIdResolvesNull()
    {
        $this->assertNull((new LocalObjectResolver())->resolveContent('https://x/notes/does-not-exist'));
    }

    public function testNonLocalObjectResolvesNull()
    {
        $obj = new FederationObject();
        $obj->ap_id = 'https://remote/notes/1';
        $obj->direction = FederationObject::DIRECTION_REMOTE;
        $obj->object_type = 'Note';
        $obj->save();
        $this->assertNull((new LocalObjectResolver())->resolveContent('https://remote/notes/1'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/LocalObjectResolverTest.php -c tests -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `services/LocalObjectResolver.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;

/**
 * Resolves an ActivityPub object id we previously published (a local Note)
 * back to its HumHub Content, via the fediverse_object mapping.
 */
class LocalObjectResolver
{
    public function resolveContent(string $apId): ?Content
    {
        if ($apId === '') {
            return null;
        }
        $obj = FederationObject::findOne([
            'ap_id' => $apId,
            'direction' => FederationObject::DIRECTION_LOCAL,
        ]);
        if ($obj === null || $obj->model_class === null || $obj->model_id === null) {
            return null;
        }
        if (!class_exists($obj->model_class) || !method_exists($obj->model_class, 'findOne')) {
            return null;
        }
        $record = $obj->model_class::findOne($obj->model_id);
        // The mapped records are ContentActiveRecords (e.g. Post) exposing ->content.
        return ($record !== null && isset($record->content)) ? $record->content : null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/LocalObjectResolverTest.php -c tests -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/LocalObjectResolver.php tests/codeception/unit/LocalObjectResolverTest.php
git commit -m "Add LocalObjectResolver mapping AP ids to local Content"
```

---

## Task 2: `InboundInteractionService` — create comment/like as the shadow user

**Files:** Create `services/InboundInteractionService.php`

(No standalone unit test — it writes Comment/Like rows that need content + user fixtures; exercised by the live check. `php -l` for syntax.)

- [ ] **Step 1: Write `services/InboundInteractionService.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\comment\models\Comment;
use humhub\modules\content\models\Content;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\like\models\Like;
use humhub\modules\user\models\User;
use Yii;

/**
 * Creates native HumHub comments/likes on behalf of a remote (shadow) user in
 * response to inbound activities. Idempotent: a given remote object id is only
 * applied once. `created_by` is set explicitly — HumHub's beforeSave only
 * auto-fills it when empty, so the shadow author sticks in the queue context.
 */
class InboundInteractionService
{
    /** Add a remote reply as a comment on the given content (idempotent by Note id). */
    public function addComment(Content $content, User $author, string $contentHtml, string $noteApId): void
    {
        if ($noteApId !== '' && FederationObject::find()->where(['ap_id' => $noteApId])->exists()) {
            return; // already processed
        }

        $comment = new Comment(['message' => $this->toPlainText($contentHtml)]);
        $comment->content_id = $content->id;
        $comment->created_by = $author->id;
        if (!$comment->save()) {
            Yii::warning('Fediverse inbound comment save failed: ' . implode(', ', $comment->getErrorSummary(true)), 'fediverse');
            return;
        }
        if ($noteApId !== '') {
            $this->record($noteApId, 'Note', Comment::class, $comment->id);
        }
    }

    /** Add a like by the remote user on the given content (idempotent). */
    public function addLike(Content $content, User $author, string $likeApId): void
    {
        if ($likeApId !== '' && FederationObject::find()->where(['ap_id' => $likeApId])->exists()) {
            return;
        }
        $exists = Like::find()
            ->where(['content_id' => $content->id, 'created_by' => $author->id, 'content_addon_record_id' => null])
            ->exists();
        if ($exists) {
            return;
        }

        $like = new Like();
        $like->content_id = $content->id;
        $like->content_addon_record_id = null; // liking the content itself, not an addon
        $like->created_by = $author->id;
        if (!$like->save()) {
            Yii::warning('Fediverse inbound like save failed: ' . implode(', ', $like->getErrorSummary(true)), 'fediverse');
            return;
        }
        if ($likeApId !== '') {
            $this->record($likeApId, 'Like', Like::class, $like->id);
        }
    }

    /** Remove the remote user's like from the given content. */
    public function removeLike(Content $content, User $author): void
    {
        $like = Like::find()
            ->where(['content_id' => $content->id, 'created_by' => $author->id, 'content_addon_record_id' => null])
            ->one();
        if ($like !== null) {
            $like->delete();
        }
    }

    private function toPlainText(string $html): string
    {
        // Mastodon Note content is HTML; store a readable plain-text comment for now.
        $text = preg_replace('#</p>\s*<p>#i', "\n\n", $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", (string) $text);
        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function record(string $apId, string $type, string $modelClass, int $modelId): void
    {
        if (FederationObject::find()->where(['ap_id' => $apId])->exists()) {
            return;
        }
        $obj = new FederationObject();
        $obj->ap_id = $apId;
        $obj->direction = FederationObject::DIRECTION_REMOTE;
        $obj->object_type = $type;
        $obj->model_class = $modelClass;
        $obj->model_id = $modelId;
        $obj->save();
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l services/InboundInteractionService.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add services/InboundInteractionService.php
git commit -m "Add InboundInteractionService creating shadow-user comments/likes"
```

---

## Task 3: Dispatch `Create` / `Like` / `Undo{Like}` in the inbox job

**Files:** Modify `jobs/ProcessInboxActivity.php`

- [ ] **Step 1: Extend the dispatch `switch` in `run()`**

In `ProcessInboxActivity::run()`, the `switch ($activity['type'])` currently handles `Follow`, `Undo`, and `default`. Add two cases before `default`:
```php
            case 'Create':
                $this->handleCreate($activity, $remote);
                break;
            case 'Like':
                $this->handleLike($activity, $remote);
                break;
```

- [ ] **Step 2: Add the handler methods**

Add these private methods to `ProcessInboxActivity` (next to `handleFollow`/`handleUndo`):
```php
    /** Inbound Create{Note, inReplyTo} → comment on the replied-to local content. */
    private function handleCreate(array $activity, RemoteActor $remote): void
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object) || ($object['type'] ?? '') !== 'Note') {
            return;
        }
        $inReplyTo = $object['inReplyTo'] ?? null;
        if (!is_string($inReplyTo) || $inReplyTo === '') {
            return; // top-level remote posts are not ingested in this phase, only replies to us
        }
        $content = (new LocalObjectResolver())->resolveContent($inReplyTo);
        if ($content === null) {
            return; // a reply to something we don't host
        }
        $author = User::findOne($remote->user_id);
        if ($author === null) {
            return;
        }
        (new InboundInteractionService())->addComment(
            $content,
            $author,
            (string) ($object['content'] ?? ''),
            (string) ($object['id'] ?? '')
        );
    }

    /** Inbound Like → like the referenced local content. */
    private function handleLike(array $activity, RemoteActor $remote): void
    {
        $content = (new LocalObjectResolver())->resolveContent($this->objectId($activity['object'] ?? null));
        $author = User::findOne($remote->user_id);
        if ($content !== null && $author !== null) {
            (new InboundInteractionService())->addLike($content, $author, (string) ($activity['id'] ?? ''));
        }
    }

    /** Extract an object id from an AP value that may be a string or an embedded object. */
    private function objectId($value): string
    {
        if (is_array($value)) {
            return (string) ($value['id'] ?? '');
        }
        return (string) $value;
    }
```

- [ ] **Step 3: Extend `handleUndo` to also cover `Undo{Like}`**

Replace the body of `handleUndo` with a type switch that keeps the existing Follow logic and adds Like:
```php
    private function handleUndo(array $activity, RemoteActor $remote): void
    {
        $inner = $activity['object'] ?? null;
        if (!is_array($inner)) {
            return;
        }
        $type = $inner['type'] ?? '';

        if ($type === 'Follow') {
            $localUser = $this->resolveFollowTarget($inner['object'] ?? null);
            if ($localUser === null) {
                return;
            }
            FederationFollow::deleteAll([
                'local_user_id' => $localUser->id,
                'remote_actor_id' => $remote->id,
                'direction' => FederationFollow::DIRECTION_INBOUND,
            ]);
            return;
        }

        if ($type === 'Like') {
            $content = (new LocalObjectResolver())->resolveContent($this->objectId($inner['object'] ?? null));
            $author = User::findOne($remote->user_id);
            if ($content !== null && $author !== null) {
                (new InboundInteractionService())->removeLike($content, $author);
            }
        }
    }
```

- [ ] **Step 4: Add the imports**

Ensure these `use` statements are present at the top of `ProcessInboxActivity.php`:
```php
use humhub\modules\fediverse\services\InboundInteractionService;
use humhub\modules\fediverse\services\LocalObjectResolver;
```

- [ ] **Step 5: Verify syntax**

Run: `php -l jobs/ProcessInboxActivity.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add jobs/ProcessInboxActivity.php
git commit -m "Dispatch inbound Create/Like/Undo-Like as comments and likes"
```

- [ ] **Step 7: Live end-to-end check (human + me)**

After deploy + worker restart (`systemctl --user restart fedihub.service`):
1. As `@admin` (opted-in, guest-visible), create a **public** profile post (so it federates and gets a `fediverse_object` Note row).
2. From `@cmburns@mastodon.social` (following admin), **reply** to that post and **favourite (like)** it.
3. Expected within seconds: the reply appears as a **comment** on the post in HumHub, authored by the federated user `cmburns@mastodon.social`; the like shows on the post. Verify a `Comment` row (`created_by` = the shadow user) and a `Like` row exist, plus `fediverse_object` rows (`direction=remote`) for the reply Note and the Like.
4. **Un-favourite** in Mastodon → the like is removed in HumHub.

---

## Done criteria

- Unit test green: `LocalObjectResolverTest`.
- Live: a Mastodon reply to a federated post becomes a HumHub comment by the shadow user; a Mastodon like becomes a HumHub like; un-favourite removes it. Redelivery does not create duplicates (idempotent).

## Known follow-ups (→ Plan 6 / later)

- Outbound likes/comments (local → remote), local→remote follow & ingesting remote posts into the dashboard.
- Inbound `Delete` of a remote comment → hard-delete the local comment (and `Update`).
- Threaded replies (reply to a comment, via comment Notes); HTML→markdown for richer comment formatting; mentions.
- Re-distributing an inbound reply to the post author's other followers (relay-style) — currently we only record it locally.

## Self-review risks to confirm live

- **`created_by` sticks** for the shadow author in the queue (no logged-in user) — HumHub's `beforeSave` only fills `created_by` when empty; confirm the comment/like show the federated user, not a blank/admin author.
- **`Comment` attaches via `content_id` alone** (the verified core pattern) — confirm the comment renders on the right post.
- **`inReplyTo` resolution** depends on the post still having its `fediverse_object` Note row (Plan 4). A reply to a post created before Plan 4, or to a deleted post, resolves to null and is dropped — expected.
