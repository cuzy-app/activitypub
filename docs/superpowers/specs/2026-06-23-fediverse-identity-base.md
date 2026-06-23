# Fediverse — Clean federated-user identity base (design spec)

**Status:** Design — pending user review, then split into implementation plans.
**Date:** 2026-06-23

## Goal

Give federated (remote, ActivityPub) users a clean, first-class representation in HumHub instead of the current ad-hoc "shadow user" with a synthetic email and a plain `user@domain` username. Concretely: a dedicated **UserSource**, a standard **identity mapping**, a readable **`@user@domain` username**, and **no fake emails** (via a small HumHub core change). This becomes the base the media backfill then runs on.

## Decisions (agreed)

1. **Email:** pursue a small, generic **HumHub core PR** so a `UserSource` can declare email *not required*; then federated users have a real `null` email and the `@federated.invalid` addresses are removed.
2. **Username (remote users):** the handle **with a leading `@`**, e.g. `@nicolegroen@mastodon.social`.
3. **Order:** build this identity base **first**, then the avatar/banner/bio backfill (so the backfill runs on the clean model and isn't redone).

## Researched facts (decision-grade)

- **UserSource** (`humhub\modules\user\source\UserSourceInterface` / `BaseUserSource`) models user *provisioning + attribute ownership* (NOT auth). A module can register one (`fediverse`) via the `EVENT_BEFORE_USER_SOURCES_SET` event or `Yii::$app->userSourceCollection->setUserSource('fediverse', …)`. It controls managed (read-only) attributes, `canDeleteAccount()`, approval, username strategy. **No core change.**
- **Identity mapping:** the `user_auth` table (`humhub\modules\user\models\Auth`) stores `(user_id, source, source_id)` — the standard place to map a local User to an external identity. **No core change.**
- **Username regex** already allows the handle: `/^[\p{L}\d_\-@\.]+$/iu`, min `minimumUsernameLength` (4), **max `maximumUsernameLength` (50)**. A leading `@`, `@`, and `.` are all valid; profile URLs `/u/{username}` resolve by literal DB lookup. **No core change** (only a length fallback for >50-char handles).
- **Email** column is already `NULL`-able; the requirement is the *global* `user` module setting `emailRequired` (default true) read by `User::isEmailRequired()`. There is **no per-user/per-source** switch today → that is the core PR.
- Canonical AP identity already lives in the module's `fediverse_local_actor.ap_id` (local users' outbound actor) and `fediverse_remote_actor.ap_id`+`handle` (remote users) — these stay as the rich metadata store.

## Architecture

### A. Core PR — per-source optional email (HumHub core)
- **Intent:** a `UserSource` can declare email optional, and `User::isEmailRequired()` honors the user's source.
- **Likely shape (to be pinned in the Core-PR plan):** add `isEmailRequired(): bool` (default `true`) to `UserSourceInterface`/`BaseUserSource`; in `humhub\modules\user\models\User::isEmailRequired()`, if the user belongs to a source (resolved via its `user_auth.source` → the registered `UserSource`), return that source's value; else fall back to the global module setting. Generic and not Fediverse-specific.
- **Repo:** `humhub` core (the local checkout at `~/work/humhub/core/develop`); carried upstream as a PR. The module's `humhub.minVersion` bumps to the core version that includes it.
- **Until it lands:** federated users keep a synthetic email (current behavior); the rest of the base can ship independently.

### B. `FederatedUserSource` (module)
- A `UserSource` with id `fediverse`, registered from the module's `config.php`/bootstrap, governing the **remote shadow users** (identified by their `user_auth` `source='fediverse'`).
- Behavior: `getManagedAttributes()` = the remote-synced fields (firstname/lastname/about + profile image) → locked/read-only in the profile UI; `canDeleteAccount()` = false; `isEmailRequired()` = false (the new core method); username strategy = auto (the module sets it). No password login (already login-disabled).
- Shadow users continue to be created by `RemoteActorResolver` (not via an auth-client login), but are now **tagged** to this source so HumHub applies its rules.

### C. Identity mapping via `user_auth`
- On creating/resolving a remote shadow user, write `Auth(user_id, source='fediverse', source_id=<actor ap_id>)`. This is the standard HumHub external-identity record and links the user to `FederatedUserSource`.
- The module's `fediverse_remote_actor` (ap_id, handle, keys, inboxes, …) remains the canonical rich metadata; `user_auth` is the thin, HumHub-native cross-reference. Lookups can use either; `fediverse_remote_actor` stays primary for AP operations.
- Local users are NOT given a `fediverse` `user_auth` record (they are not externally sourced); their outbound identity stays in `fediverse_local_actor`.

### D. Username scheme `@user@domain`
- `RemoteActorResolver::uniqueUsername` base becomes `'@' . preferredUsername . '@' . domain` (the WebFinger handle with a leading `@`).
- **Length:** cap at the 50-char `maximumUsernameLength`; if the handle is longer, fall back (e.g. truncate the local part, or `@` + first N chars) while keeping uniqueness via the existing `_N` suffix on collision.
- Username remains display/URL only; identity stays in `user_auth` + `fediverse_remote_actor`.

### E. Drop fake emails
- After the core PR: `createShadowUser` sets `email = null` (no synthetic address).
- Validation must pass because `FederatedUserSource::isEmailRequired()` = false.

### F. Migrate the existing ~17 shadow users (one-off console command)
A single `fediverse/migrate-shadow-users` command (idempotent) that, for each existing federated shadow user (found via `fediverse_remote_actor`):
1. writes the `user_auth` (`fediverse`, ap_id) record if missing;
2. renames the username to the `@user@domain` scheme (length-capped, collision-safe);
3. nulls the synthetic `@federated.invalid` email (post core-PR);
4. **backfills** avatar/banner/bio from a fresh actor fetch (reusing `RemoteMediaFetcher` + `RemoteActorResolver::applyProfileImages` + name/bio) — i.e. the backfill folds into this migration.

## Sequencing → plans

1. **Plan 1 — Core PR:** per-source optional email in HumHub core (+ tests there).
2. **Plan 2 — Module identity base:** `FederatedUserSource`, `user_auth` tagging on shadow creation, `@user@domain` username scheme, `null` email on creation (gated on the core PR being present).
3. **Plan 3 — Migration + backfill:** the `migrate-shadow-users` command (auth records, usernames, emails, media/bio backfill) for the existing users.

Each plan is independently testable; Plan 2's email-null step depends on Plan 1 being deployed to the dev/test core.

## Risks / open points

- **Core resolution of "a user's source":** confirm how HumHub maps a `User` → its `UserSource` (via `user_auth.source` matching a registered source id?) so `User::isEmailRequired()` can consult it. Pin in Plan 1.
- **Username churn:** renaming existing shadow usernames changes their `/u/{username}` profile URLs. Acceptable (federated profiles, low external linkage), but note it.
- **Managed attributes vs. our sync:** ensure `getManagedAttributes()` locking the profile in the UI doesn't block our own programmatic `Update{Person}` refresh (we write directly to the model, not via the UI form) — verify.
- **minVersion bump:** once the core PR is required for the email step, bump `module.json` `humhub.minVersion` accordingly (HumHub convention).
- **No-regression:** local users and normal (non-federated) registration must be unaffected by the core change and the source.

## Out of scope

- OAuth/"log in with your Fediverse account" (the UserSource here is provisioning-only, no auth client).
- Changing local users' identity model (they stay normal HumHub users + `fediverse_local_actor`).
- Performance items (DeliverActivity N+1, outbox paging) — tracked separately.
