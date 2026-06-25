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
- Enh: Profile sync — the actor document carries the user's avatar (`icon`),
  banner (`image`) and bio (`summary`), and a display-name or bio change is
  federated to remote followers as a signed `Update{Person}`, so Mastodon
  refreshes the cached profile.
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
- Enh: Federated users are first-class — a dedicated `fediverse` `UserSource`
  governs them (synced profile read-only, no self-delete, no email required),
  their username is their `@user@domain` handle, and they no longer carry a
  synthetic email. The `fediverse/migrate-shadow-users` console command brings
  existing federated users onto this model and backfills avatar/banner/bio.
  (Requires the core per-source optional-email change, HumHub PR #8239.)
- Enh: Federated identity lives in dedicated, protected profile fields — a new
  "Fediverse" category with `fediverse_displayname` and `fediverse_handle` —
  instead of being squeezed into first/last name; the display name and its
  `@user@domain` sub-line are rendered via `displayNameCallback`, so a remote
  user shows as their real name (not `name domain`) and local users are
  unaffected.
- Enh: The outbound delivery fan-out eager-loads followers' remote actors (one
  query instead of an N+1 per follower), so delivering to many followers no
  longer runs a query per follower.
- Enh: The actor `outbox` is a proper paged `OrderedCollection` — the collection
  advertises `first`/`last` and `?page=N` returns an `OrderedCollectionPage` of
  the user's `Create` activities (newest first) with `next`/`prev`; previously the
  page link was advertised but never served the items.
- Fix: Outbound actor/object/collection fetches are now HTTP-signed by the
  instance actor ("signed fetch"). Instances running in authorized-fetch / secure
  mode (e.g. `newsie.social`) reject unsigned GETs with 401, which previously made
  following or resolving those accounts fail; the same signed fetch also lets us
  retrieve signer keys from secure-mode instances when verifying inbound activities.
- Fix: The actor `following` and `followers` collections are now served as paged
  `OrderedCollection`s. The actor document advertised these URLs but they returned
  404, so remote servers (e.g. Mastodon) could not read the real counts and showed
  an incomplete number. They now report the true `totalItems` and list the
  federated accounts followed / followers; local-only HumHub follows are not
  included, as they have no Fediverse identity.
- Enh: Changing a local user's profile avatar or banner federates an
  `Update{Person}` to their remote followers immediately (previously only a name
  or bio change did), so Mastodon refreshes the cached image.
- Enh: A dedicated "Fediverse" entry in the user account navigation opens one
  page bundling all per-user options — your `@user@domain` handle, the publish
  opt-in, following a remote account by handle, and the list of accounts you
  follow (with unfollow). It replaces the two separate, unlinked pages.
- Enh: A federated (remote) user's profile now shows a "Fediverse profile" link
  in the profile menu (to their original account) and replaces the misleading
  local follower/friend counters with the account's real Fediverse
  follower/following counts. The counts (and the profile) are refreshed lazily
  in the background when the profile is viewed and the cached data is older than
  a day, so they stay reasonably current without any periodic job.
- Fix: Inbound activities no longer create a federated shadow user unless we
  actually store something for them — a follow we accept, a like or reply on our
  content, or a post from an account we follow. Boosts, likes/replies that don't
  attach, posts from strangers, and Undo/Delete/Update/Accept from unknown actors
  are processed without minting an empty user. A new
  `fediverse/prune-remote-actors` console command removes the orphan shadow users
  accumulated before this fix (no follow and no like/comment/post).
- Enh: A remote user's profile drops the meaningless local-graph sidebar panels
  (Spaces, Followers, Friends) in favour of a "Remote account" panel linking to
  the origin profile with the real follower/following counts, and hides the
  "Add Friend" button (a friend request to a remote account can't be accepted).
- Enh: Ingested remote posts keep their native like/comment (a local like or
  reply still federates back to the author) and additionally show a Fediverse
  engagement line — the post's real favourite/boost counts and a "View original"
  link to the source thread (where the replies live). Counts come from the Note
  document and refresh lazily in the background (6h) when the post is viewed.
- Enh: Remote-follow landing — following a local user from elsewhere in the
  Fediverse now works: WebFinger advertises the `subscribe` template and a new
  `/authorize_interaction` endpoint lets a logged-in user confirm and follow a
  remote account (by handle or profile URL) they discovered on another server.
- Fix: The "your profile must be discoverable" notice (shown when following a
  remote account or on the Fediverse settings page) now names the actual missing
  prerequisite — federation disabled, guest access disabled site-wide, or the
  user's own profile visibility — instead of always telling the user to make
  their profile "visible to all". The old wording was a dead end when guest
  access was off site-wide, because the "members+guests" visibility option is
  then not even offered.
- Fix: Federated text from remote servers (post bodies, comments, bios) keeps its
  paragraph/line breaks and decodes HTML entities (e.g. `&amp;` → `&`), instead of
  naive tag-stripping that ran paragraphs together and left entities visible.
- Enh: Outbound delivery is resilient — each activity is delivered per-inbox by a
  retryable job that retries transient failures (5xx / connection errors, up to 3
  attempts) and drops permanent ones (4xx/410), without re-delivering to inboxes
  that already succeeded. The inbound inbox has a per-IP rate-limit backstop.
- Enh: Federated users are collected in a group (new shadow users join
  automatically, existing ones are backfilled), so the People directory can later
  be filtered by local vs. remote. A default "Remote Users" group is created, and
  the target group is selectable in the Fediverse admin settings.
- Fix: A federated shadow user is no longer added to the site's default (local)
  user group. The core assigns the default group in `User::afterSave()` when a
  user has no group yet, so creation now suppresses it (`allowAssignDefaultGroup(false)`)
  and the user is placed only in the configured remote-users group.
- Enh: A trailing block of hashtags on an ingested remote post (the common
  Mastodon style, e.g. a final line of `#a #b #c`) is split off the body and
  turned into HumHub topics; hashtags used inline in a sentence are left in the
  text.
- Enh: An opt-in "federation activity log" (admin setting, off by default) records
  recent inbound/outbound activities (raw JSON + outcome) to a capped table, with
  an admin viewer — useful for debugging e.g. mis-parsed remote content.
- Enh: The Fediverse admin page shows basic statistics — local/remote actors,
  inbound/outbound follow counts, and federated vs. ingested post counts.
- Enh: The Fediverse settings page notes that liking or commenting on federated
  content is itself federated (delivered to the remote author and publicly
  visible there).
- Enh: Local users now also carry their canonical Fediverse handle
  (`@user@domain`) in the `fediverse_handle` profile field, kept in sync on
  creation and username change, so it can be shown as the display-name subtitle
  (Administration → Settings → Appearance → "User Display Name Subtitle" →
  "Fediverse Handle").
- Fix: The module no longer clobbers a `displayNameCallback` /
  `displayNameSubCallback` configured elsewhere (e.g. in `config.php`). It now
  preserves and delegates to it for non-federated users, while still rendering
  federated users from their Fediverse fields — so a site can customise the
  local display name (e.g. from a `display_name` profile field) and have it
  applied to local users and federated outbound (`Person.name`) alike.
- Enh: Mastodon custom emoji are handled — in an ingested post body the
  `:shortcode:` is rendered as the emoji image (from the activity's `tag`), and in
  federated display names (which can't show images) the shortcodes are stripped
  rather than shown raw.
- Enh: Codeception unit tests for the pure logic (URL/handle helpers, the
  visibility/discovery policies, HTTP-signature build/sign/verify, the
  Note/actor serializers, the AP-object resolver).
