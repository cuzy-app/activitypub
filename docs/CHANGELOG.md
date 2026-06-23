# CHANGELOG

## 0.1.0 (Unreleased)

- Enh: Module foundation — module shell, the five `fediverse_*` tables
  (local/remote actors, AP-object↔record map, follow handshake, delivery log),
  ActiveRecord models, and a global federation on/off admin setting.
- Enh: Identity & discovery — every guest-visible profile is a followable
  `Person` actor (GUID-based URL) plus an instance `Application` actor; WebFinger,
  Actor and NodeInfo endpoints; lazily-generated RSA keypairs.
- Enh: Inbound core — signed inbox (`/fediverse/inbox` + per-actor), HTTP
  Signature verification (digest required, key fetched from `keyId`, actor
  binding, freshness window, SSRF-guarded fetches), remote actors resolved into
  login-disabled "federated" shadow users, and `Follow` → auto-`Accept`.
- Enh: Outbound posts — per-profile opt-in and the visibility invariant (only
  guest-visible public content federates); `Create`/`Delete{Note}` with image/
  file attachments; queued fan-out delivery to followers; `outbox` endpoint.
- Enh: Inbound interactions — remote replies (`Create{Note, inReplyTo}`) become
  native comments and remote `Like`/`Undo{Like}` become native likes, authored
  by the federated shadow user (idempotent).
- Enh: Local → remote — follow a Fediverse account by `@user@domain` (WebFinger),
  outbound signed `Follow`/`Undo` with `Accept` handling, and ingest of the
  followed account's public posts into the follower's dashboard.
- Enh: Outbound interactions — liking or commenting on a remote account's
  ingested post sends a signed `Like` / `Create{Note, inReplyTo}` back to the
  author (and `Undo{Like}` on unlike); inbound interactions are loop-guarded so
  they are never re-federated.
- Enh: Profile sync — the actor document carries the user's avatar (`icon`) and
  bio (`summary`), and a display-name or bio change is federated to remote
  followers as a signed `Update{Person}`, so Mastodon refreshes the cached profile.
- Enh: Threading — a local comment on a local federated post is delivered as a
  `Create{Note, inReplyTo}` to the post author's remote followers (signed by the
  commenter), so replies stay threaded on Mastodon.
- Enh: Comment-level federation — local likes/replies on remote comments are sent
  to the comment's author, and remote likes on local comments are recorded on the
  comment (not the post).
- Fix: Federated content (posts, comments, bios) is rendered to clean HTML —
  Markdown becomes real markup (bold/links) and emoji become Unicode — instead of
  raw markdown wrapped in editor-widget HTML that remote servers showed verbatim.
- Enh: Inbound edits & deletes — when a remote account deletes or edits a post or
  comment we ingested, it is reflected locally (soft-delete or updated text),
  authorized to the content's author.
- Enh: Remote media — remote avatars and headers are downloaded into the shadow
  user's profile and banner image, images attached to a remote post become native
  file attachments, and an inbound `Update{Person}` refreshes a remote user's
  name, bio, avatar and banner. Downloads are SSRF-guarded, HTTPS-only, restricted
  to raster image types, and size-capped.
- Enh: Codeception unit tests for the pure logic (URL/handle helpers, the
  visibility/discovery policies, HTTP-signature build/sign/verify, the
  Note/actor serializers, the AP-object resolver).
