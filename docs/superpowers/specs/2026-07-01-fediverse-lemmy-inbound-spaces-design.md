# Fediverse — Inbound Lemmy community federation for Spaces (design spec)

**Status:** Design — pending user review, then split into implementation plans.
**Date:** 2026-07-01

## Goal

Let HumHub users subscribe to remote Lemmy-family communities (Lemmy, Mbin/kbin,
PieFed, NodeBB — anything using the `Group` + `Page` + `Announce` model) and
participate in them from inside HumHub. Each subscribed community is mirrored
into a **shared, public HumHub Space**: the community's posts, comments and votes
stream in via the community's `Group` actor, and HumHub members can **create
posts, comment and upvote** — new posts and those interactions federate back to
the community, authored by the user's own `Person` actor. HumHub is the *client /
mirror* here; the community stays canonical on its home server.

This is the **inbound** half of Space federation. The outbound half (a HumHub
Space acting *as* a federated community that remote users subscribe to) is a
deliberate future step; several choices below (Space-as-`Group` actor,
polymorphic local actor) lay its groundwork.

## Decisions (agreed)

1. **Direction:** inbound first — HumHub as a subscriber/client of remote
   communities. (Outbound "Space as community" is a later, separate effort.)
2. **Container:** a **normal HumHub Space**, coupled to a remote community —
   *not* a new `RemoteCommunity` container type. Reuses Space wall/stream,
   membership, permissions, comments, likes, notifications.
3. **Write depth (MVP):** **read + comment + vote + new top-level posts**. The
   community stream is mirrored; HumHub members can comment and like/upvote
   ingested posts (→ `Note`/`Like`) **and create new top-level posts** in the
   Space (→ `Create{Page}`). Native post titles (core `develop`) make this clean:
   the admin sets the post module's global `titleMode` to at least `optional` (so
   the composer shows a title field), and the module **requires a title on posts
   in a federated community Space** (a Lemmy `Page` needs a `name`) — enforcing
   the title only where it matters, not site-wide, and with no fallback/heuristic.
4. **Entry point:** **self-service — any HumHub user can subscribe** to a
   community. A global-admin toggle can restrict *who* may create new
   federated-community Spaces (default: any user), as a wildwuchs safety valve.
5. **Ownership:** a single global, login-disabled **"Fediverse" ghost user** — an
   ordinary *local* HumHub user (it never federates, so it needs no shadow-user /
   `UserSource` machinery) whose id is stored in a Fediverse setting
   (`ghostUserId`) — owns/admins *every* auto-provisioned federated-community
   Space. Subscribers become plain **members**. This decouples Space ownership
   from whoever subscribed first and removes the owner-succession problem.
6. **Follower identity:** the **Space itself is a `Group` actor** and issues a
   single `Follow` to the community — one subscription, one `Announce` stream,
   regardless of how many HumHub members read the Space.

## Background — how Lemmy-family federation differs from the current module

The module today is entirely **Person-centric** (Mastodon microblogging): each
user is a `Person` actor, and delivery is per-follower to individual inboxes.

A Lemmy community is a **`Group` actor that acts as a relay**:

- **Posts** are `Page` objects (`name` = title, `content` = body HTML, optional
  `url` = link post, `attributedTo` = author `Person`, `audience`/`to` = the
  community). Not `Note`.
- **Comments** are `Note` with `inReplyTo` (a `Page` or another `Note`).
- **Votes** are `Like` (upvote) and `Dislike` (downvote); retraction via `Undo`.
- To publish, an author's server sends `Create{Page|Note}` (or `Like`) to the
  **community's inbox**. The community wraps it in an **`Announce`** and fans it
  out to all its followers (delivered to each subscribing instance's shared
  inbox). Following a community is done with a `Follow` to the community; it
  replies `Accept` and thereafter sends `Announce{…}`.

So the single genuinely new delivery mechanic is: **one `Follow` on a `Group`
actor replaces N `Person` follows, and everything arrives bundled as
`Announce{…}` on our inbox.** Ingest, shadow users, media, edit/delete,
interaction handling, signed delivery — all already exist and are reused.

## Architecture

### A. Subscription — Space as a `Group` actor

- The Space gets its own **local `Group` actor** (keypair + actor URL, RSA
  generated lazily as for user actors). It issues **one `Follow`** to the
  community inbox; Lemmy replies `Accept` and thereafter delivers `Announce{…}`.
- The actor URL is a **federation-facing AP endpoint** (Lemmy fetches it to verify
  our HTTP-signature key and find our inbox), served *in addition to* the Space's
  normal HumHub URLs — those are unchanged and remain what HumHub users see. Its
  exact form (e.g. `/fediverse/actor/space/<guid>`) is pinned in the Foundation
  plan; it is purely internal to federation.
- Rejected alternatives: follow per HumHub member (N follows, N duplicate
  streams) or via a hidden service actor (extra concept for no gain).
  Space-as-`Group` is one stream *and* is exactly the identity the future
  outbound direction needs.

### B. Ingest — unwrap `Announce`, route to the Space

`ProcessInboxActivity` gains an `Announce` branch: unwrap the inner activity,
resolve the target Space from `fediverse_space_community` keyed on the
**`Announce.actor`** (the community `Group` itself — the reliable routing key;
`audience`/`to` on the inner object is a fallback), and dispatch the inner
activity to the existing ingest paths with a **Space-target context**:

| `Announce` inner | → HumHub | Reuses |
|---|---|---|
| `Create{Page}` (title + body + optional `url`) | Space **Post** — `Page.name`→native `Post.title`, body→`message`, `url`→link | author → shadow user; media → `RemoteMediaFetcher` |
| `Create{Note, inReplyTo}` | comment on the mapped Post (or reply on mapped comment) | inbound-interaction service |
| `Like` / `Undo{Like}` | like / unlike on the mapped Post/Comment | inbound-interaction service |
| `Dislike` (downvote) | **ignored** (no HumHub equivalent) | — |
| `Update` / `Delete` (by author or mods) | text edit / soft-delete | inbound edit/delete service |

Each ingested object is recorded in `fediverse_object` (AP id ↔ Content,
`direction=remote`) for idempotency, echo-dedup and later edit/delete.

### C. Write-back — new posts, comments & votes from HumHub

All write-back is authored by the acting member's own **`Person` actor** and
delivered to the **community inbox** (`fediverse_space_community.inbox_url`) via
the existing `DeliverToInbox` job:

- **New top-level post** in the Space → `Create{Page, name: <title>, content:
  <body>, url?: <link>, attributedTo: <user>, to: [community], cc: [Public]}`. A
  `PageSerializer` builds the `Page` from the HumHub `Post`; a module validation
  requires the post to carry a `title` in a federated community Space. Editing or
  deleting one's own such post federates `Update`/`Delete{Page}` for symmetry
  (reuses the existing local edit/delete event path).
- **Comment** on an ingested Post/Comment → `Create{Note, inReplyTo: <post/comment
  ap_id>, attributedTo: <user>, to: [community], cc: [Public]}`.
- **Like** on an ingested Post/Comment → `Like{object: <ap_id>, to: [community]}`;
  unlike → `Undo{Like}`.
- **Echo dedup:** the community `Announce`s our own activity back to us; we
  discard it because its `ap_id` is already in `fediverse_object`.
- **Loop guard:** the existing `federatableActor()` check means shadow users
  (remote authors) never re-federate; only real local members' interactions go
  out. The "Fediverse" ghost never authors content and is additionally excluded.

### D. Container, provisioning & ownership

- **One Space per community**, keyed by the community actor id
  (`fediverse_space_community.community_ap_id` unique).
- Subscribing to a **not-yet-mirrored** community → **auto-provision a public
  Space** (name/description/avatar/banner from the `Group` actor's
  `name`/`summary`/`icon`/`image`), owned/admin'd by the **"Fediverse" ghost
  user**, Fediverse module enabled on the Space (per-container), and the `Follow`
  is sent. The subscribing user joins as a **member**.
- Subscribing to an **already-mirrored** community → **join the existing Space**
  (no second `Follow`, no duplicate).
- **Public-gating:** a coupled Space **must** be public/guest-visible — writing
  back publishes publicly on Lemmy, so this keeps the module's §5 "only
  guest-visible public content federates" invariant. Auto-provisioned Spaces are
  public by default; the module refuses to couple a non-public Space.
- **Global gates:** the module-wide `enabled` switch gates everything; an
  additional global-admin setting restricts *who* may create federated-community
  Spaces (default: any user).
- **Ghost user:** one global, login-disabled **local** user "Fediverse" — an
  ordinary local HumHub user, *not* a shadow user and needing no dedicated
  `user_source`/flag, because it never opts into federation and authors no
  content, so it never emits activities. Its id is stored in a Fediverse setting
  (`ghostUserId`): on module enable the module creates the user if the setting is
  unset, and an admin may point it at a chosen user instead. Human site-admins
  still moderate normally.

### E. Identity

- Remote **authors / commenters / voters** become the existing `fediverse`
  shadow users (`@user@domain` username, dedicated profile fields, avatar/banner
  via `RemoteMediaFetcher`) — **no new identity work**.
- The **community** `Group` itself has **no shadow user** (the
  `fediverse_remote_actor.user_id` column is `NOT NULL`), so its AP identity —
  `community_ap_id`, inbox, handle, name, summary, icon — is stored directly on
  the `fediverse_space_community` coupling row, *not* a `remote_actor` row. The
  Space's HumHub metadata (name/description/avatar/banner) is synced from there.
  Only member Persons who post/comment/vote become normal `remote_actor` +
  shadow users (later plans).

## Data model

Maximal reuse, minimal new tables:

- **New: `fediverse_space_community`** — the coupling and dedup key:
  `space_id`, `community_ap_id` (unique), `handle`, `inbox_url`,
  `shared_inbox_url`, `followers_url`, `follow_state` (pending/accepted),
  timestamps. Answers "is this Space federated?" and "which Space for community
  id X?".
- **`fediverse_local_actor` → container-generic (polymorphic).** Today per-User;
  a Space-as-`Group` is just a local actor of a different container type
  (keypair + actor URL). Generalize the model to any `ContentContainer`
  (User or Space) rather than adding a second actor table — this also cleanly
  seats the future outbound direction. (Chosen over a separate
  `fediverse_space_actor` table.)
- **`fediverse_remote_actor` — unchanged.** Its `type` column already exists (set
  to `Person` today; member Persons will just start carrying their real type). A
  community `Group` is **not** stored here — `remote_actor.user_id` is `NOT NULL`
  and a community has no shadow user, so the community's actor data lives on
  `fediverse_space_community` instead.
- **`fediverse_object` — unchanged.** AP id ↔ Content map carries posts,
  comments and likes as today (echo-dedup, edit/delete). The container is
  derivable from the Content.

## Components (isolated units)

`✎` = new, `↻` = extends existing:

- ✎ **`CommunityResolver`** — parse `!name@host` / `name@host` / a community URL
  → WebFinger → `Group` actor doc; distinguishes `Group` vs `Person`; SSRF-guarded
  (reuses `FederationHttpClient` + `UrlSafety`).
- ↻ **Actor serialization / model** → emit the Space `Group` actor doc + keypair
  (generalizes the existing `Person` actor output).
- ✎ **`CommunitySubscriptionService`** — subscribe (resolve → provision-or-join
  Space under the ghost owner → send `Follow`), unsubscribe (leave; when the last
  human member leaves → `Undo{Follow}` + archive the Space). Encapsulates the
  dedup rule.
- ↻ **`ProcessInboxActivity`** — `Announce` branch: unwrap, resolve target Space
  via `fediverse_space_community`, dispatch inner activity to existing ingest
  paths with a Space-target context.
- ✎ **`PageIngestService`** — `Page` → Space `Post` (`name`→title + body + link +
  media).
- ✎ **`PageSerializer`** — HumHub `Post` → Lemmy-compatible `Page` (`name` from
  title, `content` from body, `url` from a link) for write-back `Create`/`Update`/
  `Delete{Page}`; plus the module validation requiring a title on posts in a
  federated community Space.
- ↻ **Inbound interaction / edit-delete services** — accept a Space container
  target instead of assuming the dashboard/profile.
- ↻ **`Events` (write-back)** — new post / comment / like (and their edit / delete
  / undo) in a coupled Space → build `Create{Page}` / `Create{Note}` / `Like` /
  `Undo` (+ `Update`/`Delete`) to the community inbox via `DeliverToInbox`;
  loop-guard + echo-dedup.
- ✎ **Controller / UI** — a "subscribe to a community" entry (enter/search a
  handle) and a list of subscribed community Spaces.

The bulk is reuse; the genuinely new core is `CommunityResolver`,
`PageIngestService`, the `fediverse_space_community` coupling, the Space `Group`
actor, and the ghost user.

## Known limits (deliberate, documented in the MVP)

- **Echo dedup:** our write-back returns as an `Announce`; dropped by `ap_id`.
- **Downvotes** (`Dislike`) are ignored (no HumHub equivalent); **vote scores**
  from Lemmy aren't reliably reconstructable from the `Announce` stream — we show
  only HumHub's own like count.
- **Deep comment trees** flatten onto HumHub's comment / one-level-reply model.
- **Backfill:** a subscription only delivers *new* content from the subscription
  point onward — the Space starts empty and fills forward. Historical backfill
  (community `outbox` crawl) is **out of scope for the MVP**, an optional
  follow-up.
- **Interop target:** the Lemmy family (Lemmy, Mbin/kbin, PieFed, NodeBB). Mastodon's
  `Group` support is rudimentary and is not a target.
- **Link posts:** a `Page.url` with little/no body → render the link prominently.
- **Leaving:** when the last human member leaves, send `Undo{Follow}` and archive
  the Space (the ghost stays owner; the stream stops).
- **Moderation:** remote mod `Remove`/`Delete` → local soft-delete; HumHub-side
  moderation is **not** pushed to Lemmy in the MVP.

## Testing

- **Codeception unit** (existing harness): `CommunityResolver` (handle parsing
  `!`/`@`/URL, `Group` vs `Person`), `Announce` routing → Space, `Page` → Post
  mapping (title/body/link/media), `Post` → `Page` serialization, title-required
  validation in a federated Space, write-back activity building
  (`Create{Page}`/`Note`/`Like`), echo-dedup + loop-guard, non-public-Space
  refusal, ghost-owner provisioning + dedup (second subscriber joins, no
  duplicate).
- **Live** against a real Lemmy instance from `test.fedihub.social`; test steps
  split by actor (Admin: / member: / remote Lemmy user:).

## Rough plan split (for the writing-plans phase)

1. **Foundation** — `fediverse_local_actor` polymorphic + Space `Group` actor;
   `fediverse_space_community` table; `fediverse_remote_actor.type`;
   `CommunityResolver`; the "Fediverse" ghost user; `CommunitySubscriptionService`
   (provision-or-join + `Follow`/`Accept`). Handshake only.
2. **Read ingest** — `Announce` routing + `PageIngestService`
   (`Create{Page}`→Post), `Create{Note}`→comment, `Like`/`Undo`,
   `Update`/`Delete`. The read-only mirror works end to end.
3. **Write-back** — new posts (`Create{Page}` via `PageSerializer` + the
   title-required validation), comments and likes from HumHub → community inbox;
   own-post `Update`/`Delete{Page}`; echo-dedup; loop-guard.
4. **Polish / UX** — subscribe UI, unsubscribe/archive, media, moderation
   reflection, surfaced limits, `docs/CHANGELOG.md` + `module.json` version bump
   (new minor, likely `0.2.0`).
