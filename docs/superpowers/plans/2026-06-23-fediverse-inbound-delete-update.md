# Fediverse — Inbound Delete & Update of remote content

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** When a remote account deletes or edits a post/comment we ingested, reflect it locally — soft-delete the record on `Delete`, update its text on `Update{Note}`.

**Architecture:** Add `Delete` and `Update` cases to `ProcessInboxActivity::run()`. Resolve the activity's object id to the ingested local record via `FederationObject` (`direction=remote`). Authorize: the (already signature-verified) sending actor must be the record's author. `Delete` → soft-delete + drop the mapping; `Update{Note}` → set the new content. `Update{Person}` and actor deletes are out of scope.

**Tech Stack:** PHP 8, Yii2, HumHub Post/Comment + the existing inbox auth pipeline. Live-tested against real Mastodon.

---

## Scope

- **In:** inbound `Delete` of an ingested remote `Post`/`Comment` (Note id or Tombstone) → soft-delete + remove the `fediverse_object` mapping; inbound `Update{Note}` of an ingested remote `Post`/`Comment` → update `message`.
- **Out (→ follow-up):** `Update{Person}` (refresh the remote shadow user's name/avatar/bio); remote account/actor deletion; updating attachments.

## Key guards (must hold)

- **Author-only:** the activity is already HTTP-signature-verified (`authenticate` → `$remote`). Additionally, the target record's author must equal `$remote->user_id` (Post → `content->created_by`, Comment → `created_by`). Otherwise reject + log — prevents actor A deleting/editing actor B's content.
- **Known content only:** act only when the object id resolves to a `direction=remote` `FederationObject`. Unknown ids (Mastodon broadcasts Deletes widely) are ignored.
- **No outbound echo:** soft-deleting a remote-ingested Post fires `onContentDeleted`, but that only acts on `direction=local` mappings (`existingNoteId`) — the remote mapping is skipped, so no spurious outbound `Delete{Note}`. Editing fires no `STATE_CHANGED`/`afterInsert`, so no re-federation.

## Prerequisites

- Inbound ingest shipped: remote posts → `Post` and remote replies → `Comment`, each with a `FederationObject` (`direction=remote`, `object_type=Note`). `ProcessInboxActivity::run()` switch + `authenticate()` + `objectId()` exist.
- Live env `https://test.fedihub.social`; admin follows `@cmburns`, who has ingested posts/comments to delete/edit. No `config.php`/cache change; queue `isolate=true` picks up the worker code.

---

## File structure (modified)

```
fediverse/
└── jobs/ProcessInboxActivity.php   # MODIFY: Delete/Update dispatch + handlers + 2 helpers
```

---

## Task 1: handle inbound `Delete` and `Update{Note}`

**Files:** Modify `jobs/ProcessInboxActivity.php`

- [ ] **Step 1: Add imports** (the file currently references these via FQN inline; add `use` statements with the existing imports):

```php
use humhub\modules\comment\models\Comment;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\post\models\Post;
```

- [ ] **Step 2: Add `Delete` + `Update` cases** to the `switch ($activity['type'])` in `run()` (next to the existing cases):

```php
            case 'Delete':
                $this->handleDelete($activity, $remote);
                break;
            case 'Update':
                $this->handleUpdate($activity, $remote);
                break;
```

- [ ] **Step 3: Add the handlers + helpers** (place them near `handleCreate`):

```php
    /** Inbound Delete{Note|Tombstone} → soft-delete the ingested remote post/comment (author only). */
    private function handleDelete(array $activity, RemoteActor $remote): void
    {
        $apId = $this->objectId($activity['object'] ?? null);
        $record = $this->resolveRemoteRecord($apId);
        if ($record === null) {
            return; // not something we ingested
        }
        if (!$this->isAuthoredBy($record, $remote)) {
            Yii::warning('Fediverse Delete: actor is not the author of the target ' . $apId, 'fediverse');
            return;
        }
        try {
            $record->delete();
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Delete failed for ' . $apId . ': ' . $e->getMessage(), 'fediverse');
            return;
        }
        FederationObject::deleteAll(['ap_id' => $apId, 'direction' => FederationObject::DIRECTION_REMOTE]);
    }

    /** Inbound Update{Note} → update the ingested remote post/comment content (author only). */
    private function handleUpdate(array $activity, RemoteActor $remote): void
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object) || ($object['type'] ?? '') !== 'Note') {
            return; // Update{Person} and other types are out of scope here
        }
        $record = $this->resolveRemoteRecord((string) ($object['id'] ?? ''));
        if ($record === null) {
            return;
        }
        if (!$this->isAuthoredBy($record, $remote)) {
            Yii::warning('Fediverse Update: actor is not the author of the target', 'fediverse');
            return;
        }
        $record->message = trim(strip_tags((string) ($object['content'] ?? '')));
        try {
            if (!$record->save()) {
                Yii::warning('Fediverse Update save failed: ' . implode(', ', $record->getErrorSummary(true)), 'fediverse');
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Update error: ' . $e->getMessage(), 'fediverse');
        }
    }

    /** Resolve a remote-origin AP object id to the ingested local record (Post or Comment), or null. */
    private function resolveRemoteRecord(string $apId)
    {
        if ($apId === '') {
            return null;
        }
        $obj = FederationObject::findOne(['ap_id' => $apId, 'direction' => FederationObject::DIRECTION_REMOTE]);
        if ($obj === null || $obj->model_class === null || $obj->model_id === null) {
            return null;
        }
        if (!class_exists($obj->model_class) || !method_exists($obj->model_class, 'findOne')) {
            return null;
        }
        return $obj->model_class::findOne($obj->model_id);
    }

    /** True if the verified remote actor is the author of the ingested record. */
    private function isAuthoredBy($record, RemoteActor $remote): bool
    {
        if ($record instanceof Post) {
            return (int) ($record->content->created_by ?? 0) === (int) $remote->user_id;
        }
        if ($record instanceof Comment) {
            return (int) $record->created_by === (int) $remote->user_id;
        }
        return false;
    }
```

(`message` exists on both `Post` and `Comment`; `objectId()` already handles a string object, a `{id}` object, or a Tombstone `{id, type:Tombstone}`.)

- [ ] **Step 4: Verify** `php -l jobs/ProcessInboxActivity.php` → `No syntax errors detected`. Confirm exactly one each of `handleDelete`, `handleUpdate`, `resolveRemoteRecord`, `isAuthoredBy`, and that the `Delete`/`Update` cases are in the `run()` switch.

- [ ] **Step 5: STOP — do not commit.** Report `git diff jobs/ProcessInboxActivity.php`.

---

## Deploy + live verification (controller + human)

1. (controller) No cache flush / restart needed (queue `isolate=true` → fresh worker code per job).
2. **Cmburns** (Mastodon, `@cmburns@mastodon.social`): **delete** one post that admin ingested, and **edit** another; likewise **delete** one comment and **edit** another (the comments must be ones admin ingested as replies).
3. **Admin** (HumHub, https://test.fedihub.social): the deleted post/comment are gone; the edited ones show the new text.
4. (controller) Confirm: the `fediverse_object` rows for the deleted items are gone, the deleted records are soft-deleted, the edited records' `message` updated, and no "not the author" warning for legitimate self-deletes.

## Done criteria

- `php -l` clean.
- Live: a remote self-delete removes the local post/comment; a remote edit updates the local text. Deletes/updates for content we don't host, or from a non-author actor, are ignored (logged). No spurious outbound activity.

## Self-review risks to confirm live

- **Author authorization:** a legitimate self-delete (cmburns deleting cmburns's own post) must pass (`content->created_by`/`created_by` == `$remote->user_id`); confirm it's not wrongly rejected. (A cross-actor delete should be rejected — hard to exercise live, but verify the guard reads the right field.)
- **No outbound echo:** deleting a remote-ingested post must not emit an outbound `Delete{Note}` (it has only a `direction=remote` mapping). Confirm no delivery to followers / no warning loop.
- **Mapping cleanup:** after a Delete, the `direction=remote` `fediverse_object` row is gone, so a later re-`Create` of the same id can re-ingest.
- **Tombstone/string object:** `Delete.object` arrives as a Tombstone object or a bare string id — confirm `objectId()` extracts the id in both shapes.
