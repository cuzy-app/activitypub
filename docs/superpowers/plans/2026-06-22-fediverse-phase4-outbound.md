# Fediverse Phase 4 — Outbound Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Federate a federatable user's **public profile posts** to their remote followers — when an opted-in, guest-visible user publishes a public post on their own profile wall, build a `Create{Note}` (with attachments) and deliver it, signed, to every accepted follower's inbox; handle `Update`/`Delete`; expose the actor's `outbox`.

**Architecture:** A `FederationContentPolicy` enforces the §5 invariant. HumHub content events (`Content::EVENT_STATE_CHANGED`, soft-delete) trigger an `Events` handler that serializes the post (`NoteSerializer`) and enqueues a `DeliverActivity` job, which fans out to the author's accepted followers via the Plan-3 `ActivityDelivery`. The `outbox` endpoint serves the user's federated activities from `fediverse_object`.

**Tech Stack:** PHP 8, Yii2, HumHub content/post/file APIs, OpenSSL signing (Plan 3), HumHub queue, Codeception. Live-tested against `@cmburns@mastodon.social` following `@admin@test.fedihub.social`.

---

## Roadmap position (plan 4 of 5)

1. Foundation ✅  2. Identity & Discovery ✅  3. Inbound core ✅ (Follow/Accept verified vs real Mastodon)
4. **Outbound (this plan)** — profile posts → followers
5. Interactions — outbound Likes/Comments, inbound `Create{Note,inReplyTo}`/`Like`, local→remote follow

Spec: `docs/superpowers/specs/2026-06-22-fediverse-module-design.md` (§5 invariant, §6 content types, §10 flow A).

## Scope (deliberately bounded)

- **In:** posts on a **User profile** (Person actor) → `Create{Note}`, `Update`, `Delete`; image/file attachments; per-user opt-in; the §5 invariant; queued fan-out delivery; the `outbox` endpoint.
- **Out (→ Plan 5):** Likes and Comments in either direction, and Spaces-as-Group (Phase 3). Space posts are NOT federated here (Spaces aren't actors yet).

## §5 invariant (enforced by `FederationContentPolicy::mayFederate`)

A post is federated only if **all** hold: global federation switch ON · site Guest Mode ON · container is a `User` profile · that profile's visibility = `User::VISIBILITY_ALL` (guest-visible) · the profile has the federation opt-in enabled · the content's `visibility` = `Content::VISIBILITY_PUBLIC`.

## Prerequisites

- Plans 1–3 shipped on `develop`; inbound Follow verified live. `ActivityDelivery`, `KeyManager`, `FederationUrl`, `DiscoveryPolicy`, `FederationObject`, `FederationFollow`, `LocalActor`, `RemoteActor` exist.
- Live test env `https://test.fedihub.social` (queue worker `fedihub.service`; restart it after changing job/event/serializer classes: `systemctl --user restart fedihub.service`). Controller/view changes apply per-request.

---

## File structure (created/modified by this plan)

```
fediverse/
├── Module.php                          # MODIFY: extend ContentContainerModule, add config URL
├── config.php                          # MODIFY: register outbound events + outbox URL rule
├── Events.php                          # NEW: content event handlers (publish/update/delete)
├── services/
│   ├── FederationContentPolicy.php     # NEW: the §5 invariant
│   └── NoteSerializer.php              # NEW: Post → Note + Create/Update/Delete
├── jobs/
│   └── DeliverActivity.php             # NEW: fan-out delivery to followers
├── controllers/
│   ├── OutboxController.php            # NEW: /fediverse/actor/<guid>/outbox
│   └── ProfileConfigController.php     # NEW: per-user opt-in toggle
├── views/profile-config/index.php      # NEW: opt-in checkbox form
└── tests/codeception/unit/
    ├── FederationContentPolicyTest.php
    └── NoteSerializerTest.php
```

---

## Task 1: `FederationContentPolicy` — the §5 invariant

**Files:** Create `services/FederationContentPolicy.php`; Test `tests/codeception/unit/FederationContentPolicyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\content\models\Content;
use humhub\modules\fediverse\services\FederationContentPolicy;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class FederationContentPolicyTest extends HumHubDbTestCase
{
    public function testContainerOptInRoundTrip()
    {
        // A fresh container is not opted in; setting the flag flips it.
        $user = User::findOne(['username' => 'User1']) ?? User::find()->one();
        $this->assertNotNull($user, 'a test user fixture is required');

        $this->assertFalse(FederationContentPolicy::isContainerOptedIn($user));
        Yii::$app->getModule('fediverse')->settings->contentContainer($user)->set('federationEnabled', 1);
        $this->assertTrue(FederationContentPolicy::isContainerOptedIn($user));
        Yii::$app->getModule('fediverse')->settings->contentContainer($user)->set('federationEnabled', 0);
        $this->assertFalse(FederationContentPolicy::isContainerOptedIn($user));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/FederationContentPolicyTest.php -c tests -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `services/FederationContentPolicy.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\components\ContentContainerActiveRecord;
use humhub\modules\content\models\Content;
use humhub\modules\user\helpers\AuthHelper;
use humhub\modules\user\models\User;
use Yii;

/**
 * Enforces the spec §5 invariant: a post may be federated only if it is already
 * guest-visible public content in an opted-in, guest-visible user profile.
 */
class FederationContentPolicy
{
    public static function mayFederate(Content $content): bool
    {
        if (!DiscoveryPolicy::isGloballyEnabled() || !AuthHelper::isGuestAccessEnabled()) {
            return false;
        }
        if ((int) $content->visibility !== Content::VISIBILITY_PUBLIC) {
            return false;
        }
        $container = $content->getContainer();
        // Phase 4 federates user-profile posts only (Spaces become Group actors in Phase 3).
        if (!$container instanceof User) {
            return false;
        }
        if ((int) $container->visibility !== User::VISIBILITY_ALL) {
            return false;
        }
        return self::isContainerOptedIn($container);
    }

    public static function isContainerOptedIn(ContentContainerActiveRecord $container): bool
    {
        return (bool) Yii::$app->getModule('fediverse')
            ->settings->contentContainer($container)->get('federationEnabled', false);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/FederationContentPolicyTest.php -c tests -v`
Expected: PASS. (Adjust the fixture username if the test DB uses different seed users.)

- [ ] **Step 5: Commit**

```bash
git add services/FederationContentPolicy.php tests/codeception/unit/FederationContentPolicyTest.php
git commit -m "Add FederationContentPolicy enforcing the §5 invariant"
```

---

## Task 2: Per-user opt-in (setting + config page)

Makes the module a `ContentContainerModule` (so HumHub shows a per-profile "Configure" link) and adds a one-checkbox config page that toggles `federationEnabled` for the current user's profile.

**Files:** Modify `Module.php`; Create `controllers/ProfileConfigController.php`, `views/profile-config/index.php`

- [ ] **Step 1: Update `Module.php`**

Replace the class body with:
```php
<?php

namespace humhub\modules\fediverse;

use humhub\modules\content\components\ContentContainerActiveRecord;
use humhub\modules\content\components\ContentContainerModule;
use humhub\modules\user\models\User;
use Yii;
use yii\helpers\Url;

class Module extends ContentContainerModule
{
    /** @inheritdoc */
    public function getConfigUrl()
    {
        return Url::to(['/fediverse/admin/index']);
    }

    /** @inheritdoc — federation applies to user profiles in this phase (Spaces in Phase 3). */
    public function getContentContainerTypes()
    {
        return [User::class];
    }

    /** @inheritdoc */
    public function getContentContainerName(ContentContainerActiveRecord $container)
    {
        return Yii::t('FediverseModule.base', 'Fediverse');
    }

    /** @inheritdoc — per-profile "Configure" link. */
    public function getContentContainerConfigUrl(ContentContainerActiveRecord $container)
    {
        if ($container instanceof User) {
            return $container->createUrl('/fediverse/profile-config/index');
        }
        return '';
    }
}
```

- [ ] **Step 2: Write `controllers/ProfileConfigController.php`**

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\modules\content\components\ContentContainerController;
use humhub\modules\user\models\User;
use Yii;

class ProfileConfigController extends ContentContainerController
{
    /** Only the profile owner (or an admin) may toggle federation. */
    public function actionIndex()
    {
        $container = $this->contentContainer; // set by ContentContainerController
        if (!$container instanceof User || (!$container->isCurrentUser() && !Yii::$app->user->isAdmin())) {
            throw new \yii\web\ForbiddenHttpException();
        }

        $settings = Yii::$app->getModule('fediverse')->settings->contentContainer($container);

        if (Yii::$app->request->isPost) {
            $enabled = (bool) Yii::$app->request->post('federationEnabled', false);
            $settings->set('federationEnabled', $enabled ? 1 : 0);
            $this->view->saved();
            return $this->redirect($container->createUrl('/fediverse/profile-config/index'));
        }

        return $this->render('index', [
            'container' => $container,
            'enabled' => (bool) $settings->get('federationEnabled', false),
        ]);
    }
}
```

- [ ] **Step 3: Write `views/profile-config/index.php`**

```php
<?php

use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $this \humhub\components\View */
/* @var $container \humhub\modules\user\models\User */
/* @var $enabled bool */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Fediverse federation'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'When enabled, your public profile posts are published to the Fediverse and can be followed from Mastodon and other servers.'); ?></p>
        <?= Html::beginForm($container->createUrl('/fediverse/profile-config/index'), 'post'); ?>
            <div class="checkbox">
                <label>
                    <?= Html::checkbox('federationEnabled', $enabled); ?>
                    <?= Yii::t('FediverseModule.base', 'Federate my public profile posts'); ?>
                </label>
            </div>
            <?= Button::save()->submit(); ?>
        <?= Html::endForm(); ?>
    </div>
</div>
```

- [ ] **Step 4: Verify syntax**

Run: `php -l Module.php && php -l controllers/ProfileConfigController.php && php -l views/profile-config/index.php`
Expected: all `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add Module.php controllers/ProfileConfigController.php views/profile-config/index.php
git commit -m "Add per-profile federation opt-in (ContentContainerModule config)"
```

- [ ] **Step 6: Live check (human)**

After deploy + `php protected/yii migrate/up` is not needed; flush cache if the new container-module link doesn't show (`php protected/yii cache/flush-all`). Visit the profile's module config (Account → Modules → Fediverse → Configure, or `/u/<username>/fediverse/profile-config/index`); toggle "Federate my public profile posts", save, confirm it persists. **This per-container config placement is the area to verify live** — if the link doesn't surface, reach the action directly via the container URL and confirm the toggle writes `federationEnabled`.

---

## Task 3: `NoteSerializer` — Post → Note / Create / Update / Delete

**Files:** Create `services/NoteSerializer.php`; Test `tests/codeception/unit/NoteSerializerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\NoteSerializer;
use tests\codeception\_support\HumHubDbTestCase;

class NoteSerializerTest extends HumHubDbTestCase
{
    public function testNoteAndCreateShape()
    {
        $actorUrl = 'https://test.fedihub.social/fediverse/actor/guid-1';
        $s = new NoteSerializer();

        $note = $s->note(
            $actorUrl,
            $actorUrl . '/notes/42',
            '<p>Hello Fediverse</p>',
            '2026-06-22T18:00:00Z',
            'https://test.fedihub.social/u/alice/',
            []
        );
        $this->assertSame('Note', $note['type']);
        $this->assertSame($actorUrl, $note['attributedTo']);
        $this->assertSame('<p>Hello Fediverse</p>', $note['content']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $note['to']);
        $this->assertContains($actorUrl . '/followers', $note['cc']);

        $create = $s->wrap('Create', $actorUrl, $note, $note['id'] . '/activity');
        $this->assertSame('Create', $create['type']);
        $this->assertSame($actorUrl, $create['actor']);
        $this->assertSame($note, $create['object']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $create['to']);

        $del = $s->delete($actorUrl, $note['id'], $note['id'] . '/delete');
        $this->assertSame('Delete', $del['type']);
        $this->assertSame('Tombstone', $del['object']['type']);
        $this->assertSame($note['id'], $del['object']['id']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/NoteSerializerTest.php -c tests -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `services/NoteSerializer.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

class NoteSerializer
{
    public const PUBLIC_URI = 'https://www.w3.org/ns/activitystreams#Public';

    /**
     * @param array $attachments each: ['type'=>'Image'|'Document','mediaType'=>..,'url'=>..]
     */
    public function note(string $actorUrl, string $noteId, string $contentHtml, string $publishedIso, string $url, array $attachments): array
    {
        $note = [
            'id' => $noteId,
            'type' => 'Note',
            'attributedTo' => $actorUrl,
            'content' => $contentHtml,
            'published' => $publishedIso,
            'url' => $url,
            'to' => [self::PUBLIC_URI],
            'cc' => [$actorUrl . '/followers'],
        ];
        if ($attachments !== []) {
            $note['attachment'] = array_values($attachments);
        }
        return $note;
    }

    /** Wrap an object in a Create/Update activity. */
    public function wrap(string $type, string $actorUrl, array $object, string $activityId): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => $type,
            'actor' => $actorUrl,
            'published' => $object['published'] ?? null,
            'to' => $object['to'] ?? [self::PUBLIC_URI],
            'cc' => $object['cc'] ?? [$actorUrl . '/followers'],
            'object' => $object,
        ];
    }

    /** Delete activity referencing a Tombstone of the note. */
    public function delete(string $actorUrl, string $noteId, string $activityId): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Delete',
            'actor' => $actorUrl,
            'to' => [self::PUBLIC_URI],
            'cc' => [$actorUrl . '/followers'],
            'object' => ['id' => $noteId, 'type' => 'Tombstone'],
        ];
    }

    /** Build an attachment array from a HumHub File. */
    public function attachment(string $mimeType, string $url): array
    {
        return [
            'type' => str_starts_with($mimeType, 'image/') ? 'Image' : 'Document',
            'mediaType' => $mimeType,
            'url' => $url,
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/NoteSerializerTest.php -c tests -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/NoteSerializer.php tests/codeception/unit/NoteSerializerTest.php
git commit -m "Add NoteSerializer for outbound Note/Create/Update/Delete"
```

---

## Task 4: Outbound event handlers (`Events`) + registration

**Files:** Create `Events.php`; Modify `config.php`

- [ ] **Step 1: Write `Events.php`**

```php
<?php

namespace humhub\modules\fediverse;

use humhub\modules\content\events\ContentStateEvent;
use humhub\modules\content\models\Content;
use humhub\modules\content\widgets\richtext\RichText;
use humhub\modules\fediverse\jobs\DeliverActivity;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\services\FederationContentPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\NoteSerializer;
use humhub\modules\post\models\Post;
use Yii;

class Events
{
    /** Content published → Create{Note} to the author's followers. */
    public static function onContentStateChanged(ContentStateEvent $event): void
    {
        if ((int) $event->newState !== Content::STATE_PUBLISHED) {
            return;
        }
        $content = $event->content;
        $record = $content->getPolymorphicRelation();
        if (!$record instanceof Post || !FederationContentPolicy::mayFederate($content)) {
            return;
        }

        $author = $content->createdBy;
        $actor = (new KeyManager())->getOrCreateForUser($author);

        $noteId = $actor->ap_id . '/notes/' . $content->id;
        $serializer = new NoteSerializer();
        $note = $serializer->note(
            $actor->ap_id,
            $noteId,
            RichText::output((string) $record->message),
            date('c', strtotime((string) $content->created_at)),
            $content->getUrl(true),
            self::attachments($record, $serializer)
        );
        $activity = $serializer->wrap('Create', $actor->ap_id, $note, $noteId . '/activity');

        // Record the mapping so the outbox and later Update/Delete can resolve it.
        self::recordObject($noteId, 'Note', Post::class, $record->id);

        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => $author->id,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /** Post soft-deleted → Delete{Note}. */
    public static function onContentDeleted($event): void
    {
        $record = $event->sender;
        if (!$record instanceof Post) {
            return;
        }
        $noteId = self::existingNoteId(Post::class, $record->id);
        if ($noteId === null) {
            return; // was never federated
        }
        $author = $record->content->createdBy;
        $actor = (new KeyManager())->getOrCreateForUser($author);
        $activity = (new NoteSerializer())->delete($actor->ap_id, $noteId, $noteId . '/delete');

        Yii::$app->queue->push(new DeliverActivity([
            'localUserId' => $author->id,
            'activityJson' => json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    private static function attachments(Post $record, NoteSerializer $serializer): array
    {
        $out = [];
        foreach ($record->fileManager->findAll() as $file) {
            $out[] = $serializer->attachment((string) $file->mime_type, $file->getUrl([], true));
        }
        return $out;
    }

    private static function recordObject(string $apId, string $type, string $modelClass, int $modelId): void
    {
        if (FederationObject::find()->where(['ap_id' => $apId])->exists()) {
            return;
        }
        $obj = new FederationObject();
        $obj->ap_id = $apId;
        $obj->direction = FederationObject::DIRECTION_LOCAL;
        $obj->object_type = $type;
        $obj->model_class = $modelClass;
        $obj->model_id = $modelId;
        $obj->save();
    }

    private static function existingNoteId(string $modelClass, int $modelId): ?string
    {
        $obj = FederationObject::findOne([
            'model_class' => $modelClass,
            'model_id' => $modelId,
            'direction' => FederationObject::DIRECTION_LOCAL,
        ]);
        return $obj?->ap_id;
    }
}
```

- [ ] **Step 2: Register the events in `config.php`**

Add to the `events` array (keep `consoleControllerMap` + `urlManagerRules`):
```php
    'events' => [
        ['class' => 'humhub\modules\content\models\Content', 'event' => 'changedState', 'callback' => ['humhub\modules\fediverse\Events', 'onContentStateChanged']],
        ['class' => 'humhub\modules\post\models\Post', 'event' => 'afterSoftDelete', 'callback' => ['humhub\modules\fediverse\Events', 'onContentDeleted']],
    ],
```
(`changedState` = `Content::EVENT_STATE_CHANGED`; `afterSoftDelete` = `SoftDeletable::EVENT_AFTER_SOFT_DELETE`. String literals avoid importing the classes in config.)

- [ ] **Step 3: Verify syntax**

Run: `php -l Events.php && php -l config.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add Events.php config.php
git commit -m "Add outbound content event handlers (publish/delete)"
```

---

## Task 5: `DeliverActivity` job — fan-out to followers

**Files:** Create `jobs/DeliverActivity.php`

- [ ] **Step 1: Write `jobs/DeliverActivity.php`**

```php
<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\ActivityDelivery;
use humhub\modules\queue\ActiveJob;
use Yii;

/**
 * Delivers a pre-built activity (signed by the local user's key) to every
 * accepted remote follower of that user. Each follower's personal inbox is
 * used; shared inboxes are de-duplicated to avoid double delivery.
 */
class DeliverActivity extends ActiveJob
{
    public int $localUserId = 0;
    public string $activityJson = '';

    public function run()
    {
        $signer = LocalActor::findOne(['user_id' => $this->localUserId]);
        $activity = json_decode($this->activityJson, true);
        if ($signer === null || !is_array($activity)) {
            return;
        }

        $follows = FederationFollow::find()
            ->where([
                'local_user_id' => $this->localUserId,
                'direction' => FederationFollow::DIRECTION_INBOUND,
                'state' => FederationFollow::STATE_ACCEPTED,
            ])->all();

        $delivery = new ActivityDelivery();
        $sentInboxes = [];
        foreach ($follows as $follow) {
            $remote = RemoteActor::findOne($follow->remote_actor_id);
            if ($remote === null) {
                continue;
            }
            // Prefer the shared inbox for fan-out, de-duplicated; else the personal inbox.
            $inbox = $remote->shared_inbox_url ?: $remote->inbox_url;
            if ($inbox === null || $inbox === '' || isset($sentInboxes[$inbox])) {
                continue;
            }
            $sentInboxes[$inbox] = true;
            try {
                if (!$delivery->deliver($signer, $inbox, $activity)) {
                    Yii::warning('Fediverse delivery non-2xx to ' . $inbox, 'fediverse');
                }
            } catch (\Throwable $e) {
                Yii::warning('Fediverse delivery failed to ' . $inbox . ': ' . $e->getMessage(), 'fediverse');
            }
        }
    }
}
```

> Note: for a `Create{Note}` broadcast, the shared inbox IS correct (it carries `to`/`cc` addressing). This differs from a directed `Accept` (Plan 3), which must use the personal inbox.

- [ ] **Step 2: Verify syntax**

Run: `php -l jobs/DeliverActivity.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add jobs/DeliverActivity.php
git commit -m "Add DeliverActivity job fanning out to followers"
```

---

## Task 6: `outbox` endpoint

**Files:** Create `controllers/OutboxController.php`; Modify `config.php` (URL rule)

- [ ] **Step 1: Add the URL rule to `config.php`**

Add (before the generic `fediverse/actor/<guid>` rule, like the inbox rules):
```php
        ['pattern' => 'fediverse/actor/<guid:[\w\-]+>/outbox', 'route' => 'fediverse/outbox/index'],
```

- [ ] **Step 2: Write `controllers/OutboxController.php`**

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\post\models\Post;
use humhub\modules\user\models\User;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class OutboxController extends Controller
{
    public $access = null;

    public function beforeAction($action)
    {
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** GET /fediverse/actor/<guid>/outbox */
    public function actionIndex($guid)
    {
        $user = User::findOne(['guid' => $guid]);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }
        $actor = LocalActor::findOne(['user_id' => $user->id]);
        if ($actor === null) {
            throw new NotFoundHttpException();
        }

        // Count this user's federated Notes (by their authored, locally-recorded objects).
        $postIds = Post::find()
            ->joinWith('content')
            ->where(['content.created_by' => $user->id])
            ->select('post.id')->column();

        $total = FederationObject::find()
            ->where(['direction' => FederationObject::DIRECTION_LOCAL, 'object_type' => 'Note', 'model_class' => Post::class])
            ->andWhere(['in', 'model_id', $postIds ?: [0]])
            ->count();

        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actor->ap_id . '/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => (int) $total,
            'first' => $actor->ap_id . '/outbox?page=1',
        ];

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/activity+json; charset=utf-8');
        $response->content = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $response;
    }
}
```

> A summary `OrderedCollection` (totalItems + `first` page pointer) is sufficient for Mastodon discovery; full paged `orderedItems` can be added later. Mastodon does not require the outbox to follow accounts.

- [ ] **Step 3: Verify syntax**

Run: `php -l controllers/OutboxController.php && php -l config.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add controllers/OutboxController.php config.php
git commit -m "Add outbox endpoint (summary OrderedCollection)"
```

- [ ] **Step 5: Live end-to-end check (human + me)**

After deploy + worker restart (`systemctl --user restart fedihub.service`) and a cache flush:
1. As `@admin` (guest-visible profile), enable the federation opt-in (Task 2).
2. Ensure `@cmburns@mastodon.social` follows `@admin@test.fedihub.social` (Plan 3, accepted).
3. As admin, create a **public** post on the profile wall.
4. Expected: within seconds, the post appears in cmburns's Mastodon home timeline. Verify our side has a `fediverse_object` row (Note) and the delivery log shows no non-2xx warning. `curl …/fediverse/actor/<guid>/outbox` returns an `OrderedCollection` with `totalItems` ≥ 1.
5. Delete the post → it disappears from Mastodon (Delete{Note}).

---

## Done criteria

- Unit tests green: `FederationContentPolicyTest`, `NoteSerializerTest`.
- A public profile post by an opted-in, guest-visible user is delivered to accepted followers and shows up in real Mastodon; deletion propagates; the outbox returns a valid `OrderedCollection`.
- The §5 invariant blocks: non-public posts, posts by non-opted-in or non-guest-visible profiles, and all Space posts.

## Known follow-ups (→ Plan 5 / later)

- Outbound `Update{Note}` on edit (add a `Content` update hook; the serializer already supports `wrap('Update', …)`).
- Likes & comments (both directions); inbound `Create{Note,inReplyTo}`/`Like`; local→remote follow.
- Full paged `outbox` `orderedItems`.
- Mentions/`tag`, and `sensitive`/content-warning mapping.

## Self-review risks to confirm live

- **Per-container config placement (Task 2):** converting to `ContentContainerModule` + `getContentContainerConfigUrl` is the researched pattern (timetracking module), but the exact place the "Configure" link surfaces for a *profile* should be confirmed live; the `federationEnabled` setting read/write is the robust part.
- **`getPolymorphicRelation()`** returning the `Post` — confirm the accessor name on `Content` during the first live publish (the research cited it; if it differs, it's `$content->getModel()`/`$content->object`).
- **Create{Note} to shared inbox** is correct (addressed broadcast), unlike the directed `Accept` — do not "fix" it to the personal inbox.
