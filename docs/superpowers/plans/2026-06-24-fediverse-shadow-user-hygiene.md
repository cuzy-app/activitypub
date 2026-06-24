# Fediverse — shadow-user hygiene (prune backlog + stop creating orphans)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Problem:** ~185 federated shadow users exist; only ~3–4 have any real connection (a follow, or a stored like/comment/post). The other ~180 are orphans created as a side effect of inbound activities. Root cause: `ProcessInboxActivity::authenticate()` resolves+persists the signer of **every** verified inbound activity *before* dispatch — so boosts (`Announce`), likes/replies that don't attach, top-level posts from non-followed accounts, and Undo/Delete/Update from unknown actors all mint a shadow user even though nothing is stored.

**Two parts:**
1. **Prune command** — delete shadow users with no follow relationship and no authored like/comment/content (safe: nothing to lose). Cleans the backlog.
2. **Root-cause fix** — never persist a shadow user during authentication. Verify the signature (we already fetch the signer doc for the key), then create the actor only at the point a handler actually stores something; for non-storing handlers, look the actor up and no-op if unknown.

---

## Task 1: prune command

**Modify** `commands/FediverseController.php` — add imports `humhub\modules\fediverse\models\FederationFollow`, `humhub\modules\like\models\Like`, `humhub\modules\comment\models\Comment`, `humhub\modules\content\models\Content`; add:

```php
    /**
     * Usage: php protected/yii fediverse/prune-remote-actors [apply]
     * Deletes federated shadow users with NO follow relationship and NO authored
     * like/comment/content (safe orphans). Dry-run unless `apply` = 1.
     */
    public function actionPruneRemoteActors($apply = 0): int
    {
        $apply = ((int) $apply) === 1;
        $prunable = [];
        foreach (RemoteActor::find()->each() as $ra) {
            if ($this->isPrunableActor($ra)) {
                $prunable[] = $ra;
            }
        }
        $this->stdout(count($prunable) . ' prunable shadow users (no follow, no like/comment/post)'
            . ($apply ? "\n" : " [DRY-RUN]\n"));
        if (!$apply) {
            foreach (array_slice($prunable, 0, 20) as $ra) {
                $this->stdout('  - ' . $ra->handle . "\n");
            }
            if (count($prunable) > 20) {
                $this->stdout('  ... and ' . (count($prunable) - 20) . " more\n");
            }
            $this->stdout("Run `php protected/yii fediverse/prune-remote-actors 1` to delete.\n");
            return ExitCode::OK;
        }
        $deleted = 0;
        foreach ($prunable as $ra) {
            $user = User::findOne($ra->user_id);
            if ($user !== null) {
                if ($user->delete()) {
                    $deleted++;
                }
            } else {
                $ra->delete();
            }
        }
        $this->stdout("Deleted {$deleted} shadow users.\n");
        return ExitCode::OK;
    }

    /** A remote actor is safe to prune if it has no follow link and authored no like/comment/content. */
    private function isPrunableActor(RemoteActor $ra): bool
    {
        if (FederationFollow::find()->where(['remote_actor_id' => $ra->id])->exists()) {
            return false;
        }
        $uid = (int) $ra->user_id;
        if (Like::find()->where(['created_by' => $uid])->exists()) {
            return false;
        }
        if (Comment::find()->where(['created_by' => $uid])->exists()) {
            return false;
        }
        if (Content::find()->where(['created_by' => $uid])->exists()) {
            return false;
        }
        return true;
    }
```

**Verify:** `php -l`, then `php yii fediverse/prune-remote-actors` (dry-run) shows ~180. (Human runs `... 1` to actually delete.)

---

## Task 2: stop creating orphans (ProcessInboxActivity refactor)

**Modify** `jobs/ProcessInboxActivity.php`. Verification logic is unchanged — only *when* the actor is persisted changes.

- [ ] **`authenticate()`**: change return type to `?array` and return the verified signer document instead of persisting. Replace the final `return $resolver->resolveFromDocument($signerId, $signerDoc);` with `return $signerDoc;`. (Keep the `$resolver = new RemoteActorResolver();` line — still used for `fetchActorDocument`.) Update the docblock.

- [ ] **`run()` dispatch**: resolve the actor per type — create only for storing types, look up for the rest:
```php
        $signerDoc = $this->authenticate($actorUrl);
        if ($signerDoc === null) {
            Yii::warning('Fediverse inbox: authentication failed for ' . $actorUrl, 'fediverse');
            return;
        }
        $signerId = (string) ($signerDoc['id'] ?? $actorUrl);

        switch ($activity['type']) {
            case 'Follow':
                $this->handleFollow($activity, $signerId, $signerDoc);
                break;
            case 'Create':
                $this->handleCreate($activity, $signerId, $signerDoc);
                break;
            case 'Like':
                $this->handleLike($activity, $signerId, $signerDoc);
                break;
            case 'Undo':
                $this->handleUndo($activity, $signerId);
                break;
            case 'Accept':
                $this->handleAccept($activity, $signerId);
                break;
            case 'Delete':
                $this->handleDelete($activity, $signerId);
                break;
            case 'Update':
                $this->handleUpdate($activity, $signerId);
                break;
            default:
                Yii::info('Fediverse inbox: ignoring unsupported type ' . $activity['type'], 'fediverse');
        }
```

- [ ] Add two small helpers:
```php
    /** Persist (creating if needed) the signer as a remote actor — call only when about to store. */
    private function persistActor(string $signerId, array $signerDoc): RemoteActor
    {
        return (new RemoteActorResolver())->resolveFromDocument($signerId, $signerDoc);
    }
```

- [ ] **`handleFollow(array $activity, string $signerId, array $signerDoc)`**: resolve the target first; if null, return (no actor). Then `$remote = $this->persistActor($signerId, $signerDoc);` and proceed (store follow + sendAccept).

- [ ] **`handleLike(array $activity, string $signerId, array $signerDoc)`**: resolve the likeable first; if null, warn + return (no actor). Then `$remote = $this->persistActor(...)`, `$author = User::findOne($remote->user_id)`, addLike.

- [ ] **`handleCreate(array $activity, string $signerId, array $signerDoc)`**:
  - not a Note → return (no actor).
  - top-level (no `inReplyTo`): look up the actor — `$remote = RemoteActor::findOne(['ap_id' => $signerId])`; if null → return (a non-followed stranger; don't create). Else `$this->ingestRemotePost($object, $remote)` (which itself still checks the follow).
  - reply: `resolveContent(inReplyTo)`; if null → return (no actor). Else `$remote = $this->persistActor(...)`, addComment.

- [ ] **`handleUndo(array $activity, string $signerId)`**: `$remote = RemoteActor::findOne(['ap_id' => $signerId]); if ($remote === null) { return; }` then the existing body.

- [ ] **`handleAccept(array $activity, string $signerId)`**: same look-up-or-return, then existing body.

- [ ] **`handleDelete(array $activity, string $signerId)`**: same look-up-or-return, then existing body.

- [ ] **`handleUpdate(array $activity, string $signerId)`**: same look-up-or-return, then existing body. (Refreshing an actor we don't know is pointless.)

- [ ] **Verify:** `php -l`. Logic review: an inbound Like/Create that attaches still creates the actor + stores (existing behaviour preserved); a Follow we accept still creates the actor; Undo/Delete/Update/Accept and `Announce`/unsupported never create an actor; a top-level post from a non-followed account creates nothing.

---

## Task 3: CHANGELOG

Add under `0.1.0 (Unreleased)` (before the unit-tests bullet):
```markdown
- Fix: Inbound activities no longer create a federated shadow user unless we
  actually store something for them — a follow we accept, a like or reply on our
  content, or a post from an account we follow. Boosts, likes/replies that don't
  attach, posts from strangers, and Undo/Delete/Update/Accept from unknown actors
  are processed without minting an empty user. A new
  `fediverse/prune-remote-actors` console command removes the orphan shadow users
  accumulated before this fix (those with no follow and no like/comment/post).
```

---

## Self-review risks
- **Don't weaken auth:** signature verification in `authenticate()` is byte-for-byte unchanged; only the post-verification persistence moves out.
- **Preserve working inbound features:** likes/comments/follows that DO attach must still create the actor + store — the storing handlers call `persistActor()` at exactly the old point (just after target resolution instead of before).
- **Undo ordering:** Undo{Like}/Undo{Follow} from a known actor still works (look-up succeeds because we stored their like/follow → the actor exists). From an unknown actor there is nothing to undo anyway.
- **Prune safety:** only actors with no follow link AND no authored like/comment/content are deleted; deletion via `User::delete()` cleans up cleanly. Dry-run by default.
