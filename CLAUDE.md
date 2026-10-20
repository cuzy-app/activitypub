# activitypub — Claude Guide

ActivityPub federation for HumHub **1.19** (server-to-server only). It makes a HumHub instance part of
the Fediverse: local profiles become followable `Person` actors, local users follow, like and reply
to Mastodon-compatible accounts, and Lemmy-family communities can be subscribed into a Space.

- Module ID `activitypub`, namespace `humhub\modules\activitypub`, translation category
  `ActivitypubModule.base`, tables `activitypub_*`, log category `activitypub`.
- The module was called **fediverse** until October 2026. `docs/superpowers/` (specs and plans) still
  uses the old names: read `fediverse_*` as `activitypub_*` and `/fediverse/` as `/activitypub/`.
  User-facing text keeps saying "Fediverse" where it means the network (account menu entry,
  "Fediverse handle", the profile field category); text naming the *module* says "ActivityPub".
- Branch: `main` only. Version `0.1.0` is unreleased. Schema changes go into new migrations; editing
  an existing one breaks every install that already applied it.

## Skills and documents

This file holds only what is specific to this module. General knowledge comes from the
**`activitypub-engineering`** skill
([activitypub-engineering-skills](https://github.com/cuzy-app/activitypub-engineering-skills)),
installed in the agent's skills directory with
`scripts/install_skill.py --client <claude|codex|cursor|gemini|agents>`. It covers both:

- **the protocol** — document shapes, signatures, WebFinger, NodeInfo, delivery, Mastodon
  and Lemmy interop, FEPs. Use it for any change to what goes over the wire. Its
  `scripts/validate_activitypub.py` checks JSON fixtures of the documents this module emits;
- **HumHub and Yii2 module conventions** (its `humhub-*` references) — `config.php` events,
  migrations, queue jobs, permissions, tests, coding standards. Its
  `scripts/validate_module.py .` checks this module's structure.

Module-specific detail lives in **`docs/DEVELOPER.md`**: every endpoint, the inbound and
outbound flows step by step, the activity support matrix, known gaps and bugs (fix them when
touching the area; do not build on them), and the roadmap. The original designs are in
`docs/superpowers/` — check the plans before designing unfinished work (Lemmy plans 2–4).

## Layout

```
Events.php         every outbound trigger, UI hooks, display-name callbacks (808 lines)
config.php         19 event bindings, URL rules (order matters), console map
controllers/       public AP endpoints (Actor, Inbox, Outbox, Webfinger, Nodeinfo),
                   RemoteInteraction (/authorize_interaction), Settings (per user), Admin
jobs/              ProcessInboxActivity (auth + dispatch), DeliverActivity (fan-out),
                   DeliverToInbox (retryable POST), RefreshRemoteActor, RefreshRemotePost
services/          protocol logic — serializers, resolvers, signatures, policies, URL builder
models/            LocalActor, RemoteActor, FederationObject, FederationFollow, SpaceCommunity,
                   ActivityLogEntry, ConfigureForm (DeliveryLog is dead — nothing writes to it)
widgets/           RemoteAccountInfo (remote profile sidebar), RemotePostEngagement
commands/          ActivitypubController — status, ensure-instance-actor, prune-remote-actors,
                   reprocess-remote-posts, migrate-shadow-users
docs/superpowers/  design specs and implementation plans (historical, old module name)
```

Key services: `SignatureService` (draft-cavage sign/verify, proxy-tolerant candidates),
`ActivityDelivery` (signed POST), `RemoteActorResolver` (signed GET, shadow users, WebFinger with
re-validated redirects), `NoteSerializer` / `PersonSerializer`, `LocalObjectResolver` /
`RemoteObjectResolver` (AP id ↔ HumHub record via `activitypub_object`), `FederationContentPolicy`
and `DiscoveryPolicy` (what may federate / who is discoverable), `UrlSafety` and
`FederationHttpClient` (SSRF), `FederationUrl` (all federation URLs and the handle),
`CommunitySubscriptionService` + `GhostUser` (Lemmy Spaces).

## Invariants — do not break these

1. **Only guest-visible public content federates.** `FederationContentPolicy::mayFederate()` requires
   all of: global `enabled`, guest access on, content published and `VISIBILITY_PUBLIC`, container is
   a **User** (Spaces never federate posts outbound), user visibility `VISIBILITY_ALL`, and the
   per-user `federationEnabled` opt-in. Any new outbound content path goes through it.
2. **Discoverability is a separate gate.** `DiscoveryPolicy::isDiscoverable()` guards the actor,
   WebFinger, outbox and collections, and also outbound follows, likes and replies (via
   `Events::federatableActor()`): a remote can only verify a signature whose key it can fetch.
3. **Loop guard.** `federatableActor()` returns null for any user with a `RemoteActor` row. Every
   outbound path must use it, so interactions written by shadow users are never re-federated.
4. **Shadow users are created only after signature verification and only when something is stored**
   (accepted inbound Follow, reply to our content, like on a resolvable local object, explicit local
   follow). Never for strangers' posts, boosts, or Undo/Delete/Update/Accept from unknown actors.
   They use `FederatedUserSource` (`user_source='activitypub'`, `email=null`, needs core PR #8239),
   username `@user@domain`, `VISIBILITY_REGISTERED_ONLY`, `allowAssignDefaultGroup(false)`, the
   `remoteUserGroupId` group, identity in profile fields `activitypub_displayname` /
   `activitypub_handle`. Communities never get a shadow user.
5. **Inbound authentication order** (`ProcessInboxActivity::authenticate()`): `digest` must be a signed
   header and match the raw body → `Date` within 1 h past / 5 min future → fetch the key from
   `keyId` (signed GET) → verify against {received, canonical} host × path → **signer `id` must equal
   the activity `actor`**. Nothing is persisted before this passes.
6. **Never trust `X-Forwarded-*` for signatures.** Canonical host/path come from `FederationUrl`
   (the `baseUrl` setting must be the public URL). Behind proxies (BunkerWeb, FrankenPHP) the
   inbox fills `host`/`content-type`/`content-length` from `$_SERVER`.
7. **Every outbound URL is SSRF-checked**: `UrlSafety::isSafePublicUrl()` (https only, public IPs)
   and `FederationHttpClient` (no auto-redirect, timeouts — re-applied *after*
   `parent::beforeSend()`, which resets curl options). Redirects are followed manually and each hop
   is re-validated. Remote media: raster types only, ≤10 MB, ≤8 per post.
8. **Every GET to a remote is signed by the instance `Application` actor** (authorized-fetch servers
   answer 401 otherwise). Keep the instance actor and its key fetchable *without* a signature.
9. **Idempotency through `activitypub_object.ap_id` (unique).** Check the mapping before creating a
   comment, like or post; drop inbound objects without an `id`.
10. **Directed activities go to the personal inbox** (Accept, Follow, Undo, Like, replies to a remote
    author) — a shared inbox silently drops a directed Accept. Broadcasts (Create, Delete,
    Update{Person}) go to shared inboxes, de-duplicated.
11. **Authorship guards.** Inbound Delete/Update must come from the record's author;
    `Update{Person}` must update the signer itself.
12. **Never clobber a site's display-name callback.** `Events::onBeforeRequest()` captures the
    existing `displayNameCallback` / `displayNameSubCallback` and delegates to it for local users.
13. **Queue jobs have no identity.** Set `created_by` explicitly on Post, Content, Comment, Like and
    File; ingested posts use `silentContentCreation` and `autoFollow=false`. Build activity JSON in
    the web request (absolute URLs resolve there), then queue it.

## Conventions

- Events are bound in `config.php` and handled in `Events.php` (`onXxx`); inbox handlers in
  `ProcessInboxActivity` are `handleXxx`. Idempotent provisioning is `getOrCreateXxx`, lookups
  returning `?T` are `resolveXxx`. Services are instantiated with `new`; policies and URL builders
  are static.
- Activities are plain PHP arrays, encoded with
  `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. `@context` is
  `https://www.w3.org/ns/activitystreams` (+ `https://w3id.org/security/v1` on actors). Activity ids
  are random: `<actor>/<kind>/<bin2hex(random_bytes(8))>`. Note ids: `<actor>/notes/<contentId>`,
  comments `<actor>/comments/<commentId>`.
- Read remote JSON defensively: any property may be a string, an object or an array (see the skill).
- Logging: `Yii::warning('ActivityPub <area>: <message>', 'activitypub')`. Best-effort paths (media,
  refresh, activity log) catch and log, never propagate. `DeliverToInbox` throws on purpose for
  transient failures so the queue retries (`canRetry`: 3 attempts; 4xx is permanent).
- Comments are dense and explain *why*; most methods carry a one-line `/** … */`. Match that.
- Settings — module: `enabled`, `activityLogEnabled`, `remoteUserGroupId`, `ghostUserId`; per user
  container: `federationEnabled`. Cache keys are prefixed `activitypub.` (refresh 10 min dedupe,
  post stats 6 h, inbox rate limit 300/min per IP).
- Strings: `Yii::t('ActivitypubModule.base', …)`; `Html::encode` every remote string in views.
- CHANGELOG at `docs/CHANGELOG.md` under `0.1.0 (Unreleased)`, `- Enh:` / `- Fix:` bullets.

## Running the tests

```bash
cd protected/modules_cuzy/activitypub/tests
HUMHUB_PATH=/var/www/dev-2 php /var/www/dev-2/protected/vendor/bin/codecept build   # once
HUMHUB_PATH=/var/www/dev-2 php /var/www/dev-2/protected/vendor/bin/codecept run unit [SomeTest]
```

- Unit suite only. Tests extend `tests\codeception\_support\HumHubDbTestCase`, namespace
  `tests\codeception\unit\modules\activitypub`; fixture users 1–3 and Space 1.
- `tests/codeception/fixtures/ActivityPubFixture.php` empties the module tables before every test —
  core fixtures reset auto-increments, which implicitly commits the test transaction. Add any new
  table to its `TABLES` list.
- Call `$this->allowGuestAccess()` in tests that depend on discoverability or federation.
- Tests stay network-free: feed prebuilt documents to public helpers (`provisionOrJoin`,
  `acceptCommunityFollow`, serializers). `UrlSafetyTest::testAllowsPublicHttps` needs DNS.
- Not covered yet: `ProcessInboxActivity` end to end, `Events` outbound handlers, controllers,
  delivery retries, `FederatedHtml`.

## Running it for real

Federation needs a public HTTPS hostname that matches the `baseUrl` setting, guest access on, pretty
URLs (so `/.well-known/*` reaches HumHub), and a queue worker (`php protected/yii queue/listen`).
After changing `config.php` or event bindings: `php protected/yii cache/flush-all` and restart PHP
workers. Turn on "Log federation activity" in the module settings to see raw inbound/outbound JSON.
