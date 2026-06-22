# Fediverse Phase 2 — Identity & Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make HumHub users discoverable and fetchable from the Fediverse — every guest-visible profile becomes a followable ActivityPub `Person` actor with a stable key, resolvable via WebFinger, plus an instance `Application` actor and NodeInfo.

**Architecture:** Read-only HTTP surface built on Plan 1's tables. A `KeyManager` lazily creates a `LocalActor` row (RSA keypair) per eligible user and for the instance. A `PersonSerializer` renders the ActivityPub actor document. Three controllers expose the actor (`/fediverse/actor/<guid>`), WebFinger (`/.well-known/webfinger`), and NodeInfo (`/.well-known/nodeinfo`, `/nodeinfo/2.1`). No activities, no signing, no writes yet.

**Tech Stack:** PHP 8, Yii2, HumHub module framework, OpenSSL (`openssl_pkey_*`), Codeception. Live functional checks via `curl` against `https://humhub-develop.localhost`.

---

## Roadmap position (plan 2 of 5)

1. Foundation ✅ (shipped, verified live)
2. **Identity & Discovery** *(this plan)*
3. Inbound core — inbox, signature verification, remote-actor resolver, `Follow`→`Accept`
4. Outbound — per-container opt-in + invariant, serializers, delivery, outbox
5. Inbound interactions & local→remote follow

Spec: `docs/superpowers/specs/2026-06-22-fediverse-module-design.md`.

## Design decisions locked for this plan

- **Actor URL = GUID-based:** `/fediverse/actor/<user-guid>` (immutable). The instance actor is `/fediverse/instance-actor`.
- **Discoverability = global switch ON AND profile visibility `User::VISIBILITY_ALL`** (guest-visible). No per-user opt-in needed for discovery; the content opt-in (Plan 4) is separate.
- **Actor doc advertises `inbox`/`outbox`/`followers`/`following`/`sharedInbox` URLs** even though those endpoints are implemented in Plans 3–4 (they 404 until then). This is required for Mastodon compatibility and is normal for an incremental build.
- **Avatar/summary (profile sync) are NOT in this plan** — the spec assigns them to the Phase-2 hardening milestone. The `Person` doc here carries `preferredUsername`, `name`, `url`, the collection URLs, `endpoints.sharedInbox`, and `publicKey`.

## Prerequisites

- Plan 1 is installed and enabled on the running dev instance (`https://humhub-develop.localhost`, admin `Admin`). The five `fediverse_*` tables exist; the global `enabled` setting is ON.
- `php` (8.5, with the `openssl` extension) and the HumHub console (`protected/yii` in the active core) are available for the human's verification steps. Agents verify PHP syntax with `php -l` and may run live `curl` against the dev URL.
- Work on branch `develop`.

---

## File structure (created by this plan)

```
fediverse/
├── config.php                          # MODIFY: add urlManagerRules + consoleControllerMap
├── services/
│   ├── FederationUrl.php               # domain/handle/actor-URL builders (pure-ish)
│   ├── DiscoveryPolicy.php             # is a user discoverable? (global switch + visibility)
│   ├── KeyManager.php                  # RSA keypair + get-or-create LocalActor (user & instance)
│   └── PersonSerializer.php            # LocalActor(+User) → ActivityPub actor array
├── controllers/
│   ├── ActorController.php             # /fediverse/actor/<guid> and /fediverse/instance-actor
│   ├── WebfingerController.php         # /.well-known/webfinger
│   └── NodeinfoController.php          # /.well-known/nodeinfo + /nodeinfo/2.1
├── commands/
│   └── FediverseController.php         # console: status / ensure-instance-actor
└── tests/codeception/unit/
    ├── FederationUrlTest.php
    ├── DiscoveryPolicyTest.php
    └── PersonSerializerTest.php
```

Reused from Plan 1: `models\LocalActor` (now gains the keypair rows), the `enabled` module setting.

---

## Task 1: `FederationUrl` — identity & URL helpers

Pure-ish helpers for domain, handle normalization, and actor URLs. Centralises every place a federation URL is built.

**Files:**
- Create: `services/FederationUrl.php`
- Test: `tests/codeception/unit/FederationUrlTest.php`

- [ ] **Step 1: Write the failing test**

`tests/codeception/unit/FederationUrlTest.php`:
```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\FederationUrl;
use tests\codeception\_support\HumHubDbTestCase;

class FederationUrlTest extends HumHubDbTestCase
{
    public function testNormalizeHandle()
    {
        $this->assertSame('john_doe', FederationUrl::normalizeHandle('john_doe'));
        $this->assertSame('john_doe', FederationUrl::normalizeHandle('John.Doe'));
        $this->assertSame('a_b_c', FederationUrl::normalizeHandle('a b-c'));
        $this->assertSame('user', FederationUrl::normalizeHandle('üser!')); // non-ascii/punct stripped
        $this->assertSame('user', FederationUrl::normalizeHandle('user@example.com'));
    }

    public function testActorUrlUsesGuid()
    {
        $url = FederationUrl::actorUrl('abc-123');
        $this->assertStringContainsString('/fediverse/actor/abc-123', $url);
        $this->assertStringStartsWith('http', $url);
    }

    public function testInstanceActorUrl()
    {
        $this->assertStringContainsString('/fediverse/instance-actor', FederationUrl::instanceActorUrl());
    }

    public function testDomainHasNoScheme()
    {
        $domain = FederationUrl::domain();
        $this->assertStringNotContainsString('://', $domain);
        $this->assertStringNotContainsString('/', $domain);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/FederationUrlTest.php -c tests -v`
Expected: FAIL — `Class "humhub\modules\fediverse\services\FederationUrl" not found`.

- [ ] **Step 3: Write `services/FederationUrl.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use Yii;
use yii\helpers\Url;

/**
 * Central builder for all federation-facing URLs and the WebFinger handle.
 */
class FederationUrl
{
    /**
     * Normalise an arbitrary HumHub username into a WebFinger/ActivityPub
     * preferredUsername: ASCII letters, digits and underscore only.
     */
    public static function normalizeHandle(string $username): string
    {
        // Cut at '@' so an email-like username keeps only the local part.
        $local = explode('@', $username)[0];
        // Replace any run of non [A-Za-z0-9_] with a single underscore, trim, lowercase.
        $handle = preg_replace('/[^A-Za-z0-9_]+/', '_', $local);
        $handle = trim((string) $handle, '_');
        return strtolower($handle);
    }

    /**
     * The bare host of this installation (no scheme, no trailing slash),
     * used as the right-hand side of a WebFinger handle.
     */
    public static function domain(): string
    {
        return (string) parse_url(self::base(), PHP_URL_HOST);
    }

    /** Absolute base URL of the site, no trailing slash. */
    public static function base(): string
    {
        $base = (string) Yii::$app->settings->get('baseUrl');
        if ($base === '') {
            $base = Url::base(true);
        }
        return rtrim($base, '/');
    }

    /** Absolute ActivityPub actor id/URL for a user GUID. */
    public static function actorUrl(string $guid): string
    {
        return self::base() . '/fediverse/actor/' . $guid;
    }

    /** Absolute ActivityPub actor id/URL for the instance (Application) actor. */
    public static function instanceActorUrl(): string
    {
        return self::base() . '/fediverse/instance-actor';
    }

    /** The shared inbox URL advertised by every actor. */
    public static function sharedInboxUrl(): string
    {
        return self::base() . '/fediverse/inbox';
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/FederationUrlTest.php -c tests -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/FederationUrl.php tests/codeception/unit/FederationUrlTest.php
git commit -m "Add FederationUrl identity and URL helpers"
```

---

## Task 2: `DiscoveryPolicy` — who is discoverable

**Files:**
- Create: `services/DiscoveryPolicy.php`
- Test: `tests/codeception/unit/DiscoveryPolicyTest.php`

- [ ] **Step 1: Write the failing test**

`tests/codeception/unit/DiscoveryPolicyTest.php`:
```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;
use Yii;

class DiscoveryPolicyTest extends HumHubDbTestCase
{
    public function testRequiresGlobalSwitchAndGuestVisibleProfile()
    {
        $module = Yii::$app->getModule('fediverse');

        $user = new User();
        $user->visibility = User::VISIBILITY_ALL;

        $module->settings->set('enabled', false);
        $this->assertFalse(DiscoveryPolicy::isDiscoverable($user), 'global off → not discoverable');

        $module->settings->set('enabled', true);
        $this->assertTrue(DiscoveryPolicy::isDiscoverable($user), 'global on + guest-visible → discoverable');

        $user->visibility = User::VISIBILITY_REGISTERED_ONLY;
        $this->assertFalse(DiscoveryPolicy::isDiscoverable($user), 'not guest-visible → not discoverable');

        $user->visibility = User::VISIBILITY_HIDDEN;
        $this->assertFalse(DiscoveryPolicy::isDiscoverable($user), 'hidden → not discoverable');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/DiscoveryPolicyTest.php -c tests -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `services/DiscoveryPolicy.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\user\models\User;
use Yii;

/**
 * Decides whether a user may be exposed as a discoverable/fetchable actor.
 * Discovery (a fetchable profile actor) is NOT content federation — content
 * still requires the per-container opt-in and the visibility invariant (§5),
 * enforced in Plan 4.
 */
class DiscoveryPolicy
{
    public static function isGloballyEnabled(): bool
    {
        return (bool) Yii::$app->getModule('fediverse')->settings->get('enabled', false);
    }

    public static function isDiscoverable(User $user): bool
    {
        if (!self::isGloballyEnabled()) {
            return false;
        }
        // Only profiles visible to guests (no login required) may federate.
        return (int) $user->visibility === User::VISIBILITY_ALL;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/DiscoveryPolicyTest.php -c tests -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/DiscoveryPolicy.php tests/codeception/unit/DiscoveryPolicyTest.php
git commit -m "Add DiscoveryPolicy gating actor exposure"
```

---

## Task 3: `KeyManager` — RSA keypairs and LocalActor provisioning

Lazily provisions a `LocalActor` (with an RSA keypair and a unique handle) for a user, and the singleton instance actor.

**Files:**
- Create: `services/KeyManager.php`

(No standalone unit test for keypair generation — it depends on OpenSSL and writes DB rows; it is exercised end-to-end by the Actor/WebFinger live checks in Tasks 5–6. `php -l` verifies syntax.)

- [ ] **Step 1: Write `services/KeyManager.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\user\models\User;
use RuntimeException;

/**
 * Provisions and retrieves the local ActivityPub actors and their RSA keypairs.
 */
class KeyManager
{
    /** Get (or lazily create) the LocalActor for a user. */
    public function getOrCreateForUser(User $user): LocalActor
    {
        $actor = LocalActor::findOne(['user_id' => $user->id]);
        if ($actor !== null) {
            return $actor;
        }

        [$privateKey, $publicKey] = $this->generateKeypair();

        $actor = new LocalActor();
        $actor->user_id = $user->id;
        $actor->type = LocalActor::TYPE_PERSON;
        $actor->preferred_username = $this->uniqueHandle($user->username);
        $actor->ap_id = FederationUrl::actorUrl($user->guid);
        $actor->private_key = $privateKey;
        $actor->public_key = $publicKey;
        if (!$actor->save()) {
            throw new RuntimeException('Could not create LocalActor: ' . implode(', ', $actor->getErrorSummary(true)));
        }
        return $actor;
    }

    /** Get (or lazily create) the singleton instance Application actor. */
    public function getOrCreateInstanceActor(): LocalActor
    {
        $actor = LocalActor::findOne(['type' => LocalActor::TYPE_APPLICATION, 'user_id' => null]);
        if ($actor !== null) {
            return $actor;
        }

        [$privateKey, $publicKey] = $this->generateKeypair();

        $actor = new LocalActor();
        $actor->user_id = null;
        $actor->type = LocalActor::TYPE_APPLICATION;
        $actor->preferred_username = $this->uniqueHandle(FederationUrl::domain());
        $actor->ap_id = FederationUrl::instanceActorUrl();
        $actor->private_key = $privateKey;
        $actor->public_key = $publicKey;
        if (!$actor->save()) {
            throw new RuntimeException('Could not create instance actor: ' . implode(', ', $actor->getErrorSummary(true)));
        }
        return $actor;
    }

    /**
     * @return array{0:string,1:string} [privateKeyPem, publicKeyPem]
     */
    public function generateKeypair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);
        return [(string) $privateKey, (string) $details['key']];
    }

    /** Derive a unique preferred_username, suffixing on collision. */
    private function uniqueHandle(string $source): string
    {
        $base = FederationUrl::normalizeHandle($source);
        if ($base === '') {
            $base = 'user';
        }
        $handle = $base;
        $i = 1;
        while (LocalActor::find()->where(['preferred_username' => $handle])->exists()) {
            $handle = $base . '_' . $i;
            $i++;
        }
        return $handle;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l services/KeyManager.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add services/KeyManager.php
git commit -m "Add KeyManager for actor keypair provisioning"
```

---

## Task 4: `PersonSerializer` — ActivityPub actor document

**Files:**
- Create: `services/PersonSerializer.php`
- Test: `tests/codeception/unit/PersonSerializerTest.php`

- [ ] **Step 1: Write the failing test**

`tests/codeception/unit/PersonSerializerTest.php`:
```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\PersonSerializer;
use tests\codeception\_support\HumHubDbTestCase;

class PersonSerializerTest extends HumHubDbTestCase
{
    public function testPersonDocumentShape()
    {
        $actor = new LocalActor();
        $actor->type = LocalActor::TYPE_PERSON;
        $actor->preferred_username = 'alice';
        $actor->ap_id = 'https://humhub.example/fediverse/actor/guid-1';
        $actor->public_key = "-----BEGIN PUBLIC KEY-----\nABC\n-----END PUBLIC KEY-----\n";

        $doc = (new PersonSerializer())->serialize($actor, 'Alice Example', 'https://humhub.example/u/alice');

        $this->assertContains('https://www.w3.org/ns/activitystreams', $doc['@context']);
        $this->assertContains('https://w3id.org/security/v1', $doc['@context']);
        $this->assertSame('Person', $doc['type']);
        $this->assertSame($actor->ap_id, $doc['id']);
        $this->assertSame('alice', $doc['preferredUsername']);
        $this->assertSame('Alice Example', $doc['name']);
        $this->assertSame('https://humhub.example/u/alice', $doc['url']);
        $this->assertSame($actor->ap_id . '/inbox', $doc['inbox']);
        $this->assertSame($actor->ap_id . '/outbox', $doc['outbox']);
        $this->assertSame($actor->ap_id . '#main-key', $doc['publicKey']['id']);
        $this->assertSame($actor->ap_id, $doc['publicKey']['owner']);
        $this->assertSame($actor->public_key, $doc['publicKey']['publicKeyPem']);
        $this->assertArrayHasKey('sharedInbox', $doc['endpoints']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/PersonSerializerTest.php -c tests -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `services/PersonSerializer.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\fediverse\models\LocalActor;

/**
 * Renders a LocalActor into an ActivityPub actor document (Person or
 * Application). The inbox/outbox/collection endpoints are advertised here but
 * implemented in later plans.
 */
class PersonSerializer
{
    /**
     * @param LocalActor $actor the local actor (its `type` selects Person/Application)
     * @param string $name human-readable display name
     * @param string $profileUrl absolute HTML profile URL
     */
    public function serialize(LocalActor $actor, string $name, string $profileUrl): array
    {
        $id = $actor->ap_id;

        return [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $id,
            'type' => $actor->type, // 'Person' | 'Application'
            'preferredUsername' => $actor->preferred_username,
            'name' => $name,
            'url' => $profileUrl,
            'inbox' => $id . '/inbox',
            'outbox' => $id . '/outbox',
            'followers' => $id . '/followers',
            'following' => $id . '/following',
            'endpoints' => [
                'sharedInbox' => FederationUrl::sharedInboxUrl(),
            ],
            'publicKey' => [
                'id' => $id . '#main-key',
                'owner' => $id,
                'publicKeyPem' => $actor->public_key,
            ],
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/PersonSerializerTest.php -c tests -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/PersonSerializer.php tests/codeception/unit/PersonSerializerTest.php
git commit -m "Add PersonSerializer for ActivityPub actor documents"
```

---

## Task 5: Actor endpoint + URL rules

**Files:**
- Create: `controllers/ActorController.php`
- Modify: `config.php` (add `urlManagerRules`)

- [ ] **Step 1: Add the URL rules to `config.php`**

Replace the whole returned array in `config.php` with:
```php
<?php

return [
    'id' => 'fediverse',
    'class' => 'humhub\modules\fediverse\Module',
    'namespace' => 'humhub\modules\fediverse',
    'events' => [
        // Event handlers are registered in later plans (outbound listeners, etc.).
    ],
    'consoleControllerMap' => [
        'fediverse' => 'humhub\modules\fediverse\commands\FediverseController',
    ],
    'urlManagerRules' => [
        ['pattern' => '.well-known/webfinger', 'route' => 'fediverse/webfinger/index'],
        ['pattern' => '.well-known/nodeinfo', 'route' => 'fediverse/nodeinfo/index'],
        ['pattern' => 'nodeinfo/2.1', 'route' => 'fediverse/nodeinfo/schema'],
        ['pattern' => 'fediverse/instance-actor', 'route' => 'fediverse/actor/instance'],
        ['pattern' => 'fediverse/actor/<guid:[\w\-]+>', 'route' => 'fediverse/actor/index'],
    ],
];
```

- [ ] **Step 2: Write `controllers/ActorController.php`**

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\PersonSerializer;
use humhub\modules\user\models\User;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ActorController extends Controller
{
    /** Actor documents are public, unauthenticated GETs. */
    public $access = null;

    public function beforeAction($action)
    {
        // Allow guest access to these endpoints regardless of site guest mode UI.
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** GET /fediverse/actor/<guid> */
    public function actionIndex($guid)
    {
        $user = User::findOne(['guid' => $guid]);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }

        $actor = (new KeyManager())->getOrCreateForUser($user);
        $doc = (new PersonSerializer())->serialize($actor, $user->displayName, $user->getUrl(true));

        return $this->asActivityJson($doc);
    }

    /** GET /fediverse/instance-actor */
    public function actionInstance()
    {
        if (!DiscoveryPolicy::isGloballyEnabled()) {
            throw new NotFoundHttpException();
        }
        $actor = (new KeyManager())->getOrCreateInstanceActor();
        $doc = (new PersonSerializer())->serialize($actor, $actor->preferred_username, \humhub\modules\fediverse\services\FederationUrl::base());

        return $this->asActivityJson($doc);
    }

    private function asActivityJson(array $doc): Response
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->headers->set('Content-Type', 'application/activity+json; charset=utf-8');
        $response->data = $doc;
        return $response;
    }
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l controllers/ActorController.php && php -l config.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add controllers/ActorController.php config.php
git commit -m "Add ActivityPub actor endpoint and URL rules"
```

- [ ] **Step 5: Live functional check (human, against the running dev instance)**

After deploying to `humhub-develop.localhost` (the module auto-loads; flush caches if URL rules don't apply: `php protected/yii cache/flush-all`):
```bash
# pick a guest-visible user's GUID, then:
curl -sS -H 'Accept: application/activity+json' \
  https://humhub-develop.localhost/fediverse/actor/<guid> | jq .
```
Expected: a JSON `Person` document with `id`, `preferredUsername`, `publicKey.publicKeyPem`, `inbox`/`outbox` URLs. A non-guest-visible user's GUID returns HTTP 404. Also:
```bash
curl -sS -H 'Accept: application/activity+json' \
  https://humhub-develop.localhost/fediverse/instance-actor | jq .type
# Expected: "Application"
```

---

## Task 6: WebFinger endpoint

**Files:**
- Create: `controllers/WebfingerController.php`

- [ ] **Step 1: Write `controllers/WebfingerController.php`**

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\user\models\User;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WebfingerController extends Controller
{
    public function beforeAction($action)
    {
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** GET /.well-known/webfinger?resource=acct:<handle>@<domain> */
    public function actionIndex($resource = null)
    {
        if (!is_string($resource) || !str_starts_with($resource, 'acct:')) {
            throw new BadRequestHttpException('resource must be an acct: URI');
        }

        // acct:handle@domain
        $acct = substr($resource, strlen('acct:'));
        [$handle, $domain] = array_pad(explode('@', $acct, 2), 2, '');
        if ($handle === '' || strcasecmp($domain, FederationUrl::domain()) !== 0) {
            throw new NotFoundHttpException();
        }

        $user = $this->resolveUserByHandle($handle);
        if ($user === null || !DiscoveryPolicy::isDiscoverable($user)) {
            throw new NotFoundHttpException();
        }

        $actor = (new KeyManager())->getOrCreateForUser($user);
        $actorUrl = $actor->ap_id;

        $jrd = [
            'subject' => 'acct:' . $actor->preferred_username . '@' . FederationUrl::domain(),
            'aliases' => [$actorUrl, $user->getUrl(true)],
            'links' => [
                [
                    'rel' => 'self',
                    'type' => 'application/activity+json',
                    'href' => $actorUrl,
                ],
                [
                    'rel' => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $user->getUrl(true),
                ],
            ],
        ];

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->headers->set('Content-Type', 'application/jrd+json; charset=utf-8');
        $response->data = $jrd;
        return $response;
    }

    /**
     * Resolve a WebFinger handle to a user: first by an existing LocalActor
     * preferred_username, then by the raw username as a fallback.
     */
    private function resolveUserByHandle(string $handle): ?User
    {
        $actor = \humhub\modules\fediverse\models\LocalActor::findOne(['preferred_username' => $handle]);
        if ($actor !== null && $actor->user_id !== null) {
            return User::findOne($actor->user_id);
        }
        return User::findOne(['username' => $handle]);
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l controllers/WebfingerController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add controllers/WebfingerController.php
git commit -m "Add WebFinger endpoint"
```

- [ ] **Step 4: Live functional check (human)**

```bash
curl -sS \
  'https://humhub-develop.localhost/.well-known/webfinger?resource=acct:<handle>@humhub-develop.localhost' \
  -H 'Accept: application/jrd+json' | jq .
```
Expected: a JRD with `subject` `acct:<handle>@humhub-develop.localhost` and a `links[].rel=self` of type `application/activity+json` pointing at `/fediverse/actor/<guid>`. Unknown handle → 404; wrong domain → 404; missing/invalid `resource` → 400.

---

## Task 7: NodeInfo endpoints

**Files:**
- Create: `controllers/NodeinfoController.php`

- [ ] **Step 1: Write `controllers/NodeinfoController.php`**

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\services\FederationUrl;
use Yii;
use yii\web\Response;

class NodeinfoController extends Controller
{
    public function beforeAction($action)
    {
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** GET /.well-known/nodeinfo — the discovery document. */
    public function actionIndex()
    {
        return $this->asJson([
            'links' => [
                [
                    'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                    'href' => FederationUrl::base() . '/nodeinfo/2.1',
                ],
            ],
        ], 'application/json; charset=utf-8');
    }

    /** GET /nodeinfo/2.1 — the NodeInfo 2.1 schema document. */
    public function actionSchema()
    {
        $module = Yii::$app->getModule('fediverse');
        $manifest = json_decode((string) @file_get_contents($module->getBasePath() . '/module.json'), true);
        $version = (string) ($manifest['version'] ?? '0.0.0');

        return $this->asJson([
            'version' => '2.1',
            'software' => [
                'name' => 'humhub-fediverse',
                'version' => $version,
            ],
            'protocols' => ['activitypub'],
            'services' => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => false,
            'usage' => [
                'users' => ['total' => 0],
                'localPosts' => 0,
            ],
            'metadata' => (object) [],
        ], 'application/json; charset=utf-8');
    }

    private function asJson(array $data, string $contentType): Response
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->headers->set('Content-Type', $contentType);
        $response->data = $data;
        return $response;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l controllers/NodeinfoController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add controllers/NodeinfoController.php
git commit -m "Add NodeInfo discovery and schema endpoints"
```

- [ ] **Step 4: Live functional check (human)**

```bash
curl -sS https://humhub-develop.localhost/.well-known/nodeinfo | jq .
curl -sS https://humhub-develop.localhost/nodeinfo/2.1 | jq '.software, .protocols'
```
Expected: discovery doc links to `/nodeinfo/2.1`; the schema doc reports `software.name = "humhub-fediverse"` and `protocols = ["activitypub"]`.

---

## Task 8: Console command (`fediverse` console controller)

A small operator command: report status and pre-create the instance actor.

**Files:**
- Create: `commands/FediverseController.php`
- (config.php already registers `consoleControllerMap` in Task 5.)

- [ ] **Step 1: Write `commands/FediverseController.php`**

```php
<?php

namespace humhub\modules\fediverse\commands;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\KeyManager;
use yii\console\Controller;
use yii\console\ExitCode;

class FediverseController extends Controller
{
    /** Usage: php protected/yii fediverse/status */
    public function actionStatus(): int
    {
        $this->stdout('Federation enabled: ' . (DiscoveryPolicy::isGloballyEnabled() ? 'yes' : 'no') . "\n");
        $this->stdout('Domain: ' . FederationUrl::domain() . "\n");
        $this->stdout('Instance actor: ' . FederationUrl::instanceActorUrl() . "\n");
        $this->stdout('Local actors: ' . LocalActor::find()->count() . "\n");
        return ExitCode::OK;
    }

    /** Usage: php protected/yii fediverse/ensure-instance-actor */
    public function actionEnsureInstanceActor(): int
    {
        $actor = (new KeyManager())->getOrCreateInstanceActor();
        $this->stdout('Instance actor ready: ' . $actor->ap_id . "\n");
        return ExitCode::OK;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l commands/FediverseController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add commands/FediverseController.php
git commit -m "Add fediverse console controller (status, instance actor)"
```

- [ ] **Step 4: Live functional check (human)**

```bash
php protected/yii fediverse/status
php protected/yii fediverse/ensure-instance-actor
```
Expected: status prints the domain and actor counts; ensure-instance-actor prints the instance actor URL and creates one `LocalActor` row with `type=Application`.

---

## Done criteria for this plan

- Unit tests green: `FederationUrlTest`, `DiscoveryPolicyTest`, `PersonSerializerTest`.
- Against the live dev instance: a guest-visible user resolves via WebFinger and serves a valid `Person` actor doc with a public key; a non-guest-visible user 404s; NodeInfo advertises ActivityPub; the instance actor is fetchable.
- **Acceptance (interop):** searching `@<handle>@humhub-develop.localhost` from a real Mastodon instance finds the profile. (Requires a reachable Mastodon test instance; otherwise validate WebFinger+actor JSON shape against a known-good Mastodon actor by hand.)

Next: **Plan 3 — Inbound core** (inbox controller, HTTP-signature verification, remote-actor resolver / federated users, `Follow` → auto-`Accept`).

## Self-review notes

- Spec coverage: actor model §4 (Person + instance Application), WebFinger/identity §11, NodeInfo (§8 endpoints) — covered. Avatar/summary deferred to the spec's Phase-2 hardening, called out explicitly.
- Open risk to confirm during implementation: that a module `urlManagerRules` pattern for `.well-known/webfinger` is matched ahead of core's `WellKnownUrlRule` (the calendar module's `/.well-known/caldav` is the working precedent). If a collision appears, fall back to a custom `UrlRuleInterface` class registered via `['class' => …]`. The live check in Task 6 will reveal this immediately.
- `Controller::$access = null` + `enableSession = false` is used to keep these endpoints public; confirm against HumHub's base `humhub\components\Controller` access behaviour during the first live check (Task 5), since federation endpoints must answer for anonymous/remote callers.
