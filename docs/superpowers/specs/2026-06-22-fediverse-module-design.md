# Fediverse Module — Design Spec

- **Date:** 2026-06-22
- **Status:** Draft for review
- **Module:** `fediverse` (HumHub module)

## 1. Summary

A HumHub module that makes a HumHub installation a first-class participant in
the Fediverse via **ActivityPub**. The long-term goal is **bidirectional**
federation; the build is incremental, with **maximum Mastodon compatibility
first**. HumHub users become followable `Person` actors that can publish to and
interact with Mastodon, Misskey, and other ActivityPub servers. Later phases add
group/community federation (Lemmy and friends) and federation of module content.

## 2. Goals & non-goals

**Goals**
- HumHub users are followable ActivityPub `Person` actors.
- Outbound + inbound federation of posts, comments, likes, and attachments.
- Strict, privacy-safe gating: only content already readable by anonymous guests
  is ever federated.
- A lean, self-contained protocol implementation tuned for real-world Mastodon
  behaviour.

**Non-goals (initially)**
- Full JSON-LD processing (we treat ActivityPub as plain JSON with a fixed
  `@context`, as Mastodon effectively does).
- Group/community (Lemmy) federation — deferred to Phase 3.
- Federation of arbitrary module content (wiki, calendar, polls) — Phase 4.
- Multi-domain / multi-tenant host mapping (one federation domain per install).
- Acting as a Mastodon Client API or OAuth provider (letting existing Mastodon
  apps use a HumHub account as their backend) — far-future at best; see §11.

## 3. Phasing roadmap

- **Phase 1 — Mastodon compatibility (MVP):** Users ↔ `Person` actors. Outbound +
  inbound for posts (`Note`), comments (`Note` + `inReplyTo`), likes
  (`Like`/`Undo`), attachments (`Image`/`Document`). Follow lifecycle
  (`Follow`/`Accept`/`Undo`). WebFinger, instance actor, HTTP signatures,
  NodeInfo. Remote persons represented as federated `User` records; their posts
  surface via HumHub's existing follow → dashboard mechanic. Basic instance
  blocklist.
- **Phase 2 — Hardening & reach:** Share/boost (`Announce`), profile sync
  (avatar/bio), remote-account search & mentions, moderation (instance
  allow/blocklist UI, reports).
- **Phase 3 — Groups / Lemmy:** New `RemoteCommunity` ContentContainer type;
  Spaces as `Group` actors; Lemmy semantics (`Page` with title); community
  routing.
- **Phase 4 — Module content:** Wiki / calendar / polls etc. as native AP
  objects or `Note` fallback.

## 4. Actor model

- **HumHub User → `Person`** actor — `@handle@domain`, followable like a Mastodon
  account.
- **HumHub Space → `Group`** actor — Lemmy-style community. *(Phase 3.)*
- **Site → `Application`** actor — the instance actor: shared inbox and the
  signing identity for authorized server-to-server fetches.

## 5. Visibility & federation control

Federation of a content item is permitted **only if all** of the following hold:

1. **Instance:** Guest Mode is enabled **and** the global federation switch is on
   (admin).
2. **ContentContainer** (Space or Profile): visibility = **"Visible for all
   (members & guests)"** **and** federation opt-in is enabled for that container.
3. **Content item:** visibility = **Public**.
4. → The item is federated addressed to `as:Public`. Anything failing the chain
   stays strictly internal.

Rationale: only content already readable by anonymous guests (no login) is ever
federated, so federation can never widen access beyond what is already public.
Control model is **explicit opt-in** layered under a **global admin switch**;
federation is off by default.

Visibility mapping:

| HumHub visibility | Federation |
|---|---|
| Public (guest-visible container + public item) | federated as `as:Public` |
| Protected / members-only | not federated (followers-only is a deliberate non-goal for now — risky) |
| Private | never federated |

### Inbound visibility

The gating applies on the way in too. In Phase 1 we ingest **only** activities
addressed to `as:Public`. Followers-only and direct (DM) activities are dropped,
not stored — even when our instance legitimately receives them as an approved
follower — because HumHub's container-based visibility cannot safely express
"visible to this one local follower only". Correct per-recipient visibility for
inbound private posts is a deliberate later feature, not Phase 1.

## 6. Content type mapping

| HumHub | ActivityPub object | Activities | Phase |
|---|---|---|---|
| Post (wall stream entry) | `Note` (Mastodon) / `Page` w/ title (Lemmy, later) | `Create`/`Update`/`Delete` | 1 |
| Comment | `Note` + `inReplyTo` | `Create`/`Delete` | 1 |
| Like / reaction | — | `Like`/`Undo` | 1 |
| File/image attachment | `attachment`: `Image`/`Document` | (part of Create) | 1 |
| Share | — | `Announce`/`Undo` | 2 |
| Module content (wiki, calendar, polls, …) | `Article`/`Event`/`Question`/… or `Note` fallback | various | 4 |

Note: AP has no native multi-reaction concept; all HumHub reactions map to `Like`.

## 7. Remote actor representation

Every remote actor needs a local "shadow" record, because HumHub attributes all
authorship via foreign keys to `user.id` (`Content.created_by`,
`Comment.created_by`, `Like.created_by`). Without a local record there is nothing
to attribute or render a remote comment/like to.

- **Remote Person → federated `User`** — login disabled, flagged remote, profile
  data synced (avatar, display name, handle), excluded from the People directory.
  A user profile *is* a ContentContainer, so the remote person's mirrored posts
  live in their profile, and HumHub's existing follow → dashboard mechanic
  surfaces them to followers with no custom stream code.
- **Remote Group → `RemoteCommunity` ContentContainer type** — a purpose-built
  container type alongside Space & User. *(Phase 3.)* Chosen over reusing `Space`
  for cleaner semantics, accepting the higher core-integration cost (URL routing,
  permissions, module manager, `instanceof Space` assumptions in other modules)
  because it is deferred and can be done deliberately.

Inbound placement is **hybrid**: remote-person follows surface in the follower's
dashboard; remote-group content lands in the linked community container (Phase 3).

### Data distribution, backfill & retention

ActivityPub has no central store and no completeness guarantee. An instance only
accumulates remote content that reaches it via: its members' **follows** (push of
future public posts), **boosts** by followed accounts, **opportunistic fetches**
(e.g. resolving `inReplyTo` thread context, or a searched/pasted remote URL), and
optionally **relays** it subscribes to. Private posts never reach an instance
whose users are not approved followers.

For this module that means our storage is **bounded by what our local users
follow**, plus touched thread context — we never mirror the whole Fediverse. Two
operational policies follow:

- **Backfill on follow:** kept minimal (content from the follow point forward, as
  Mastodon does); no full-history import.
- **Retention / pruning:** remote content for actors no longer followed by anyone
  local is pruned on a schedule, keeping local storage predictable. Any
  deliberate bulk ingestion (e.g. for load testing) should be an explicit
  relay/backfill switch, never a side-effect of missing cleanup.

## 8. Architecture & components

The module has an **outbound pipeline**, an **inbound pipeline**, and **shared
services**. All network operations run asynchronously through HumHub's queue
(`yii2-queue`) with retries.

**Outbound**
- **Event listeners** — hook HumHub `Content`/`Comment`/`Like` create/update/
  delete events; apply the §5 invariant; enqueue on pass.
- **AP serializer** — turn the HumHub model into an activity JSON (`Create{Note}`,
  `Like`, …) and record the ID mapping.
- **Delivery job** — signed `POST` to each accepted follower's inbox (shared
  inbox when available), with retries.

**Inbound**
- **Inbox controller** — accepts incoming activities, responds `202` immediately,
  enqueues.
- **Inbox processor job** — verify HTTP signature → resolve remote actor → map
  the activity to a HumHub action.
- **Remote actor resolver** — fetch + create/update the federated `User`, cache
  actor metadata.

**Shared services**
- **Key manager** — RSA keypair per actor + the instance actor.
- **Signature service** — HTTP Signatures sign/verify (small vetted crypto lib).
- **ID/object mapping** — AP URI ↔ HumHub record.

**HTTP endpoints**
- `.well-known/webfinger` — handle → actor.
- `/actor` (Person/Group) — `publicKey`, `inbox`, `outbox`.
- `/inbox` + shared `/inbox`.
- `/outbox`.
- `nodeinfo` — instance metadata.

**Protocol approach:** lean, self-built core. ActivityPub objects/activities are
plain JSON with a fixed `@context` (no full JSON-LD processor); only HTTP
Signatures use an external vetted crypto library. Mastodon compatibility comes
from reproducing Mastodon's concrete wire behaviour, which is most reliable with
a thin, fully-controlled core.

## 9. Data model

Guiding principle: **do not alter HumHub core tables.** Map everything via the
module's own tables and reuse existing `Content`/`Comment`/`Like`/`User`/follow
structures.

New tables (Phase 1):

- **`fediverse_local_actor`** — per federatable user (and the instance actor):
  `user_id` (nullable for instance), `type` (Person/Application),
  `private_key`, `public_key`, `preferred_username`, `ap_id` (URL),
  `federation_enabled`, counters. Key management & identity without touching the
  `user` table.
- **`fediverse_remote_actor`** — the shadow record: `user_id` (→ federated user),
  `ap_id` (unique), `type`, `handle` (`user@host`), `domain`, `inbox_url`,
  `shared_inbox_url`, `public_key`, `icon_url`, `last_refreshed_at`. The presence
  of a row means "this user is federated" — used to hide them from the People
  directory without a core schema change.
- **`fediverse_object`** — the central mapping: `ap_id` (unique), `direction`
  (local/remote), `object_type`, `model_class` + `model_id` (polymorphic link to
  Content/Comment/Like), `in_reply_to_ap_id`, optional `raw_json`. Provides:
  outbound URL construction, **idempotency** of inbound activities,
  `inReplyTo` resolution, and Update/Delete handling.
- **`fediverse_follow`** — AP follow state: `local_user_id`, `remote_actor_id`,
  `direction` (inbound/outbound), `state` (pending/accepted), `activity_ap_id`.
  Complements HumHub's native follow (which drives dashboard surfacing) with the
  protocol handshake.
- **`fediverse_delivery_log`** *(optional)* — per (activity, target inbox, status)
  for observability and retry insight.

Reused: `Content`/`Comment`/`Like` (with `created_by` = federated user for remote
authors), HumHub follow for dashboard surfacing, module settings (global switch)
+ `ContentContainerSettingManager` (per-container opt-in), `File` for attachments.

## 10. Federation flows (Phase 1)

**A — Outbound (local user posts)**
1. User creates a post → `Content created` event.
2. Listener runs the §5 invariant check; stop if it fails.
3. Queue: serializer builds `Create{Note}`, ID mapping recorded.
4. Delivery job: signed `POST` to each accepted follower's inbox (retries).
5. Post appears in followers' timelines.

**B — Inbound (remote user comments/likes our post)**
1. Remote sends `Create{Note, inReplyTo}` or `Like` to our inbox (non-`as:Public`
   activities are dropped per §5).
2. Inbox controller responds `202`, enqueues.
3. Queue: verify signature (fetch/cache remote actor public key).
4. Resolve remote actor → create/update the federated `User`.
5. Resolve `inReplyTo` → local content; create comment/like with
   `created_by` = federated user.
6. Comment/like appears natively on the post.

**C — Local user follows a Mastodon account**
1. Search `@bob@mastodon.social` (WebFinger) → federated user created, "Follow"
   clicked.
2. Queue: signed `Follow` activity to Bob's inbox.
3. Remote returns `Accept{Follow}` → follow state = accepted.
4. Remote thereafter delivers Bob's posts to our inbox.
5. Posts become content in the federated user's profile → appear in the
   follower's dashboard.

## 11. Security & identity

### Authentication layers

Two independent layers — not to be conflated:

- **Server-to-server (federation):** HTTP Signatures, **not OAuth**. Each server
  signs requests with its actor's private key; the peer verifies via the actor's
  published public key. Authorized-fetch ("secure mode") signs outbound `GET`s
  with the **instance actor** — still HTTP Signatures, at the instance level, used
  only to refuse blocked instances. There is **no per-user OAuth across servers**.
- **Client-to-home-server (Mastodon Client API):** OAuth 2.0 bearer tokens — how
  an app authenticates to *its own* home server's REST API. This is **not** part
  of federation; acting as such an OAuth provider / Client API is a non-goal (§2).

Consequently, viewing a remote profile never logs you into the remote server —
servers share no sessions. Your home server acts on your behalf and federates the
signed activity. The same holds for private posts: access is enforced by
**selective delivery + the receiving server honouring the audience** (trust-based,
not cryptographic), never by OAuth.

### Signatures & keys

- **HTTP Signatures (S2S):** outbound `POST`s signed with the actor's private key
  (draft-cavage, like Mastodon: `(request-target)`, `host`, `date`, `digest`);
  the `Digest` header secures the body. Inbound requests verified by fetching
  (and caching) the remote actor's public key. **Verify that the activity
  `actor`/`attributedTo` matches the signing key's identity and domain** — else
  reject (prevents spoofing / cross-origin object injection).
- **Instance actor & authorized fetch:** a dedicated `Application` actor signs
  outbound `GET`s (actor/object fetches), required by Mastodon "secure mode" and
  avoiding key-fetch deadlocks.
- **Key management:** RSA-2048 keypair per local actor, generated on enabling
  federation, stored in `fediverse_local_actor`; private keys encrypted at rest
  (app secret). Keys are never federated (only the public key in the actor doc).
- **WebFinger & handle:** format `@handle@domain`. AP `preferredUsername` is
  effectively `[A-Za-z0-9_]`, while HumHub usernames are freer, so we derive a
  **normalized, unique** handle (default from username, collisions resolved by
  suffix) stored in `fediverse_local_actor.preferred_username`; WebFinger resolves
  `acct:handle@domain` → actor URL.
- **Domain policy & hardening:** admin instance **blocklist** (basics in Phase 1,
  full UI in Phase 2); **SSRF protection** on remote fetches (no internal
  IPs/hosts); body size limits; inbox rate limiting; **idempotency** via
  `fediverse_object` (dedupe by `ap_id`); incoming JSON validated/normalized.

## 12. Error handling & delivery semantics

- **Delivery:** queue jobs with bounded retries + exponential backoff. `4xx`
  (except 429) = permanent, stop; `410 Gone`/`404` → mark follower/actor dead,
  optionally remove; `5xx`/`429` → retry. Use the shared inbox when available.
- **Inbox:** always respond `202` quickly; do work in the job; processing errors
  are logged, never reflected to the remote (no info leak). Unknown activity
  types are ignored cleanly (debug log).
- **Follow handshake:** inbound `Follow` auto-`Accept`ed in Phase 1 (content is
  public anyway), `Accept` returned. Handle `Undo{Follow}`, `Reject`,
  `Undo{Like}`, `Delete`. Missing `inReplyTo` target tolerated (drop or store as
  orphan). Tolerance window for `Date` header (clock skew).

## 13. Testing strategy

HumHub uses Codeception.

- **Unit:** AP serialization against **golden JSON fixtures** in Mastodon wire
  format; signature sign/verify; the §5 invariant gating logic; handle
  normalization.
- **Integration:** inbox controller end-to-end with **signed real payloads**
  (recorded Mastodon/Misskey activities as fixtures); queue job processing;
  WebFinger.
- **Interop:** test plan against a real (dockerized) Mastodon instance —
  follow / post / reply / like round-trips. This is the actual proof of "Mastodon
  compatibility."

## 14. Assumptions & open questions

These are defaults chosen during design; flag any to revisit.

- Federation requires Guest Mode to be enabled (it gates what is guest-visible).
- Handle is auto-derived from the username (normalized, unique); user-editable
  handle is a later enhancement.
- Inbound follows are auto-accepted in Phase 1.
- Private keys are encrypted at rest using the application secret.
- One federation domain per installation (no multi-host mapping).
- Spec language is English to match HumHub repo conventions.
- Minimum HumHub core version: **TBD** — pin once the required event/queue/
  ContentContainer APIs are confirmed against the target core release.

## 15. Glossary

- **Actor** — an ActivityPub entity that can act and be followed (Person, Group,
  Application).
- **Instance actor** — a server-level `Application` actor used for signed fetches
  and the shared inbox.
- **Federated user** — a local HumHub `User` record standing in for a remote
  person, login disabled, hidden from directories.
- **Invariant (§5)** — the all-of conditions under which a content item may be
  federated.
