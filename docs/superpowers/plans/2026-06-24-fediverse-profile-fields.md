# Fediverse — Proper identity profile fields + clean display name (Plan 4)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`). **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Stop abusing `firstname`/`lastname` for federated identity. Introduce a "Fediverse" profile category with two protected fields — `fediverse_handle` and `fediverse_displayname` — store the real identity there, display it via `displayNameCallback`, and leave federated users' `firstname`/`lastname` empty (no core change; via `Profile::save(false)`).

**Architecture:** A module migration creates the category + fields + columns. The user module's `displayNameCallback`/`displayNameSubCallback` (set early via an app `beforeRequest` event) render federated users from the new fields. `RemoteActorResolver::createShadowUser` and the `migrate-shadow-users` command populate the new fields and clear `firstname`/`lastname` (saving the profile with validation off, since those are globally `required` but nullable columns).

**Tech Stack:** PHP 8, Yii2, HumHub profile fields + `displayNameCallback`. No core change. Spec: `docs/superpowers/specs/2026-06-23-fediverse-identity-base.md`.

**Decisions (from the user):** create the Fediverse profile fields in the module; use displayName/Sub callbacks; do NOT make a 2nd core PR for name-requiredness — instead work around it with `save(false)` (the `profile.firstname`/`lastname` columns are nullable `varchar(255)`; the `required` rule is validation-only, enforced for normal users via the UI form).

---

## Key facts (verified)

- `profile.firstname` / `profile.lastname` are `varchar(255) NULL` → `Profile::save(false)` persists empty/null values without tripping the global `required` rule.
- Profile fields are created (precedent: `m140701_000611_profile_genderfield`) by `insert('profile_field', [...])` + `addColumn('profile', <name>, 'varchar(255) DEFAULT NULL')`. `field_type_class` today is the FQN `humhub\modules\user\models\fieldtype\Text`.
- `profile_field_category` cols: `title`,`description`(NOT NULL),`sort_order`(NOT NULL),`visibility`(NOT NULL),`is_system`,… Existing: General/Communication/Social bookmarks (sort 100/200/300).
- `User::getDisplayName()` returns `displayNameCallback($user)` when set (else firstname+' '+lastname, untrimmed, username fallback). `getDisplayNameSub()` uses `displayNameSubCallback`.
- A profile dynamic column is read as `$profile->fediverse_displayname` once the column exists.

---

## Task 1: migration — Fediverse category + fields + columns

**Files:** Create `migrations/m260624_100000_fediverse_profile_fields.php`

```php
<?php

use humhub\components\Migration;
use yii\db\Query;

class m260624_100000_fediverse_profile_fields extends Migration
{
    public function safeUp()
    {
        // 1. Category
        $categoryId = (new Query())->select('id')->from('profile_field_category')
            ->where(['title' => 'Fediverse'])->scalar();
        if (!$categoryId) {
            $this->insert('profile_field_category', [
                'title' => 'Fediverse',
                'description' => '',
                'sort_order' => 400,
                'visibility' => 1,
                'is_system' => 1,
            ]);
            $categoryId = (new Query())->select('id')->from('profile_field_category')
                ->where(['title' => 'Fediverse'])->scalar();
        }

        // 2. Fields + columns
        $fields = [
            'fediverse_handle' => ['title' => 'Fediverse Handle', 'sort_order' => 410],
            'fediverse_displayname' => ['title' => 'Fediverse Display Name', 'sort_order' => 420],
        ];
        foreach ($fields as $name => $cfg) {
            $exists = (new Query())->from('profile_field')->where(['internal_name' => $name])->exists();
            if (!$exists) {
                $this->insert('profile_field', [
                    'profile_field_category_id' => $categoryId,
                    'field_type_class' => 'humhub\\modules\\user\\models\\fieldtype\\Text',
                    'internal_name' => $name,
                    'title' => $cfg['title'],
                    'sort_order' => $cfg['sort_order'],
                    'required' => 0,
                    'show_at_registration' => 0,
                    'editable' => 0,   // protected: system-managed, not user-editable
                    'visible' => 1,
                    'is_system' => 1,
                ]);
            }
            if (!in_array($name, $this->db->getTableSchema('profile')->columnNames, true)) {
                $this->addColumn('profile', $name, 'varchar(255) DEFAULT NULL');
            }
        }
    }

    public function safeDown()
    {
        foreach (['fediverse_handle', 'fediverse_displayname'] as $name) {
            $this->delete('profile_field', ['internal_name' => $name]);
            if (in_array($name, $this->db->getTableSchema('profile')->columnNames, true)) {
                $this->dropColumn('profile', $name);
            }
        }
        $this->delete('profile_field_category', ['title' => 'Fediverse']);
        return true;
    }
}
```

- [ ] **Steps:** create the file; `php -l` it. (Run it in the Deploy section.)

---

## Task 2: displayName + displayNameSub callbacks

**Files:** Modify `Events.php`, `config.php`

- [ ] **Step 1: Add handlers to `Events.php`** (`FederatedUserSource` + `User` are already imported):

```php
    /** Set the display-name callbacks so federated users render from their Fediverse fields. */
    public static function onBeforeRequest(): void
    {
        $userModule = Yii::$app->getModule('user');
        $userModule->displayNameCallback = [self::class, 'displayName'];
        $userModule->displayNameSubCallback = [self::class, 'displayNameSub'];
    }

    /** Federated users → their Fediverse display name (fallback handle/username); others → HumHub default. */
    public static function displayName($user): string
    {
        if (!$user instanceof User) {
            return '';
        }
        if ($user->user_source === FederatedUserSource::ID && $user->profile !== null) {
            $dn = trim((string) $user->profile->fediverse_displayname);
            if ($dn !== '') {
                return $dn;
            }
            $handle = trim((string) $user->profile->fediverse_handle);
            return $handle !== '' ? $handle : $user->username;
        }
        // HumHub default (mirrors User::getDisplayName)
        $name = '';
        if ($user->profile !== null && Yii::$app->settings->get('displayNameFormat') === '{profile.firstname} {profile.lastname}') {
            $name = $user->profile->firstname . ' ' . $user->profile->lastname;
        }
        return ($name === '' || $name === ' ') ? $user->username : $name;
    }

    /** Federated users → their handle as the secondary line; others → HumHub default sub. */
    public static function displayNameSub($user): string
    {
        if (!$user instanceof User || $user->profile === null) {
            return '';
        }
        if ($user->user_source === FederatedUserSource::ID) {
            return trim((string) $user->profile->fediverse_handle);
        }
        return $user->profile->getFieldValue(Yii::$app->settings->get('displayNameSubFormat', ''), false, false) ?? '';
    }
```

- [ ] **Step 2: Register the early hook in `config.php`** events:

```php
        ['class' => 'yii\base\Application', 'event' => 'beforeRequest', 'callback' => ['humhub\modules\fediverse\Events', 'onBeforeRequest']],
```

- [ ] **Step 3: Verify** `php -l Events.php && php -l config.php`.

---

## Task 3: create shadow users with the new fields (clean firstname/lastname)

**Files:** Modify `services/RemoteActorResolver.php`

- [ ] In `createShadowUser`, replace the profile population. Current:
```php
        $profile = $user->profile;
        $profile->firstname = mb_substr($name, 0, 255);
        $profile->lastname = mb_substr($domain, 0, 255);
        if (!$profile->save()) {
            Yii::warning('Federated user profile save failed: ' . implode(', ', $profile->getErrorSummary(true)), 'fediverse');
        }
```
with:
```php
        $profile = $user->profile;
        $profile->fediverse_displayname = mb_substr($name, 0, 255);
        $profile->fediverse_handle = '@' . $remote->handle ?? mb_substr($preferred . '@' . $domain, 0, 255);
        // firstname/lastname intentionally left empty for federated users — their
        // identity lives in the Fediverse fields. They are globally `required` but
        // nullable columns, so save without validation.
        if (!$profile->save(false)) {
            Yii::warning('Federated user profile save failed', 'fediverse');
        }
```
NOTE: `$remote` is created/saved earlier in `resolveFromDocument` BEFORE `createShadowUser` is called? Check the call order — `createShadowUser($doc, $actorUrl)` runs FIRST (to make the user), THEN the `RemoteActor` row is built. So `$remote->handle` is not available inside `createShadowUser`. Use the locally-computed handle instead: `'@' . $preferred . '@' . $domain` (capped). So:
```php
        $profile->fediverse_handle = mb_substr('@' . $preferred . '@' . $domain, 0, 255);
```

- [ ] **Verify** `php -l services/RemoteActorResolver.php`. Confirm firstname/lastname are no longer set there and `save(false)` is used.

---

## Task 4: migrate existing shadow users onto the new fields

**Files:** Modify `commands/FediverseController.php` (`actionMigrateShadowUsers`)

- [ ] In the backfill block of `actionMigrateShadowUsers`, replace the firstname/lastname/about writes with the new fields + cleared names:

```php
                    $profile = $user->profile;
                    if ($profile !== null) {
                        $name = trim((string) ($doc['name'] ?? '')) ?: (string) $remote->preferred_username;
                        $profile->fediverse_displayname = mb_substr($name, 0, 255);
                        $profile->fediverse_handle = mb_substr('@' . $remote->handle, 0, 255);
                        $summary = trim(strip_tags((string) ($doc['summary'] ?? '')));
                        $profile->about = $summary !== '' ? $summary : $profile->about;
                        $profile->firstname = null;
                        $profile->lastname = null;
                        $profile->save(false);
                    }
```
(The avatar/banner via `applyProfileImages` stays. For users whose actor fetch fails (410), still clear firstname/lastname + set fediverse_handle from `$remote->handle` even without a fresh `$doc` — i.e. do the handle/name-from-remote + name-clear OUTSIDE the fetch `try`, so identity-display is fixed for everyone, media/displayname-from-doc best-effort inside.)

Restructure so: outside the fetch try → set `fediverse_handle` from `$remote->handle`, set `fediverse_displayname` from `$remote->preferred_username` as a baseline, null firstname/lastname, `save(false)`. Inside the fetch try → upgrade `fediverse_displayname`/`about` from `$doc` + `applyProfileImages`, `save(false)` again.

- [ ] **Verify** `php -l commands/FediverseController.php`.

---

## Deploy + verification (controller + human)

1. (controller) Run the migration: on the fedihub console `php yii migrate/up --includeModuleMigrations=1` (or the module migration path) — creates the category, fields, columns. Then flush cache.
2. (controller) Re-run `php yii fediverse/migrate-shadow-users` → existing 124 users get `fediverse_handle`/`fediverse_displayname` populated and `firstname`/`lastname` nulled.
3. (controller) Verify DB: for shadow users, `fediverse_displayname`/`fediverse_handle` set, `firstname`/`lastname` NULL. `getDisplayName()` returns the clean name (probe a shadow user).
4. **Admin** (HumHub): open a federated profile → display name is the remote name (no domain), sub-line is `@user@domain`, no "name domain" anywhere.

## Done criteria

- `php -l` clean; migration creates the Fediverse category + 2 fields + columns; new + existing shadow users have the Fediverse fields populated and empty firstname/lastname; federated display name is clean (callback); normal users unaffected.

## Self-review risks

- **save(false) scope:** confirm it persists null firstname/lastname (nullable cols) and doesn't skip needed lifecycle (afterSave still runs). Only validation is skipped — acceptable for system-managed shadow profiles.
- **Callback registered early enough:** `beforeRequest` fires before any `getDisplayName()` in the request (web + console); confirm federated names render and normal users are unchanged (callback mirrors the default).
- **Empty firstname/lastname elsewhere:** confirm nothing assumes non-null firstname for federated users (directory, mentions, search use displayName → callback). Spot-check a federated profile page.
- **Idempotency:** re-running the migration command must not break (fields overwritten with same values; firstname/lastname already null).
- **No re-federation:** `save(false)` still triggers `Profile afterUpdate` → `onProfileUpdated` → bails for shadow users (federatableActor null). Confirm no outbound storm.
