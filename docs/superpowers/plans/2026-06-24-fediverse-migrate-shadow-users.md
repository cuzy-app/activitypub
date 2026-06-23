# Fediverse — Migrate existing shadow users + media/bio backfill (Plan 3)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`). **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Bring the ~17 pre-existing federated shadow users onto the clean identity model from Plan 2, and backfill their media/bio: set `user_source='fediverse'`, rename to `@user@domain`, drop the synthetic `@federated.invalid` email, and download avatar/banner + refresh name/bio from a fresh actor fetch — all via one idempotent console command.

**Architecture:** A `fediverse/migrate-shadow-users` console action iterating `fediverse_remote_actor` rows; reuses `RemoteActorResolver::fetchActorDocument()` + `applyProfileImages()` (both public). Idempotent: re-running is a no-op for already-migrated users.

**Tech Stack:** PHP 8, Yii2, HumHub console. Depends on Plan 1 (core PR, merged) + Plan 2 (`FederatedUserSource`, identity scheme). Spec: `docs/superpowers/specs/2026-06-23-fediverse-identity-base.md` (component F).

**Prereq:** Plan 2 live (FederatedUserSource registered; new shadow users already created on the clean model). This plan fixes the OLD ones.

---

## Scope

- **In:** for every existing `fediverse_remote_actor`'s shadow user — set `user_source`, `@user@domain` username, `null` email; backfill avatar/banner + name/bio. Idempotent + best-effort (one user's failure doesn't abort the run).
- **Out:** local users (untouched); any new federation features.

## Key facts

- `RemoteActor` has `user_id`, `handle` (`user@domain`), `domain`, `preferred_username`, `ap_id`. `RemoteActorResolver::fetchActorDocument(string $apId): array` (public, SSRF-guarded) and `applyProfileImages(User, array $doc)` (public) exist.
- New username max = `maximumUsernameLength` (50); leading `@` valid. Renaming changes `/u/{username}` profile URLs (acceptable for federated profiles).
- `email=null` validates because `FederatedUserSource::isEmailRequired()=false` once `user_source='fediverse'` is set (verified live in Plan 2).

---

## File structure

```
fediverse/
└── commands/FediverseController.php   # MODIFY: add actionMigrateShadowUsers + helper
```

---

## Task 1: the migration command

**Files:** Modify `commands/FediverseController.php`

- [ ] **Step 1: Add imports** (check existing; add what's missing): `use humhub\modules\fediverse\models\RemoteActor;`, `use humhub\modules\fediverse\services\FederatedUserSource;`, `use humhub\modules\fediverse\services\RemoteActorResolver;`, `use humhub\modules\user\models\User;`, `use Yii;`.

- [ ] **Step 2: Add the action + helper**

```php
    /**
     * Usage: php protected/yii fediverse/migrate-shadow-users
     * Brings existing federated shadow users onto the clean identity model
     * (user_source, @handle username, null email) and backfills avatar/banner/bio.
     * Idempotent.
     */
    public function actionMigrateShadowUsers(): int
    {
        $resolver = new RemoteActorResolver();
        $max = (int) (Yii::$app->getModule('user')->maximumUsernameLength ?: 50);
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        foreach (RemoteActor::find()->each() as $remote) {
            $user = User::findOne($remote->user_id);
            if ($user === null) {
                $skipped++;
                continue;
            }
            try {
                $user->user_source = FederatedUserSource::ID;

                $desired = '@' . $remote->handle;
                if ($user->username !== $desired) {
                    $user->username = $this->uniqueShadowUsername($desired, (int) $user->id, $max);
                }
                if (is_string($user->email) && str_ends_with($user->email, '@federated.invalid')) {
                    $user->email = null;
                }
                if (!$user->save()) {
                    $this->stderr('  user ' . $user->id . ' save failed: ' . implode(', ', $user->getErrorSummary(true)) . "\n");
                    $errors++;
                    continue;
                }

                // Best-effort media + name/bio backfill from a fresh actor fetch.
                try {
                    $doc = $resolver->fetchActorDocument($remote->ap_id);
                    $resolver->applyProfileImages($user, $doc);
                    $profile = $user->profile;
                    if ($profile !== null) {
                        $name = trim((string) ($doc['name'] ?? '')) ?: (string) $remote->preferred_username;
                        if ($name !== '') {
                            $profile->firstname = mb_substr($name, 0, 255);
                        }
                        if (empty($profile->lastname)) {
                            $profile->lastname = mb_substr((string) $remote->domain, 0, 255);
                        }
                        $summary = trim(strip_tags((string) ($doc['summary'] ?? '')));
                        if ($summary !== '') {
                            $profile->about = $summary;
                        }
                        $profile->save();
                    }
                } catch (\Throwable $e) {
                    $this->stdout('  backfill skipped for ' . $remote->handle . ': ' . $e->getMessage() . "\n");
                }

                $migrated++;
                $this->stdout('migrated user ' . $user->id . ' -> ' . $user->username . "\n");
            } catch (\Throwable $e) {
                $this->stderr('  error for remote ' . $remote->id . ' (user ' . $remote->user_id . '): ' . $e->getMessage() . "\n");
                $errors++;
            }
        }

        $this->stdout("Done. migrated={$migrated} skipped={$skipped} errors={$errors}\n");
        return ExitCode::OK;
    }

    /** A unique `@handle` username for a shadow user, capped to $max, excluding the user's own row. */
    private function uniqueShadowUsername(string $base, int $ownUserId, int $max): string
    {
        $candidate = mb_substr($base, 0, $max);
        $i = 1;
        while (
            User::find()->where(['username' => $candidate])->andWhere(['!=', 'id', $ownUserId])->exists()
        ) {
            $suffix = '_' . $i;
            $candidate = mb_substr($base, 0, $max - mb_strlen($suffix)) . $suffix;
            $i++;
        }
        return $candidate;
    }
```

- [ ] **Step 3: Verify** `php -l commands/FediverseController.php` → clean. Confirm one `actionMigrateShadowUsers` + one `uniqueShadowUsername`, and the imports present.

- [ ] **Step 4: STOP — do not commit.** Report the diff.

---

## Deploy + verification (controller)

1. (controller) No cache flush needed for a new console action (CLI loads fresh). Run on the fedihub console: `php yii fediverse/migrate-shadow-users`.
2. Verify in the DB: every shadow user (those in `fediverse_remote_actor`) now has `user_source='fediverse'`, an `@user@domain` username, `email IS NULL`; avatar files exist on disk for them; `firstname`/`about` populated where the remote provides them. Re-run once → all skipped/no-ops (idempotent), no errors.
3. (human) Spot-check a couple of federated profiles in HumHub: handle-style username, avatar shown, bio present.

## Done criteria

- `php -l` clean. Running the command migrates all existing shadow users to the clean model + backfills media/bio; a second run is a no-op; failures for one user are logged and don't abort the rest.

## Self-review risks

- **Username collisions / length:** confirm long handles are capped to ≤ max and any collision gets a `_N` suffix; the user's own row is excluded from the uniqueness check (re-runs must not bump the suffix).
- **Email null save:** depends on `user_source='fediverse'` being set before save (it is) + the core PR (live). Confirm no "Email cannot be blank".
- **Idempotency:** re-running must not duplicate work or re-bump usernames; `user_source`/`@`-username/null-email are all checked-then-set.
- **No re-federation:** the profile saves here fire `Profile afterUpdate` → `onProfileUpdated`, which bails for shadow users (`federatableActor` returns null) — confirm no outbound `Update{Person}` storm during migration.
- **Media load:** ~17 actor fetches + avatar/banner downloads; best-effort, each guarded — confirm a fetch failure only skips that user's backfill (identity changes already saved).
