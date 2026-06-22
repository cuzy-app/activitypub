# Fediverse Phase 6 — Local→Remote Follow & Remote Post Ingest

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a HumHub user follow a remote Fediverse account — enter `@user@domain`, we resolve it (WebFinger), the user follows the local shadow account, we send a signed `Follow`, handle the `Accept`, and ingest the remote's public posts as Posts on the shadow profile so they appear in the follower's dashboard. The "HumHub as a Fediverse client" story.

**Architecture:** Reuses Plan 3's signature/delivery + shadow users and Plan 5's inbox dispatcher. A WebFinger *client* (`RemoteActorResolver::resolveByHandle`) turns a handle into a `RemoteActor` + shadow `User` (now `VISIBILITY_REGISTERED_ONLY` so it's followable). A "follow a remote account" form makes the HumHub user follow that shadow user; HumHub's `Follow::EVENT_FOLLOWING_CREATED` triggers an outbound `Follow` (signed by the follower's key) via a directed `DeliverToInbox` job. Inbound `Accept{Follow}` marks the follow accepted; inbound top-level `Create{Note}` from a followed remote is ingested as a public Post on the shadow profile → surfaced in the dashboard by HumHub's follow→stream join.

**Tech Stack:** PHP 8, Yii2, HumHub follow/post/dashboard APIs, OpenSSL signing + queue (Plan 3), Codeception. Live-tested: a HumHub user follows a Mastodon account and sees its posts.

---

## Roadmap position (plan 6 of 7)

1–5 ✅ (foundation → outbound posts → inbound interactions, all verified vs real Mastodon)
6. **Local→remote follow + remote post ingest (this plan)**
7. Outbound interactions — local likes/comments → remote authors (the remaining MVP §6 piece)

Spec: `docs/superpowers/specs/2026-06-22-fediverse-module-design.md` (§4 Person actors, §7 inbound placement = hybrid: person follows surface in the follower's dashboard, §10 flow C).

## Decisions / constraints

- **Shadow users become `User::VISIBILITY_REGISTERED_ONLY`** (was `VISIBILITY_HIDDEN`): hidden users can't be followed via UI and their profile is inaccessible. REGISTERED_ONLY keeps them off guest/public discovery while letting registered users follow and view them. (Directory-filtering of federated users → follow-up.)
- **The following user must be federatable** (a fetchable actor) so the remote can verify the `Follow` signature and deliver `Accept`/posts back. We require the follower to satisfy `DiscoveryPolicy::isDiscoverable` (global switch on + guest-visible profile). A non-discoverable follower is rejected with a message.
- **Scope:** follow/unfollow a remote account; ingest the remote's **top-level** public posts as Posts. **Out (→ Plan 7 / later):** outbound likes/comments, ingesting remote replies as threaded comments, profile/avatar sync, backfill of history (we ingest from the follow point forward, like Mastodon).

## Prerequisites

- Plans 1–5 shipped on `develop`; inbound + outbound verified live. `RemoteActorResolver`, `ActivityDelivery`, `KeyManager`, `FederationUrl`, `FederationFollow`, `LocalObjectResolver`, `ProcessInboxActivity` exist.
- Live env `https://test.fedihub.social` (restart `fedihub.service` after job/service/Events changes). An admin profile that is guest-visible + federation-opted-in (Plan 4) to act as the follower.

---

## File structure (created/modified)

```
fediverse/
├── config.php                            # MODIFY: register Follow events
├── Events.php                            # MODIFY: onFollowCreated / onFollowRemoved
├── services/
│   └── RemoteActorResolver.php           # MODIFY: resolveByHandle() + REGISTERED_ONLY shadow users
├── jobs/
│   └── DeliverToInbox.php                # NEW: deliver one signed activity to one inbox
├── controllers/
│   └── RemoteController.php              # NEW: "follow a remote account" form
├── views/remote/index.php               # NEW: handle input form
├── jobs/ProcessInboxActivity.php         # MODIFY: handle Accept{Follow} + ingest top-level Notes
└── tests/codeception/unit/
    └── WebfingerHandleTest.php
```

---

## Task 1: WebFinger client + followable shadow users

**Files:** Modify `services/RemoteActorResolver.php`; Test `tests/codeception/unit/WebfingerHandleTest.php`

- [ ] **Step 1: Write the failing test** (`tests/codeception/unit/WebfingerHandleTest.php`)

```php
<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\RemoteActorResolver;
use tests\codeception\_support\HumHubDbTestCase;

class WebfingerHandleTest extends HumHubDbTestCase
{
    public function testWebfingerUrlFromHandle()
    {
        $this->assertSame(
            'https://mastodon.social/.well-known/webfinger?resource=acct:cmburns@mastodon.social',
            RemoteActorResolver::webfingerUrl('@cmburns@mastodon.social')
        );
        $this->assertSame(
            'https://example.org/.well-known/webfinger?resource=acct:alice@example.org',
            RemoteActorResolver::webfingerUrl('alice@example.org')
        );
    }

    public function testInvalidHandleReturnsEmpty()
    {
        $this->assertSame('', RemoteActorResolver::webfingerUrl('not-a-handle'));
        $this->assertSame('', RemoteActorResolver::webfingerUrl(''));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/WebfingerHandleTest.php -c tests -v`
Expected: FAIL — `webfingerUrl` not defined.

- [ ] **Step 3: Edit `services/RemoteActorResolver.php`**

(a) Change the shadow user's visibility in `createShadowUser()`:
```php
        $user->visibility = User::VISIBILITY_REGISTERED_ONLY; // followable + profile-viewable, off public discovery
```
(was `User::VISIBILITY_HIDDEN`).

(b) Add a static WebFinger-URL builder and a `resolveByHandle()` method:
```php
    /** Build the WebFinger query URL for a handle like "@user@domain" / "user@domain"; '' if malformed. */
    public static function webfingerUrl(string $handle): string
    {
        $handle = ltrim(trim($handle), '@');
        if (!preg_match('/^([^@\s]+)@([^@\s]+\.[^@\s]+)$/', $handle, $m)) {
            return '';
        }
        return 'https://' . $m[2] . '/.well-known/webfinger?resource=acct:' . $m[1] . '@' . $m[2];
    }

    /** Resolve a handle (@user@domain) to a RemoteActor via WebFinger, creating the shadow user. */
    public function resolveByHandle(string $handle): RemoteActor
    {
        $wf = self::webfingerUrl($handle);
        if ($wf === '' || !UrlSafety::isSafePublicUrl($wf)) {
            throw new RuntimeException('Invalid or unsafe handle: ' . $handle);
        }

        $client = new FederationHttpClient();
        $response = $client->createRequest()
            ->setMethod('GET')
            ->setUrl($wf)
            ->addHeaders(['Accept' => 'application/jrd+json, application/json'])
            ->send();
        if (!$response->getIsOk()) {
            throw new RuntimeException('WebFinger lookup failed (' . $response->getStatusCode() . '): ' . $handle);
        }

        $jrd = json_decode($response->getContent(), true);
        $actorUrl = '';
        foreach ($jrd['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'self'
                && in_array($link['type'] ?? '', ['application/activity+json', 'application/ld+json'], true)) {
                $actorUrl = (string) ($link['href'] ?? '');
                break;
            }
        }
        if ($actorUrl === '') {
            throw new RuntimeException('No ActivityPub actor link in WebFinger for ' . $handle);
        }
        return $this->resolve($actorUrl);
    }
```
Ensure `use RuntimeException;` and `use yii\... ` imports are present (RuntimeException already used in the file; `UrlSafety` and `FederationHttpClient` are same-namespace classes — no import needed).

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/WebfingerHandleTest.php -c tests -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/RemoteActorResolver.php tests/codeception/unit/WebfingerHandleTest.php
git commit -m "Add WebFinger handle resolution; make shadow users followable"
```

---

## Task 2: `DeliverToInbox` job — one signed activity to one inbox

**Files:** Create `jobs/DeliverToInbox.php`

- [ ] **Step 1: Write `jobs/DeliverToInbox.php`**

```php
<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\ActivityDelivery;
use humhub\modules\queue\ActiveJob;
use Yii;

/**
 * Delivers a single pre-built activity, signed by a local actor's key, to one
 * remote inbox (directed activities: Follow, Undo, Accept, etc.).
 */
class DeliverToInbox extends ActiveJob
{
    public int $signerUserId = 0;
    public string $inboxUrl = '';
    public string $activityJson = '';

    public function run()
    {
        $signer = LocalActor::findOne(['user_id' => $this->signerUserId]);
        $activity = json_decode($this->activityJson, true);
        if ($signer === null || !is_array($activity) || $this->inboxUrl === '') {
            return;
        }
        try {
            if (!(new ActivityDelivery())->deliver($signer, $this->inboxUrl, $activity)) {
                Yii::warning('Fediverse directed delivery non-2xx to ' . $this->inboxUrl, 'fediverse');
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse directed delivery failed to ' . $this->inboxUrl . ': ' . $e->getMessage(), 'fediverse');
        }
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l jobs/DeliverToInbox.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add jobs/DeliverToInbox.php
git commit -m "Add DeliverToInbox job for directed activity delivery"
```

---

## Task 3: Outbound Follow/Undo on HumHub follow events

**Files:** Modify `Events.php`, `config.php`

- [ ] **Step 1: Add handlers to `Events.php`**

Add these imports if missing: `use humhub\modules\fediverse\jobs\DeliverToInbox;`, `use humhub\modules\fediverse\models\FederationFollow;`, `use humhub\modules\fediverse\models\LocalActor;`, `use humhub\modules\fediverse\models\RemoteActor;`, `use humhub\modules\fediverse\services\DiscoveryPolicy;`, `use humhub\modules\user\models\User;`, `use humhub\modules\user\events\FollowEvent;`.

Add:
```php
    /** A local user followed someone → if it's a remote shadow actor, send a Follow. */
    public static function onFollowCreated(FollowEvent $event): void
    {
        $follower = $event->user;
        $target = $event->target;
        if (!$follower instanceof User || !$target instanceof User) {
            return;
        }
        $remote = RemoteActor::findOne(['user_id' => $target->id]);
        if ($remote === null) {
            return; // target is a normal local user, not a federated account
        }
        if (!DiscoveryPolicy::isDiscoverable($follower)) {
            Yii::warning('Fediverse: follower ' . $follower->id . ' is not federatable; remote follow not sent', 'fediverse');
            return;
        }

        $signer = (new KeyManager())->getOrCreateForUser($follower);

        $follow = FederationFollow::findOne([
            'local_user_id' => $follower->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_OUTBOUND,
        ]);
        if ($follow === null) {
            $follow = new FederationFollow();
            $follow->local_user_id = $follower->id;
            $follow->remote_actor_id = $remote->id;
            $follow->direction = FederationFollow::DIRECTION_OUTBOUND;
        }
        $follow->state = FederationFollow::STATE_PENDING;
        $follow->activity_ap_id = $signer->ap_id . '/follows/' . bin2hex(random_bytes(8));
        $follow->save();

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $follow->activity_ap_id,
            'type' => 'Follow',
            'actor' => $signer->ap_id,
            'object' => $remote->ap_id,
        ];
        Yii::$app->queue->push(new DeliverToInbox([
            'signerUserId' => $follower->id,
            'inboxUrl' => $remote->inbox_url,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** A local user unfollowed a remote shadow actor → send Undo{Follow}. */
    public static function onFollowRemoved(FollowEvent $event): void
    {
        $follower = $event->user;
        $target = $event->target;
        if (!$follower instanceof User || !$target instanceof User) {
            return;
        }
        $remote = RemoteActor::findOne(['user_id' => $target->id]);
        if ($remote === null) {
            return;
        }
        $follow = FederationFollow::findOne([
            'local_user_id' => $follower->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_OUTBOUND,
        ]);
        if ($follow === null) {
            return;
        }
        $signer = LocalActor::findOne(['user_id' => $follower->id]);
        if ($signer !== null) {
            $undo = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $signer->ap_id . '/undos/' . bin2hex(random_bytes(8)),
                'type' => 'Undo',
                'actor' => $signer->ap_id,
                'object' => [
                    'id' => $follow->activity_ap_id,
                    'type' => 'Follow',
                    'actor' => $signer->ap_id,
                    'object' => $remote->ap_id,
                ],
            ];
            Yii::$app->queue->push(new DeliverToInbox([
                'signerUserId' => $follower->id,
                'inboxUrl' => $remote->inbox_url,
                'activityJson' => json_encode($undo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]));
        }
        $follow->delete();
    }
```
(`KeyManager` and `Yii` are already imported in `Events.php` from Plan 4; add `LocalActor` per the list above.)

- [ ] **Step 2: Register the events in `config.php`**

Add to the `events` array:
```php
        ['class' => 'humhub\modules\user\models\Follow', 'event' => 'followCreated', 'callback' => ['humhub\modules\fediverse\Events', 'onFollowCreated']],
        ['class' => 'humhub\modules\user\models\Follow', 'event' => 'followRemoved', 'callback' => ['humhub\modules\fediverse\Events', 'onFollowRemoved']],
```

- [ ] **Step 3: Verify syntax**

Run: `php -l Events.php && php -l config.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add Events.php config.php
git commit -m "Send Follow/Undo when a local user follows a remote account"
```

---

## Task 4: Inbound `Accept{Follow}` + ingest top-level posts

**Files:** Modify `jobs/ProcessInboxActivity.php`

- [ ] **Step 1: Add `Accept` to the `run()` dispatch switch**

Before `default:` add:
```php
            case 'Accept':
                $this->handleAccept($activity, $remote);
                break;
```

- [ ] **Step 2: Add `handleAccept` and extend `handleCreate` to ingest top-level Notes**

Add the handler:
```php
    /** Remote accepted our outbound Follow → mark it accepted. */
    private function handleAccept(array $activity, RemoteActor $remote): void
    {
        $inner = $activity['object'] ?? null;
        if (!is_array($inner) || ($inner['type'] ?? '') !== 'Follow') {
            return;
        }
        $follow = FederationFollow::findOne([
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_OUTBOUND,
        ]);
        if ($follow !== null) {
            $follow->state = FederationFollow::STATE_ACCEPTED;
            $follow->save();
        }
    }
```

In `handleCreate`, after the existing reply path, add a top-level-post branch. Replace the current early `return` for the no-inReplyTo case:
```php
        $inReplyTo = $object['inReplyTo'] ?? null;
        if (!is_string($inReplyTo) || $inReplyTo === '') {
            return; // top-level remote posts are not ingested in this phase, only replies to us
        }
```
with:
```php
        $inReplyTo = $object['inReplyTo'] ?? null;
        if (!is_string($inReplyTo) || $inReplyTo === '') {
            $this->ingestRemotePost($object, $remote);
            return;
        }
```
and add the ingest method (uses the InboundInteractionService for idempotency-by-id is overkill; do a local check here):
```php
    /** Ingest a followed remote actor's top-level Note as a Post on the shadow profile. */
    private function ingestRemotePost(array $note, RemoteActor $remote): void
    {
        // Only ingest from actors a local user actually follows (we only receive such posts anyway).
        $followed = FederationFollow::find()
            ->where(['remote_actor_id' => $remote->id, 'direction' => FederationFollow::DIRECTION_OUTBOUND, 'state' => FederationFollow::STATE_ACCEPTED])
            ->exists();
        if (!$followed) {
            return;
        }
        $noteId = (string) ($note['id'] ?? '');
        if ($noteId === '' || \humhub\modules\fediverse\models\FederationObject::find()->where(['ap_id' => $noteId])->exists()) {
            return; // malformed or already ingested
        }
        $shadow = User::findOne($remote->user_id);
        if ($shadow === null) {
            return;
        }

        $post = new \humhub\modules\post\models\Post($shadow, \humhub\modules\content\models\Content::VISIBILITY_PUBLIC, [
            'message' => trim(strip_tags((string) ($note['content'] ?? ''))),
        ]);
        $post->created_by = $shadow->id;
        $post->autoFollow = false;
        try {
            if (!$post->save()) {
                Yii::warning('Fediverse ingest post failed: ' . implode(', ', $post->getErrorSummary(true)), 'fediverse');
                return;
            }
        } catch (\Throwable $e) {
            Yii::warning('Fediverse ingest post error: ' . $e->getMessage(), 'fediverse');
            return;
        }

        $obj = new \humhub\modules\fediverse\models\FederationObject();
        $obj->ap_id = $noteId;
        $obj->direction = \humhub\modules\fediverse\models\FederationObject::DIRECTION_REMOTE;
        $obj->object_type = 'Note';
        $obj->model_class = \humhub\modules\post\models\Post::class;
        $obj->model_id = $post->id;
        $obj->save();
    }
```

- [ ] **Step 3: Verify syntax**

Run: `php -l jobs/ProcessInboxActivity.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add jobs/ProcessInboxActivity.php
git commit -m "Handle Accept{Follow} and ingest followed remotes' top-level posts"
```

---

## Task 5: "Follow a remote account" form

**Files:** Create `controllers/RemoteController.php`, `views/remote/index.php`

- [ ] **Step 1: Write `controllers/RemoteController.php`**

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\user\models\User;
use Yii;

/**
 * Lets a logged-in, federatable user follow a remote Fediverse account by handle.
 */
class RemoteController extends Controller
{
    public function actionIndex()
    {
        $user = Yii::$app->user->getIdentity();
        if ($user === null) {
            return $this->redirect(['/user/auth/login']);
        }

        $error = null;
        if (Yii::$app->request->isPost) {
            $handle = (string) Yii::$app->request->post('handle', '');
            if (!DiscoveryPolicy::isDiscoverable($user)) {
                $error = Yii::t('FediverseModule.base', 'Your profile must be federation-enabled (public/guest-visible) before you can follow remote accounts.');
            } else {
                try {
                    $remote = (new RemoteActorResolver())->resolveByHandle($handle);
                    $shadow = User::findOne($remote->user_id);
                    if ($shadow !== null) {
                        $shadow->follow($user->id); // fires Follow::EVENT_FOLLOWING_CREATED → outbound Follow
                        $this->view->success(Yii::t('FediverseModule.base', 'Now following {handle}.', ['handle' => $remote->handle]));
                        return $this->redirect(['index']);
                    }
                } catch (\Throwable $e) {
                    $error = Yii::t('FediverseModule.base', 'Could not resolve that account: {msg}', ['msg' => $e->getMessage()]);
                }
            }
        }

        return $this->render('index', ['error' => $error]);
    }
}
```

- [ ] **Step 2: Write `views/remote/index.php`**

```php
<?php

use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $this \humhub\components\View */
/* @var $error string|null */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Follow a Fediverse account'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'Enter a handle like <code>@user@mastodon.social</code> to follow it. Their public posts will appear in your dashboard.'); ?></p>
        <?php if ($error !== null) : ?>
            <div class="alert alert-danger"><?= Html::encode($error); ?></div>
        <?php endif; ?>
        <?= Html::beginForm(); ?>
            <div class="form-group">
                <?= Html::textInput('handle', '', ['class' => 'form-control', 'placeholder' => '@user@domain']); ?>
            </div>
            <?= Button::primary(Yii::t('FediverseModule.base', 'Follow'))->submit(); ?>
        <?= Html::endForm(); ?>
    </div>
</div>
```

- [ ] **Step 3: Verify syntax**

Run: `php -l controllers/RemoteController.php && php -l views/remote/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add controllers/RemoteController.php views/remote/index.php
git commit -m "Add follow-a-remote-account form"
```

- [ ] **Step 5: Live end-to-end check (human + me)**

After deploy + worker restart + cache flush:
1. As admin (federatable), open `/fediverse/remote/index`, enter a real Mastodon handle (e.g. `@cmburns@mastodon.social`), submit.
2. Verify: a `fediverse_follow` row (`direction=outbound`) appears (state `pending`, then `accepted` once the remote's `Accept` arrives — confirm via DB it flips to `accepted`); on the Mastodon side, the account shows admin as a follower.
3. When that remote account makes a new **public** post, it appears as a Post on the shadow profile and in admin's HumHub **dashboard** within seconds; a `fediverse_object` (`direction=remote`, Note→Post) row exists.
4. Unfollow (via the shadow user's profile follow button or by re-running): an `Undo{Follow}` is delivered and the `fediverse_follow` row is removed.

---

## Done criteria

- Unit test green: `WebfingerHandleTest`.
- Live: admin follows a Mastodon account → Mastodon shows admin as follower (our `Follow` accepted); the account's new public posts land in admin's dashboard; unfollow propagates.
- The §-constraint holds: a non-federatable follower is rejected with a clear message.

## Known follow-ups (→ Plan 7 / later)

- Outbound likes/comments (local → remote authors).
- Ingest remote **replies** as threaded HumHub comments (currently only top-level posts are ingested; replies-to-us already become comments via Plan 5).
- Migrate the 17 existing `VISIBILITY_HIDDEN` shadow users to `VISIBILITY_REGISTERED_ONLY` (a one-off migration) so they're followable too; and a People-directory filter to hide federated users.
- Profile/avatar/bio sync for shadow users; backfill of recent posts on follow; attachments on ingested posts; rich HTML→markdown.

## Self-review risks to confirm live

- **Follower needs a fetchable actor:** the remote verifies our `Follow` signature by fetching the follower's actor — admin must be discoverable (guest-visible + global on). Confirm a discoverable follower's `Follow` is accepted (state flips to `accepted`).
- **`Post(new $shadow, …)` constructor + `created_by` + `autoFollow=false`** in the queue — confirm ingested posts save with the shadow user as author and surface in the follower's dashboard (the follow→stream join).
- **Follow-button visibility** now that shadow users are `REGISTERED_ONLY` — confirm the unfollow path (the `EVENT_FOLLOWING_REMOVED` → `Undo`) fires; if the standard button isn't reachable, unfollow via the same form/route is the fallback.
