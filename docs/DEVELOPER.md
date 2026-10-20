# ActivityPub module: developer guide

Endpoints, data flows, the activity support matrix, known gaps and the roadmap of this
module. State as of October 2026 (version 0.1.0, unreleased); verify against the code
before relying on details. Protocol background (what Mastodon and Lemmy expect, HTTP
Signatures, FEPs) lives in the [`activitypub-engineering`](https://github.com/cuzy-app/activitypub-engineering-skills) skill, HumHub module conventions
in the `humhub-engineering` skill.

## Endpoints (`config.php` URL rules — order matters)

| Route | Handler | Notes |
|---|---|---|
| `GET /.well-known/webfinger?resource=acct:…` | `WebfingerController` | Instance actor or a discoverable user (by `LocalActor.preferred_username`, then raw username). Creates actor + keys lazily. Links: `self`, profile page, `http://ostatus.org/schema/1.0/subscribe` → `/authorize_interaction?uri={uri}`. |
| `GET /.well-known/nodeinfo`, `/nodeinfo/2.1` | `NodeinfoController` | Software `humhub-activitypub`, version from `module.json`. Usage hard-coded to 0. |
| `GET /activitypub/instance-actor` | `ActorController::actionInstance` | `Application` actor; only when federation is enabled. |
| `GET /activitypub/actor/space/<guid>` | `ActorController::actionSpace` | `Group` actor of a coupled community Space. Must precede the generic actor rule. |
| `GET /activitypub/actor/<guid>` | `ActorController::actionIndex` | `Person`; 404 unless discoverable. |
| `GET /activitypub/actor/<guid>/followers`, `/following` | `ActorController` | Paged `OrderedCollection`, 50/page, federated follows only. |
| `GET /activitypub/actor/<guid>/outbox` | `OutboxController` | Paged, 20/page, `Create` items referencing Notes by id. |
| `POST /activitypub/inbox`, `/activitypub/actor/space/<guid>/inbox` | `InboxController::actionShared` | Shared inbox. |
| `POST /activitypub/actor/<guid>/inbox` | `InboxController::actionUser` | Personal inbox; same processing. |
| `/authorize_interaction?uri=` | `RemoteInteractionController` | Login required; confirm remote follow / join community. |
| `/activitypub/settings[/publish,/follow,/unfollow]` | `SettingsController` | Per-user page. |
| `/activitypub/admin/index`, `/admin/activity-log` | `AdminController` | Global settings, stats, debug log viewer. |

Public endpoints: `$access = null`, `enableSession = false`; inbox also disables CSRF. JSON is
written raw with `application/activity+json` or `application/jrd+json`.

## Inbound flow

1. `InboxController::accept()`: 400 if federation is off; per-IP rate limit (300/min, then 429);
   400 without body or `Signature`; captures all headers lowercased (fills host/content-type/
   content-length from `$_SERVER`); queues `ProcessInboxActivity{rawBody, headers, requestPath,
   canonicalPath, targetGuid}`; answers **202**.
2. `ProcessInboxActivity::run()`: drops malformed JSON or missing `type`/`actor`.
3. `authenticate()` — nothing persisted yet:
   1. parse `Signature` (`keyId`, `headers`, `signature` required);
   2. `digest` must be signed and match SHA-256 of the raw body;
   3. `Date` ≤ 1 h old, ≤ 5 min in the future;
   4. fetch signer document from `keyId` minus fragment (signed GET as instance actor);
   5. verify against every candidate signing string: {received, canonical} path × {received Host,
      `FederationUrl::domain()`};
   6. signer `id` must equal activity `actor`.
4. Dispatch (`switch` in `run()`):
   - **Follow** → target must be a `LocalActor` with `user_id`. Persist actor, upsert inbound follow
     as accepted, queue `Accept{Follow}` to the follower's **personal** inbox.
   - **Undo** (known actor only) → `Undo{Follow}` deletes the inbound follow; `Undo{Like}` removes
     the like. Other inner types ignored.
   - **Create{Note}** →
     - no `inReplyTo`: ingested as a `Post` on the shadow user's profile **only if** the RemoteActor
       exists and an outbound follow to it exists (`ingestRemotePost`). Trailing hashtags → Topics,
       custom emoji → images, images → Files, REMOTE mapping.
     - `inReplyTo` one of our LOCAL objects → comment on that content (replies to comments flatten
       onto the post).
     - author replying in their own ingested thread → comment on the root. Third-party replies in
       remote threads are dropped.
   - **Like** → only if the object resolves to a local Post/Comment with a LOCAL mapping.
   - **Accept** → `acceptCommunityFollow(signer)` first; otherwise every pending outbound follow to
     that actor is accepted (not matched by Follow id). `object` may be a string or an embedded Follow.
   - **Delete** → known actor, target is a REMOTE record authored by them → soft-delete + unmap.
   - **Update** → actor types refresh the shadow profile (`object.id` must equal the actor);
     `Update{Note}` replaces message text only, author-guarded.
   - anything else → `Yii::info`, ignored (Announce, Reject, Dislike, Move, Block, Flag…).
5. `ActivityLog::record('in', …)` for every authenticated activity; failed auth logs `rejected`.

## Outbound flow (all triggers in `Events.php`)

| Trigger | Activity | Delivery |
|---|---|---|
| `Content::changedState` → published Post passing `mayFederate` | `Create{Note}`, `to: Public`, `cc: followers`, attachments as Image/Document | `DeliverActivity` (followers, shared inboxes) |
| `Post::afterSoftDelete` with LOCAL mapping | `Delete{Tombstone}` | `DeliverActivity` |
| `Follow::followCreated` on a shadow user | `Follow` (outbound follow row pending, id `<actor>/follows/<hex>`) | `DeliverToInbox`, personal inbox |
| `Follow::followRemoved` | `Undo{Follow}` (embedded) | `DeliverToInbox` |
| `Like::afterInsert` on remote content | `Like` (LOCAL Like mapping) | `DeliverToInbox` to the author |
| `Like::beforeDelete` | `Undo{Like}` | `DeliverToInbox` |
| `Comment::afterInsert` on remote post/comment | `Create{Note, inReplyTo}` | `DeliverToInbox` to the author |
| `Comment::afterInsert` (top-level) on a local federated post | `Create{Note, inReplyTo}` signed by the commenter | `DeliverActivity(localUserId = post author, signerUserId = commenter)` |
| `Profile::afterUpdate` (name/bio) and `ImageController::afterAction` (avatar/banner) | `Update{Person}` | `DeliverActivity`, only with accepted followers |
| Lemmy subscribe (`CommunitySubscriptionService::provisionOrJoin`) | `Follow` signed by the Space `Group` actor | `DeliverToInbox(signerActorId)` |

`DeliverToInbox::run()`: UrlSafety → `ActivityDelivery::deliver()` (cavage, `(request-target) host date
digest`, keyId `<ap_id>#main-key`). 2xx → delivered; 4xx (incl. 410, 429) → permanent, no retry;
5xx/transport error → throws, queue retries (3 attempts, no custom backoff).

## Lemmy community Spaces (foundation only)

Subscribe (settings form or landing, doc `type == Group`) → `provisionOrJoin()`: existing coupling →
`addMember`; otherwise in one transaction: `GhostUser::getOrCreate()`, public Space owned by the ghost
(visibility rule skipped so non-admins can subscribe), enable module, `SpaceCommunity` row (pending),
add subscriber. After commit, `Follow` from the Space `Group` actor. `Accept` from the community flips
`follow_state`. Plans for the rest: `docs/superpowers/plans/2026-07-01-fediverse-lemmy-space-*.md`.

## Activity support matrix

| Type | Inbound | Outbound |
|---|---|---|
| Follow | to a local Person; auto-accept | user → remote Person; Space Group → community |
| Accept | Follow accepted (user follows, community coupling) | reply to inbound Follow |
| Reject | ignored | never |
| Undo | Follow, Like | Follow, Like |
| Create{Note} | followed author's posts; replies to our content; author self-replies | posts, replies to remote, comments on local federated posts |
| Create{Page/Article/Question} | ignored | never |
| Like | on local Post/Comment | on remote post/comment |
| Dislike | ignored | never |
| Announce | **ignored** (no boosts, no Lemmy relay) | never |
| Update{Note} | text only, author-guarded | **never** — post/comment edits don't federate |
| Update{actor} | refreshes shadow profile | `Update{Person}` |
| Delete | ingested posts/comments; actor deletes ignored | post soft-delete; **comment deletes not sent** |
| Mentions / hashtags / CW / emoji | emoji → images, trailing hashtags → Topics | **none** |

## Known gaps and bugs (verified in code)

Fix these when touching the area; don't build on top of them.

1. **No `as:Public` check inbound.** Followers-only posts and DMs from followed accounts are ingested
   as public Posts/comments. Privacy issue; spec §5 required the check.
2. `refreshRemoteActor()` (inbound `Update{Person}`) writes `firstname`/`lastname` instead of
   `activitypub_displayname` and doesn't strip emoji; the lazy `RemoteActorResolver::refresh()` uses
   the current model.
3. Remote `profile_url` and Note `url` are used as link hrefs without an http(s) scheme check.
4. Our Note, comment and activity ids are not dereferenceable (no route) — remotes fetching them get 404.
5. `Reject` is unhandled; `rejected` follow state is never set.
6. `Accept` accepts every pending follow to that actor without matching the Follow id.
7. `RemoteInteractionController` creates the shadow user on GET (viewing the confirm page).
8. Community provisioning doesn't check the global `enabled` switch.
9. 429 is treated as permanent; no backoff; 410 doesn't mark the actor gone.
10. `LocalActor.preferred_username` never changes after creation, but the `activitypub_handle`
    profile field and the settings page use `normalizeHandle(username)` — they can drift.
11. Private keys are stored in plaintext; DNS-rebinding (TOCTOU) between UrlSafety and curl is open;
    lazy keypair generation on unauthenticated GETs is not rate-limited.
12. Inbound Follow is still auto-accepted for a user who became non-discoverable after their
    `LocalActor` was created.
13. Dead code: `DeliveryLog`, `LocalActor.federation_enabled` and counters,
    `FederationObject.in_reply_to_ap_id` / `raw_json`, `RemoteActorResolver::resolve()` /
    `resolveByHandle()`, `CommunitySubscriptionService::subscribe()`,
    `RemoteObjectResolver::resolveRootContent()` (meant for Lemmy plan 2).

## Roadmap (planned, not built)

- Lemmy plan 2 (read ingest: `Announce` handling, `Page` → Post with title), plan 3 (write-back:
  `Create{Page}`, comments/votes to the community), plan 4 (unsubscribe, archive, community images,
  who may create community Spaces).
- Instance blocklist; boosts both ways; outbound mentions/hashtags/CW; outbound `Update{Note}` and
  comment deletes; retention of unfollowed remote content; backfill on follow; People-directory
  filter for remote users; outbound Space-as-Group; golden-JSON and live interop tests.
- Explicit non-goals in the specs: Mastodon client API/OAuth, JSON-LD processing, multi-domain
  hosting, followers-only/DM semantics (for now).
