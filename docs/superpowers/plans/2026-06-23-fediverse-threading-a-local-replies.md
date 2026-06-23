# Fediverse — Threading (a): local replies on local federated posts

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** When a local user writes a top-level comment on a *local, federated* post, deliver that comment as a signed `Create{Note, inReplyTo}` to the **post author's** remote followers, so the thread stays coherent on Mastodon.

**Architecture:** Extend the fan-out job `DeliverActivity` so the signer can differ from the follower-set owner (`signerUserId` vs `localUserId`). Add a second branch to the existing `Events::onCommentCreated`: if the commented post is not remote-origin (Phase 7) but IS a local federated post (`fediverse_object` `direction=local`, `Note`, `Post`), build the commenter's reply (attributed to the commenter, `inReplyTo` the post's Note) and fan it out to the post author's accepted inbound followers — signed by the commenter.

**Tech Stack:** PHP 8, Yii2, HumHub comment AR events, `DeliverActivity` (extended) + `existingNoteId`/`recordObject`/`federatableActor` (reused). Live-tested against real Mastodon. One-directional (HumHub → Fediverse).

---

## Scope

- **In:** a top-level local comment by a federatable user on a local post that was federated out → `Create{Note, inReplyTo}` to the **post author's** remote followers, signed by the commenter.
- **Out (→ later):** delivering the reply to the *commenter's own* followers too; replies to *comments* (nested) and likes/replies on remote *comments* (= Threading (b), a separate plan); `Update`/`Delete` of a federated comment.

## Key guards (must hold)

- **Top-level only:** skip when `parent_comment_id` is set (a reply to a comment — Threading (b)). (Already in `onCommentCreated`.)
- **Commenter federatable:** `federatableActor($comment->created_by)` — null for federated shadow users (loop guard: inbound Phase-5 replies are authored by shadow users and must NOT be re-federated) and for non-discoverable users. (Already checked.)
- **Post was federated:** only act when the post has a `direction=local` Note mapping (`existingNoteId(Post::class, postId) !== null`); otherwise no remote follower ever saw it.
- **Mutually exclusive with Phase 7:** remote-origin posts (`RemoteTargetResolver::resolve` non-null) keep the Phase-7 path; the new branch runs only when that returns null.

## Prerequisites

- Plans 1–7 + display-name sync shipped on `develop`. `DeliverActivity` (fan-out to `FederationFollow` `direction=inbound`,`state=accepted` of `localUserId`), `Events::onCommentCreated`, `Events::existingNoteId()`, `Events::recordObject()`, `Events::federatableActor()`, `LocalActor`, `Content`, `Post`, `Comment` all exist.
- Live env `https://test.fedihub.social`; admin is federatable, has a federated post, and has an accepted inbound follower (`@cmburns@mastodon.social`). After changes, flush the HumHub cache (`php yii cache/flush-all` on the fedihub console).

---

## File structure (modified)

```
fediverse/
├── jobs/DeliverActivity.php   # MODIFY: optional signerUserId (sign-as override)
└── Events.php                 # MODIFY: onCommentCreated → local-federated-post branch + helper
```

(No `config.php` change — `Comment afterInsert → onCommentCreated` is already registered. No new unit test: both changes are job/event wiring that need a DB + network, verified by `php -l` + the live test, consistent with prior event/job tasks in this module.)

---

## Task 1: `DeliverActivity` — decouple signer from follower set

**Files:** Modify `jobs/DeliverActivity.php`

- [ ] **Step 1: Add the `signerUserId` property + use it for signing**

In `jobs/DeliverActivity.php`, add the property next to the existing ones:

```php
    public int $localUserId = 0;
    public int $signerUserId = 0;
    public string $activityJson = '';
```

Then in `run()`, change the signer lookup from:

```php
        $signer = LocalActor::findOne(['user_id' => $this->localUserId]);
```

to:

```php
        // The signer may differ from the follower-set owner (e.g. user B replies on
        // user A's post → sign as B, but fan out to A's followers).
        $signerUserId = $this->signerUserId ?: $this->localUserId;
        $signer = LocalActor::findOne(['user_id' => $signerUserId]);
```

Leave the follower query (`FederationFollow ... 'local_user_id' => $this->localUserId ...`) unchanged. This is backward compatible: existing callers don't set `signerUserId`, so it defaults to `localUserId`.

- [ ] **Step 2: Verify syntax**

Run: `cd /home/luke/work/humhub/modules/develop/fediverse && php -l jobs/DeliverActivity.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: STOP — do not commit**

Report the diff (`git diff --stat jobs/DeliverActivity.php`). The controller reviews and commits later on the human's word.

---

## Task 2: `onCommentCreated` — fan replies on local federated posts to the post author's followers

**Files:** Modify `Events.php`

- [ ] **Step 1: Route the "not remote-origin" case to a new branch**

In `Events::onCommentCreated`, find:

```php
        $target = (new RemoteTargetResolver())->resolve((int) $comment->content_id);
        if ($target === null) {
            return; // not a reply to a remote-origin post
        }
```

and change the early `return;` to call the new helper:

```php
        $target = (new RemoteTargetResolver())->resolve((int) $comment->content_id);
        if ($target === null) {
            self::federateReplyToLocalPost($comment, $actor);
            return;
        }
```

(Leave the rest of `onCommentCreated` — the Phase-7 block that delivers to the remote author — exactly as is.)

- [ ] **Step 2: Add the `federateReplyToLocalPost` helper**

Add this private static method to `Events.php` (near `onCommentCreated`). All referenced classes (`Content`, `Post`, `Comment`, `LocalActor`, `RichText`, `DeliverActivity`) and helpers (`existingNoteId`, `recordObject`) already exist/are imported.

```php
    /** A top-level comment on a LOCAL federated post → fan the reply out to the post author's remote followers. */
    private static function federateReplyToLocalPost($comment, LocalActor $actor): void
    {
        $content = Content::findOne((int) $comment->content_id);
        if ($content === null) {
            return;
        }
        $record = $content->getPolymorphicRelation();
        if (!$record instanceof Post) {
            return;
        }
        $postNoteId = self::existingNoteId(Post::class, (int) $record->id);
        if ($postNoteId === null) {
            return; // the post was never federated → no remote followers saw it
        }
        $postAuthor = $content->createdBy;
        if ($postAuthor === null) {
            return;
        }
        $authorActor = LocalActor::findOne(['user_id' => $postAuthor->id]);
        if ($authorActor === null) {
            return;
        }

        $noteApId = $actor->ap_id . '/comments/' . (int) $comment->id;
        self::recordObject($noteApId, 'Note', Comment::class, (int) $comment->id);

        $published = date('c', strtotime((string) $comment->created_at));
        $note = [
            'id' => $noteApId,
            'type' => 'Note',
            'attributedTo' => $actor->ap_id,
            'content' => RichText::output((string) $comment->message),
            'inReplyTo' => $postNoteId,
            'published' => $published,
            'to' => [$authorActor->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $noteApId . '/activity',
            'type' => 'Create',
            'actor' => $actor->ap_id,
            'published' => $published,
            'to' => [$authorActor->ap_id],
            'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => $note,
        ];
        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => (int) $postAuthor->id,        // deliver to the post author's followers
            'signerUserId' => (int) $comment->created_by,  // signed by the commenter
            'activityJson' => json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }
```

- [ ] **Step 3: Verify syntax**

Run: `cd /home/luke/work/humhub/modules/develop/fediverse && php -l Events.php`
Expected: `No syntax errors detected`. Confirm exactly one `federateReplyToLocalPost` definition and that `existingNoteId`/`recordObject`/`federatableActor` are NOT redefined.

- [ ] **Step 4: STOP — do not commit**

Report the diff (`git diff --stat Events.php` and the full `git diff Events.php`). The controller reviews and commits later on the human's word.

---

## Deploy + live verification (controller + human)

1. (controller) Flush cache: `php yii cache/flush-all` on the fedihub console (opcache picks up edited files in ~2s; classic `php_server`, no restart needed; `DeliverActivity` is a job — the queue worker runs current code per job).
2. (human) As admin (federatable, with follower `@cmburns`): make a **new public profile post** (it federates to cmburns), then write a **comment** on that post.
3. (human) On `@cmburns@mastodon.social`: the comment appears as a **reply** threaded under admin's post.
4. (controller) Confirm no `DeliverActivity` delivery warning in the app log; a `fediverse_object` `direction=local`, `Note`→Comment row exists for the comment.

## Done criteria

- `php -l` clean on both files.
- Live: a local comment on a local federated post shows as a threaded reply on a follower's Mastodon; a comment on a non-federated local post, a reply-to-comment, or a shadow-user (inbound) comment produces no outbound traffic.

## Self-review risks to confirm live

- **Signer/actor match:** the reply's `actor`/`attributedTo` is the commenter and it is signed by the commenter (`signerUserId`), so `keyId` matches `actor` — confirm Mastodon accepts it (no signature rejection).
- **Loop guard:** an inbound remote reply (Phase 5, shadow-user comment on the local post) must NOT be re-federated — `federatableActor()` returns null first. Confirm no echo.
- **Threading:** `inReplyTo` = the post's `direction=local` Note ap_id (`existingNoteId(Post::class, postId)`) — confirm the reply threads under the original post on the follower's side, not as a standalone toot.
- **No double-send:** a comment takes exactly one branch (remote-origin XOR local-federated); confirm `recordObject` writes a single mapping per comment.
