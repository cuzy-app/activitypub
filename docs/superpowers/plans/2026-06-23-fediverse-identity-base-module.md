# Fediverse — Module identity base (Plan 2)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`). **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Give federated (remote shadow) users a clean HumHub identity: governed by a dedicated `FederatedUserSource`, tagged via the native `user.user_source` column, with a readable `@user@domain` username and a real `null` email (no more `@federated.invalid`).

**Architecture:** A `FederatedUserSource extends BaseUserSource` (id `fediverse`; managed attrs `firstname/lastname/about`; `canDeleteAccount=false`; `isEmailRequired()=false`), registered via the `UserSourceCollection::EVENT_BEFORE_USER_SOURCES_SET` event from the module's `config.php`. `RemoteActorResolver::createShadowUser` sets `user_source='fediverse'`, `email=null`, and a `@user@domain` username (length-capped to the username max).

**Tech Stack:** PHP 8, Yii2, HumHub UserSource API (requires the merged core PR #8239 for the email step — already on the dev/test cores). Depends on Plan 1 (core). Spec: `docs/superpowers/specs/2026-06-23-fediverse-identity-base.md`.

**Prereq confirmed:** core PR #8239 (per-source optional email) is merged on `develop` + `fedihub` cores. `module.json` `humhub.minVersion` is already `1.19` (the core line carrying the PR) — no bump needed.

---

## Scope

- **In:** `FederatedUserSource` + registration; new shadow users created with `user_source='fediverse'`, `@user@domain` username, `null` email.
- **Out:** migrating the ~17 existing shadow users (their usernames/emails/source) — that's **Plan 3** (migration + media backfill). This plan only changes how NEW shadow users are created.

## Key facts (verified against core/develop)

- `UserSourceCollection::EVENT_BEFORE_USER_SOURCES_SET` (value `'beforeUserSourcesSet'`) lets a module add to `$event->parameters['userSources'][$id] = ['class' => ...]` (LDAP module does exactly this in its `config.php` + `Events`).
- `BaseUserSource` is abstract and does NOT implement `createUser()` → the subclass must. It exposes settable props: `$id`, `$title`, `$managedAttributes`, `$deleteAccount` (→ `canDeleteAccount()`), `$usernameStrategy`. `getId()` returns `$this->id`.
- `User::isEmailRequired()` (post core PR) consults the source → with `FederatedUserSource::isEmailRequired()=false`, a `null` email validates. `email` column is nullable; `unique` allows multiple nulls.
- Username regex `/^[\p{L}\d_\-@\.]+$/iu`, max `maximumUsernameLength` (50). Leading `@` valid.

---

## File structure

```
fediverse/
├── services/FederatedUserSource.php   # NEW: the UserSource
├── Events.php                         # MODIFY: register the source on the collection event
├── config.php                         # MODIFY: bind EVENT_BEFORE_USER_SOURCES_SET
└── services/RemoteActorResolver.php   # MODIFY: user_source + @handle username + null email
```

---

## Task 1: `FederatedUserSource` + registration

**Files:** Create `services/FederatedUserSource.php`; Modify `Events.php`, `config.php`

- [ ] **Step 1: Create `services/FederatedUserSource.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\user\models\User;
use humhub\modules\user\source\BaseUserSource;
use humhub\modules\user\source\UserSourceInterface;

/**
 * UserSource governing federated (remote ActivityPub) shadow users. These users
 * are provisioned by RemoteActorResolver on inbound activity (not via a login
 * flow), tagged with user.user_source = self::ID. The source makes their synced
 * profile fields read-only, forbids self-deletion, and (via the core per-source
 * email-optional support) lets them exist without an email address.
 */
class FederatedUserSource extends BaseUserSource
{
    public const ID = 'fediverse';

    public string $id = self::ID;
    public string $title = 'Fediverse';
    public array $managedAttributes = ['firstname', 'lastname', 'about'];
    public bool $deleteAccount = false; // federated users cannot self-delete
    public string $usernameStrategy = UserSourceInterface::USERNAME_AUTO_GENERATE;

    /** Federated users have no email. */
    public function isEmailRequired(): bool
    {
        return false;
    }

    /**
     * Not used: federated shadow users are created by RemoteActorResolver in
     * response to inbound activity, not via the standard provisioning flow.
     */
    public function createUser(array $attributes): ?User
    {
        return null;
    }
}
```

- [ ] **Step 2: Add the registration handler to `Events.php`**

Add import `use humhub\modules\fediverse\services\FederatedUserSource;`. Add handler:

```php
    /** Register the Fediverse UserSource so it governs federated shadow users. */
    public static function onUserSourceCollectionSet($event): void
    {
        $event->parameters['userSources'][FederatedUserSource::ID] = ['class' => FederatedUserSource::class];
    }
```

- [ ] **Step 3: Bind the event in `config.php`** — add to the `events` array:

```php
        ['class' => 'humhub\modules\user\source\UserSourceCollection', 'event' => 'beforeUserSourcesSet', 'callback' => ['humhub\modules\fediverse\Events', 'onUserSourceCollectionSet']],
```

- [ ] **Step 4: Verify** `php -l services/FederatedUserSource.php && php -l Events.php && php -l config.php` → all clean. After deploy (cache flush), `php yii fediverse/status` still works and the source is registered (optionally check via a quick console probe that `Yii::$app->userSourceCollection->getUserSource('fediverse')` resolves).

- [ ] **Step 5: STOP — do not commit.** Report the diff.

---

## Task 2: create shadow users on the clean model

**Files:** Modify `services/RemoteActorResolver.php`

- [ ] **Step 1: In `createShadowUser`**, change the `User` setup. Current:
```php
        $user = new User();
        $user->username = $this->uniqueUsername($preferred . '@' . $domain);
        $user->email = 'remote-' . substr(sha1($actorUrl), 0, 20) . '@federated.invalid';
        $user->status = User::STATUS_ENABLED;
        $user->visibility = User::VISIBILITY_REGISTERED_ONLY;
```
to:
```php
        $user = new User();
        $user->user_source = FederatedUserSource::ID; // governs: email optional, no self-delete, managed attrs
        $user->username = $this->uniqueUsername('@' . $preferred . '@' . $domain);
        $user->email = null; // FederatedUserSource declares email optional (core PR #8239)
        $user->status = User::STATUS_ENABLED;
        $user->visibility = User::VISIBILITY_REGISTERED_ONLY;
```
(`FederatedUserSource` is in the same `services` namespace — no import needed.)

- [ ] **Step 2: Replace `uniqueUsername`** with a length-aware version (the handle can approach/exceed the 50-char username max):

```php
    private function uniqueUsername(string $base): string
    {
        $max = (int) (Yii::$app->getModule('user')->maximumUsernameLength ?: 50);
        $candidate = mb_substr($base, 0, $max);
        $i = 1;
        while (User::find()->where(['username' => $candidate])->exists()) {
            $suffix = '_' . $i;
            $candidate = mb_substr($base, 0, $max - mb_strlen($suffix)) . $suffix;
            $i++;
        }
        return $candidate;
    }
```

- [ ] **Step 3: Verify** `php -l services/RemoteActorResolver.php` → clean. Confirm the synthetic-email line is gone and `user_source` is set.

- [ ] **Step 4: STOP — do not commit.** Report the diff.

---

## Deploy + live verification (controller + human)

1. (controller) Flush the HumHub cache (`php yii cache/flush-all` on the fedihub console) so the new `config.php` event registration binds; opcache picks up the new classes.
2. **Cmburns** (Mastodon) — to exercise NEW shadow creation, the simplest is a fresh remote account; or:
   **Admin** (HumHub): follow a **new** Fediverse account (one not yet a shadow user) by `@user@domain`.
3. (controller) Verify the new shadow `user` row: `user_source = 'fediverse'`, `username = '@user@domain'` (length-capped), `email IS NULL`, and the save succeeded (no "Email cannot be blank"). Confirm `Yii::$app->userSourceCollection->getUserSource('fediverse')` resolves and `UserSourceService::getForUser($shadow)->isEmailRequired()` is false.

## Done criteria

- `php -l` clean on all files; the `fediverse` source is registered.
- A newly created federated shadow user has `user_source='fediverse'`, an `@user@domain` username, and a `null` email — saved without error.

## Self-review risks

- **Email-null save:** depends on core PR #8239 being live (it is). Confirm a new shadow user saves with `email=null` (the source must be registered AND `user_source` set before validation — both hold).
- **Managed attributes vs. our refresh:** `getManagedAttributes()` locks fields in the profile UI form only; our programmatic `Update{Person}` refresh writes the model directly + saves, so it is NOT blocked. Confirm the refresh still updates name/bio.
- **Username length/uniqueness:** confirm a long handle is capped to ≤ max and collisions get a `_N` suffix while staying ≤ max.
- **No effect on existing users:** existing shadow users keep their old username/email until Plan 3 migrates them; normal local users (default `user_source`) are unaffected.
