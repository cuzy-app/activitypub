# Fediverse — Threading (b): like/reply to remote comments

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Let a local user like and reply to a *remote* comment (one ingested from a Fediverse account), sending a `Like`/`Undo{Like}` and a `Create{Note, inReplyTo: <comment>}` to that comment's author — the counterpart of Phase 7 (which only handled remote *posts*).

**Architecture:** Add `RemoteTargetResolver::resolveComment()` (Comment id → its remote Note ap_id + the commenter's `RemoteActor`). Route like targets through a shared `likeTarget()` helper (post via `content_id`, comment via `content_addon_record_id`). Add a `parent_comment_id` branch to `onCommentCreated` for replies to remote comments, and extract the existing Phase-7 reply body into a shared `sendReplyToRemote()` used by both remote-post and remote-comment replies.

**Tech Stack:** PHP 8, Yii2, HumHub like/comment AR events, `DeliverToInbox` (reused), Codeception. One-directional (HumHub → Fediverse). Live-tested against real Mastodon.

---

## Scope

- **In:** like/unlike a remote comment → `Like`/`Undo{Like}` to its author; reply to a remote comment → `Create{Note, inReplyTo: <comment>}` to its author.
- **Out (→ later):** likes/replies on *local* comments (deeper local threading); `Update`/`Delete` of a federated reply.

## Key guards (must hold)

- **Federatable actor first:** `federatableActor($created_by)` runs before any branch — null for shadow users (loop guard: inbound Phase-5 likes/comments authored by shadow users must NOT be re-federated) and non-discoverable users.
- **Remote target only:** `resolveComment()` returns null unless the comment has a `direction=remote`,`object_type=Note`,`Comment` mapping AND its author (`created_by`) maps to a `RemoteActor` with an inbox. Likes/replies on local comments → null → skipped.
- **Idempotency unchanged:** outbound `Like` records a `fediverse_object` (`Like`→Like#id, local) with a random ap_id; `onLikeRemoved` looks it up by Like#id (works for post- and comment-likes alike) and deletes it. Outbound reply records a `Note`→Comment#id mapping (one per comment; each comment takes exactly one path).

## Prerequisites

- Plans 1–7 + display-name sync + Threading (a) shipped on `develop`. `RemoteTargetResolver::resolve()`, `Events::onLikeCreated`/`onLikeRemoved`/`onCommentCreated`/`federateReplyToLocalPost`/`recordObject`/`federatableActor`, `DeliverToInbox`, `LocalActor`, `RemoteActor`, `FederationObject`, `Comment` all exist.
- Live env `https://test.fedihub.social`; admin is federatable; `@cmburns@mastodon.social` has ingested **comments** (e.g. `fediverse_object` rows `direction=remote`, `model_class=...comment...Comment`, `object_type=Note`) that admin can like/reply to. No worker restart / cache flush needed (no `config.php` change; opcache 2s; yii2-queue `isolate=true`).

---

## File structure (modified)

```
fediverse/
├── services/RemoteTargetResolver.php                   # MODIFY: add resolveComment()
├── tests/codeception/unit/RemoteTargetResolverTest.php # MODIFY: add null-case test
└── Events.php                                          # MODIFY: likeTarget(), onLikeCreated/onLikeRemoved, onCommentCreated + sendReplyToRemote()
```

---

## Task 1: `RemoteTargetResolver::resolveComment()` + test

**Files:** Modify `services/RemoteTargetResolver.php`, `tests/codeception/unit/RemoteTargetResolverTest.php`

- [ ] **Step 1: Add the failing test** to `tests/codeception/unit/RemoteTargetResolverTest.php` (a new method in the existing class):

```php
    public function testUnknownCommentResolvesNull()
    {
        $this->assertNull((new RemoteTargetResolver())->resolveComment(999999));
    }
```

- [ ] **Step 2: Run it** (best-effort; codeception runs via core/CI — `php -l` is the required bar): expect failure (`resolveComment` undefined) if runnable.

- [ ] **Step 3: Add `resolveComment()` to `services/RemoteTargetResolver.php`**

Add the import `use humhub\modules\comment\models\Comment;` (with the other `use` statements), then add this method to the class (after `resolve()`):

```php
    /**
     * Resolve a remote-origin COMMENT (by its local Comment id) to the original
     * remote Note ap_id and the commenter's RemoteActor. Null for local comments.
     *
     * @return array{noteApId:string,remote:RemoteActor}|null
     */
    public function resolveComment(int $commentId): ?array
    {
        $comment = Comment::findOne($commentId);
        if ($comment === null) {
            return null;
        }
        $obj = FederationObject::findOne([
            'model_class' => Comment::class,
            'model_id' => $comment->id,
            'direction' => FederationObject::DIRECTION_REMOTE,
            'object_type' => 'Note',
        ]);
        if ($obj === null) {
            return null; // not a remote-origin comment
        }
        $remote = RemoteActor::findOne(['user_id' => $comment->created_by]);
        if ($remote === null || empty($remote->inbox_url)) {
            return null;
        }
        return ['noteApId' => $obj->ap_id, 'remote' => $remote];
    }
```

- [ ] **Step 4: Verify** `php -l services/RemoteTargetResolver.php` and `php -l tests/codeception/unit/RemoteTargetResolverTest.php` → `No syntax errors detected`.

- [ ] **Step 5: STOP — do not commit.** Report `git diff --stat`.

---

## Task 2: like/unlike a remote comment

**Files:** Modify `Events.php`

- [ ] **Step 1: Add the `likeTarget()` helper** (private static, near the like handlers). It centralises the post-vs-comment choice AND the `Expression('NULL')` gotcha:

```php
    /** The remote Note a like targets — a remote post (content) or a remote comment (addon) — or null. */
    private static function likeTarget($like): ?array
    {
        $addonId = $like->content_addon_record_id;
        if ($addonId instanceof \yii\db\Expression) {
            $addonId = null; // freshly-saved post-like carries Expression('NULL'), not real null
        }
        $resolver = new RemoteTargetResolver();
        return empty($addonId)
            ? $resolver->resolve((int) $like->content_id)
            : $resolver->resolveComment((int) $addonId);
    }
```

- [ ] **Step 2: Replace `onLikeCreated`** with this version (it drops the early addon-skip + inline resolve and uses `likeTarget()`):

```php
    /** Local user liked a remote post or remote comment → send Like to its author. */
    public static function onLikeCreated($event): void
    {
        $like = $event->sender;
        $actor = self::federatableActor((int) $like->created_by);
        if ($actor === null) {
            return; // not a local federatable user (shadow user or non-discoverable)
        }
        $target = self::likeTarget($like);
        if ($target === null) {
            return; // not a like on a remote post or remote comment
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
```

- [ ] **Step 3: In `onLikeRemoved`**, replace the single line

```php
        $target = (new RemoteTargetResolver())->resolve((int) $like->content_id);
```

with

```php
        $target = self::likeTarget($like);
```

(Leave the rest of `onLikeRemoved` unchanged — the `FederationObject` lookup by Like#id, the `Undo` build, and `$obj->delete()`.)

- [ ] **Step 4: Verify** `php -l Events.php` → `No syntax errors detected`. Confirm exactly one `likeTarget` definition.

- [ ] **Step 5: STOP — do not commit.** Report `git diff Events.php`.

---

## Task 3: reply to a remote comment (+ extract `sendReplyToRemote`)

**Files:** Modify `Events.php`

- [ ] **Step 1: Extract the Phase-7 remote-reply body into a shared helper.** Add this private static method (near `onCommentCreated`):

```php
    /** Deliver $comment as a Create{Note, inReplyTo: $target Note} to the remote author in $target. */
    private static function sendReplyToRemote($comment, LocalActor $actor, array $target): void
    {
        $noteApId = $actor->ap_id . '/comments/' . (int) $comment->id;
        self::recordObject($noteApId, 'Note', Comment::class, (int) $comment->id);

        $published = date('c', strtotime((string) $comment->created_at));
        $note = [
            'id' => $noteApId,
            'type' => 'Note',
            'attributedTo' => $actor->ap_id,
            'content' => RichText::output((string) $comment->message),
            'inReplyTo' => $target['noteApId'],
            'published' => $published,
            'to' => [$target['remote']->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $noteApId . '/activity',
            'type' => 'Create',
            'actor' => $actor->ap_id,
            'published' => $published,
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

- [ ] **Step 2: Replace `onCommentCreated`** with this version (federatable check first; a `parent_comment_id` branch for replies to remote comments; the top-level path delegates the remote-post case to `sendReplyToRemote`):

```php
    /** Local comment → federate it: reply to a remote comment, reply to a remote post, or fan out on a local federated post. */
    public static function onCommentCreated($event): void
    {
        $comment = $event->sender;
        $actor = self::federatableActor((int) $comment->created_by);
        if ($actor === null) {
            return; // shadow user (inbound comment — loop guard) or non-federatable
        }

        if (!empty($comment->parent_comment_id)) {
            // Reply to a comment → federate only if the parent is a remote comment.
            $target = (new RemoteTargetResolver())->resolveComment((int) $comment->parent_comment_id);
            if ($target !== null) {
                self::sendReplyToRemote($comment, $actor, $target);
            }
            return; // replies to local comments are out of scope
        }

        $target = (new RemoteTargetResolver())->resolve((int) $comment->content_id);
        if ($target === null) {
            self::federateReplyToLocalPost($comment, $actor);
            return;
        }
        self::sendReplyToRemote($comment, $actor, $target);
    }
```

> Note: this REPLACES the previous `onCommentCreated` whose inline body (the `$noteApId`/`$note`/`$create`/`DeliverToInbox` block) now lives in `sendReplyToRemote`. Delete that old inline block — do not leave a duplicate. `federateReplyToLocalPost` (Threading a) is unchanged and still called for the top-level local-federated-post case.

- [ ] **Step 3: Verify** `php -l Events.php` → `No syntax errors detected`. Confirm: exactly one each of `onCommentCreated`, `sendReplyToRemote`, `federateReplyToLocalPost`, `likeTarget`; no leftover duplicate inline reply block in `onCommentCreated`; `recordObject`/`federatableActor`/`existingNoteId` not redefined.

- [ ] **Step 4: STOP — do not commit.** Report the full `git diff Events.php`.

---

## Deploy + live verification (controller + human)

1. (controller) No cache flush / worker restart needed (no `config.php` change; opcache 2s; queue `isolate=true`). Optionally `git diff` review first.
2. (human) As admin (federatable): find a **comment by `@cmburns`** under a post (an ingested remote comment), **like it**, and **reply to it**.
3. (human) On `@cmburns@mastodon.social`: a **favourite** appears on cmburns's comment, and admin's **reply** appears threaded under that comment. Then **unlike** → the favourite disappears.
4. (controller) Confirm a `fediverse_object` `Like`→Like row was created then deleted on unlike, a `Note`→Comment row exists for the reply, and no `DeliverToInbox` warning in the app log.

## Done criteria

- `php -l` clean on all files; `resolveComment` unit test added.
- Live: like + reply to a remote comment land on the comment's author (favourite + threaded reply), unlike removes the favourite. Likes/replies on local comments, and inbound shadow-user interactions, produce no outbound traffic.

## Self-review risks to confirm live

- **Phase-7 behavior preserved:** likes and replies on remote *posts* must still work exactly as before (now routed via `likeTarget`/`sendReplyToRemote`). Confirm a like + reply on a remote *post* still federates.
- **`inReplyTo` is the comment, not the post:** a reply to a remote comment must thread under that comment on Mastodon (resolved via `resolveComment(parent_comment_id)`), not under the post.
- **Undo targets the comment:** unliking a remote comment sends `Undo{Like}` whose object is the comment's Note (via `likeTarget` in `onLikeRemoved`), not the post's.
- **Loop guard + local skip:** inbound shadow-user likes/comments are skipped (federatable check first); likes/replies on local comments resolve to null and are skipped.
