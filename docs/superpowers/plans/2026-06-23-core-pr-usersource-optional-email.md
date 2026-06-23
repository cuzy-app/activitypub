# Core PR — let a UserSource declare email optional

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`). **DO NOT COMMIT — the controller commits on the human's explicit word.**
>
> ⚠️ **This plan modifies the HumHub CORE repo, NOT the fediverse module.** All paths are under `/home/luke/work/humhub/core/develop/protected/humhub`. It is a generic, upstream-quality change (carried as a HumHub core PR); it is the prerequisite for the module dropping synthetic `@federated.invalid` emails. Spec: `docs/superpowers/specs/2026-06-23-fediverse-identity-base.md` (component A).

**Goal:** Allow a `UserSource` to declare that its users do not require an email address, and have `User::isEmailRequired()` honor it — so federated users (governed by a future `FederatedUserSource`) can have a real `null` email.

**Architecture:** Add `isEmailRequired(): bool` (default `true`) to `UserSourceInterface` + `BaseUserSource`. In `User::isEmailRequired()`, resolve the user's source via its `user_source` column (`Yii::$app->userSourceCollection->getUserSource(...)`, guarded) and return `false` when the source declares email optional; otherwise the existing global `user` module setting. Fully backward-compatible: default `true` everywhere, and users whose `user_source` is unregistered fall back to the global setting.

**Tech Stack:** PHP 8, Yii2, HumHub core user module. Target: `~/work/humhub/core/develop` (separate git repo from the module).

---

## Why this is safe / backward-compatible

- `BaseUserSource::isEmailRequired()` defaults to `true`, so every existing source behaves exactly as today.
- Most installs register no custom sources (`userSources => []` by default), and `user.user_source` defaults to the password-auth id; `getUserSource()` throws `InvalidArgumentException` for unregistered ids → caught → falls back to the global `emailRequired` setting. So normal users/registration are unaffected.
- Only a user whose `user_source` resolves to a registered source that returns `isEmailRequired() === false` skips the email requirement.

## Researched facts (verified)

- `User::isEmailRequired()` (`modules/user/models/User.php:207-212`) currently just returns `Yii::$app->getModule('user')->emailRequired`.
- `User` has a `user_source` column (`User.php:48` `@property`, validated `:188`, defaulted to `(new PasswordAuth())->getId()` at `:577-578`).
- `Yii::$app->userSourceCollection` (component, `config/common.php:219`) → `getUserSource(string $id): UserSourceInterface` throws `yii\base\InvalidArgumentException` on unknown id (`source/UserSourceCollection.php:82-86`).
- `UserSourceInterface` (`source/UserSourceInterface.php`) has no email method today; `BaseUserSource` (`source/BaseUserSource.php`) provides the defaults.

---

## Task 1: add `isEmailRequired()` to the UserSource contract

**Files:** Modify `modules/user/source/UserSourceInterface.php`, `modules/user/source/BaseUserSource.php`

- [ ] **Step 1: Declare it on the interface** — add to `UserSourceInterface` (next to `canDeleteAccount()`):

```php
    /**
     * Whether users provisioned by this source must have an email address.
     * When false, {@see User::isEmailRequired()} returns false for such users
     * regardless of the global `user` module `emailRequired` setting.
     */
    public function isEmailRequired(): bool;
```

- [ ] **Step 2: Default it on the base class** — add to `BaseUserSource` (next to `canDeleteAccount()`):

```php
    public function isEmailRequired(): bool
    {
        return true;
    }
```

- [ ] **Step 3: Verify** `php -l modules/user/source/UserSourceInterface.php && php -l modules/user/source/BaseUserSource.php` → `No syntax errors detected`.

- [ ] **Step 4: STOP — do not commit.** Report the diff.

---

## Task 2: resolve via the existing `UserSourceService` (no new `User` method)

> **Design note (revised):** HumHub already has `humhub\modules\user\services\UserSourceService` which resolves a user → its `UserSource` (`getForUser($user)->getUserSource()`), with a SAFE fallback to `LocalUserSource` on an unknown/misconfigured id (never throws). It already exposes `canChangeEmail()`/`canDeleteAccount()` etc. So we reuse it instead of adding a resolver to the `User` model.

**Files:** Modify `modules/user/services/UserSourceService.php`, `modules/user/models/User.php`

- [ ] **Step 1: Add a convenience method to `UserSourceService`** (next to `canDeleteAccount()`):

```php
    public function isEmailRequired(): bool
    {
        return $this->getUserSource()->isEmailRequired();
    }
```

- [ ] **Step 2: In `User.php`**, add `use humhub\modules\user\services\UserSourceService;` (if missing) and REPLACE `isEmailRequired()` (`User.php:207-212`) with:

```php
    public function isEmailRequired(): bool
    {
        // A UserSource may declare email optional (e.g. federated users). The
        // service resolves the source safely (falls back to LocalUserSource for
        // an unknown/misconfigured source), so this never breaks a user save.
        if (!UserSourceService::getForUser($this)->isEmailRequired()) {
            return false;
        }
        /* @var $userModule Module */
        $userModule = Yii::$app->getModule('user');
        return $userModule->emailRequired;
    }
```
(No `getUserSourceInstance()` on `User` — `UserSourceService::getUserSource()` already does safe resolution + fallback. No `try/catch` needed in `User`.)

- [ ] **Step 3: Verify** `php -l` on both files → `No syntax errors`. Confirm exactly one `isEmailRequired` in `User.php` (and the service), and that `getUserSourceInstance` does NOT exist in `User.php`.

- [ ] **Step 4: STOP — do not commit.** Report the diff.

---

## Task 3: unit test (core test suite)

**Files:** Create a unit test under the core's user-module tests (match the existing location/namespace, e.g. `modules/user/tests/codeception/unit/models/UserEmailRequiredTest.php` — adapt to the actual convention found in the repo).

- [ ] **Step 1: Write a test** asserting:
  - a `User` with `user_source = ''` (or an unregistered id) → `isEmailRequired()` follows the global `emailRequired` setting (unchanged behavior);
  - a `User` whose `user_source` resolves (register a stub source via `Yii::$app->userSourceCollection->setUserSource('test-optional', $stub)` where `$stub` is an anonymous `BaseUserSource` subclass overriding `isEmailRequired(): bool { return false; }` and `getId(): string { return 'test-optional'; }`) → `isEmailRequired()` returns `false` regardless of the global setting.

Follow the core's existing unit-test base class and bootstrap (look at a neighbouring `models/*Test.php`).

- [ ] **Step 2: Run** the core test if the suite is runnable in this environment (`vendor/bin/codecept run unit ...`); otherwise rely on `php -l` of the test file. Report which.

- [ ] **Step 3: STOP — do not commit.** Report the diff.

---

## Deploy + verification

1. Code lives in the dev/test core (`~/work/humhub/core/develop`), which the running instance uses — opcache picks up the changed PHP within 2s.
2. Verification is exercised end-to-end by **Plan 2/3** (creating a federated user with `user_source='fediverse'` + the `FederatedUserSource` returning `isEmailRequired()=false`, then saving the user with a `null` email succeeds). On its own, this PR has no observable behavior change (default `true` preserves everything).

## Done criteria

- `php -l` clean on all three core files; unit test added (run if possible).
- No behavior change for existing users/sources (default `true`); a source declaring `isEmailRequired()=false` makes `User::isEmailRequired()` false for its users.

## Handoff notes (for the human)

- This is a **core** change in `~/work/humhub/core/develop` — to be carried upstream as a HumHub PR. Commit only on your word; you decide the upstream PR/branch.
- Once present on the dev/test core, **Plan 2** can set `user.user_source='fediverse'` + a `FederatedUserSource::isEmailRequired()=false` and drop synthetic emails; bump the module's `humhub.minVersion` to the core version that includes this.

## Self-review risks

- **Backward compatibility:** confirm a normal user (default `user_source`, no registered source) still gets the global `emailRequired` behavior (the `InvalidArgumentException` guard must catch the unregistered-id case).
- **Validation hot path:** `isEmailRequired()` runs on every user save, so `getUserSourceInstance()` catches `\Throwable` (covers `InvalidArgumentException` for unknown ids AND `InvalidConfigException` from a misconfigured registered source) and falls back to null → global setting. A broken source config must never make users un-saveable. (Decision made; baked into the code above.)
