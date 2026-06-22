# Fediverse Phase 3 — Inbound Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let remote Fediverse servers deliver activities to HumHub: an authenticated inbox that verifies HTTP Signatures, resolves remote actors into local "federated" shadow users, and completes the `Follow` → `Accept` handshake so Mastodon users can follow a HumHub user.

**Architecture:** Public inbox controllers (`/fediverse/inbox` shared, `/fediverse/actor/<guid>/inbox` per-user) accept POSTs, respond `202`, and enqueue a queue job. The job verifies the HTTP Signature (fetching the remote actor's public key via an SSRF-guarded HTTP fetch), then dispatches by activity type. `Follow` creates a `FederationFollow` row and sends a signed `Accept` back to the remote inbox. All crypto is hand-rolled over OpenSSL (no new dependency).

**Tech Stack:** PHP 8 + OpenSSL (`openssl_sign`/`openssl_verify`), Yii2, `humhub\libs\HttpClient` (yii2-httpclient), HumHub queue (`ActiveJob`), Codeception.

---

## Roadmap position (plan 3 of 5)

1. Foundation ✅ (shipped, verified live)
2. Identity & Discovery ✅ (shipped, verified live)
3. **Inbound core** *(this plan)*
4. Outbound — per-container opt-in + §5 invariant, content serializers (`Create{Note}` etc.), delivery, outbox
5. Inbound interactions (`Create{Note,inReplyTo}`/`Like` → comments/likes) & local→remote follow

Spec: `docs/superpowers/specs/2026-06-22-fediverse-module-design.md` (§7 remote representation, §11 security, §12 error handling).

## Decisions locked for this plan

- **Hand-rolled HTTP Signatures over OpenSSL** (draft-cavage, the de-facto Mastodon style) — `openssl_sign`/`openssl_verify` are the "small crypto lib" from the spec. No new Composer dependency.
- **Remote actor fetch uses an UNSIGNED GET.** Authorized-fetch (signing GETs with the instance actor for Mastodon "secure mode") is a later hardening item; most servers serve actor docs unsigned.
- **Inbound `Follow` is auto-`Accept`ed** (spec §12 — content is public-only anyway). `Accept` is signed with the followed *user's* key (`LocalActor` from Plan 2).
- **Scope:** this plan handles `Follow` and `Undo{Follow}`. Inbound `Create`/`Like` (comments/likes) are Plan 5; unknown types are logged and ignored.
- **Federated shadow users:** `User` with `status = STATUS_ENABLED`, `visibility = VISIBILITY_HIDDEN` (excluded from People directory but valid as a content author later), a `Profile` with names from the actor, and default-group membership. No password/auth record → cannot log in.

## Prerequisites

- Plans 1–2 shipped on `develop` (tables, `LocalActor`, `RemoteActor`, `FederationFollow`, `FederationObject`, `KeyManager`, `FederationUrl`, actor/WebFinger endpoints).
- Running dev instance auto-loads the module. `php` 8.5 with `openssl`. Live end-to-end inbound testing needs a federating peer (a second instance or real Mastodon) and is therefore partially deferred; the crypto and parsing are covered by unit tests (sign↔verify round-trip with a generated keypair).

---

## File structure (created by this plan)

```
fediverse/
├── config.php                          # MODIFY: add inbox URL rules
├── services/
│   ├── UrlSafety.php                   # SSRF guard for outbound fetches
│   ├── SignatureService.php            # build/sign/verify HTTP Signatures + Digest
│   ├── RemoteActorResolver.php         # fetch remote actor → RemoteActor + shadow User
│   └── ActivityDelivery.php            # sign + POST an activity to a remote inbox
├── controllers/
│   └── InboxController.php             # shared + per-user inbox (POST)
├── jobs/
│   └── ProcessInboxActivity.php        # verify signature, dispatch Follow/Undo
└── tests/codeception/unit/
    ├── UrlSafetyTest.php
    └── SignatureServiceTest.php
```

Reused: `models\RemoteActor`, `models\FederationFollow`, `models\LocalActor`, `services\KeyManager`, `services\FederationUrl`.

---

## Task 1: `UrlSafety` — SSRF guard

**Files:**
- Create: `services/UrlSafety.php`
- Test: `tests/codeception/unit/UrlSafetyTest.php`

- [ ] **Step 1: Write the failing test**

`tests/codeception/unit/UrlSafetyTest.php`:
```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\UrlSafety;
use tests\codeception\_support\HumHubDbTestCase;

class UrlSafetyTest extends HumHubDbTestCase
{
    public function testRejectsNonHttpsAndMalformed()
    {
        $this->assertFalse(UrlSafety::isSafePublicUrl('not a url'));
        $this->assertFalse(UrlSafety::isSafePublicUrl('http://example.com/actor')); // not https
        $this->assertFalse(UrlSafety::isSafePublicUrl('ftp://example.com'));
    }

    public function testRejectsPrivateAndLoopbackLiterals()
    {
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://127.0.0.1/x'));
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://10.0.0.5/x'));
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://192.168.1.10/x'));
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://169.254.169.254/x')); // link-local metadata
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://[::1]/x'));
    }

    public function testAllowsPublicHttps()
    {
        $this->assertTrue(UrlSafety::isSafePublicUrl('https://mastodon.social/users/alice'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/UrlSafetyTest.php -c tests -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `services/UrlSafety.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

/**
 * Guards outbound fetches against SSRF: only public, https hosts that do not
 * resolve to private/loopback/link-local/reserved IP ranges are allowed.
 */
class UrlSafety
{
    public static function isSafePublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = trim($parts['host'], '[]'); // strip brackets from IPv6 literal

        $ips = self::resolve($host);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }
        return true;
    }

    /** @return string[] resolved IPs (or the literal IP if host is already an IP) */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        return $ips;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/UrlSafetyTest.php -c tests -v`
Expected: PASS. (Note: `testAllowsPublicHttps` performs a DNS lookup; if the test environment has no DNS, mark it skipped — the private-range assertions use IP literals and need no DNS.)

- [ ] **Step 5: Commit**

```bash
git add services/UrlSafety.php tests/codeception/unit/UrlSafetyTest.php
git commit -m "Add UrlSafety SSRF guard"
```

---

## Task 2: `SignatureService` — HTTP Signatures + Digest

Builds and verifies draft-cavage HTTP Signatures and the `Digest` header.

**Files:**
- Create: `services/SignatureService.php`
- Test: `tests/codeception/unit/SignatureServiceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/codeception/unit/SignatureServiceTest.php`:
```php
<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\SignatureService;
use tests\codeception\_support\HumHubDbTestCase;

class SignatureServiceTest extends HumHubDbTestCase
{
    public function testDigestMatchesKnownVector()
    {
        // base64(sha256("")) is the well-known empty-string digest.
        $this->assertSame(
            'SHA-256=47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=',
            SignatureService::digest('')
        );
    }

    public function testSignThenVerifyRoundTrip()
    {
        [$priv, $pub] = (new KeyManager())->generateKeypair();
        $svc = new SignatureService();

        $method = 'POST';
        $path = '/fediverse/inbox';
        $body = '{"type":"Follow"}';
        $headers = [
            'host' => 'fedihub.social',
            'date' => 'Mon, 22 Jun 2026 17:00:00 GMT',
            'digest' => SignatureService::digest($body),
        ];
        $headerList = ['(request-target)', 'host', 'date', 'digest'];

        $signingString = $svc->buildSigningString($method, $path, $headers, $headerList);
        $this->assertStringContainsString('(request-target): post /fediverse/inbox', $signingString);

        $sigB64 = $svc->signString($signingString, $priv);
        $this->assertNotSame('', $sigB64);

        $this->assertTrue($svc->verifyString($signingString, $sigB64, $pub));
        $this->assertFalse($svc->verifyString($signingString . 'x', $sigB64, $pub));
    }

    public function testParseSignatureHeader()
    {
        $header = 'keyId="https://x/actor#main-key",algorithm="rsa-sha256",headers="(request-target) host date",signature="QUJD"';
        $parsed = SignatureService::parseSignatureHeader($header);
        $this->assertSame('https://x/actor#main-key', $parsed['keyId']);
        $this->assertSame('(request-target) host date', $parsed['headers']);
        $this->assertSame('QUJD', $parsed['signature']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `codecept run unit unit/SignatureServiceTest.php -c tests -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `services/SignatureService.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use RuntimeException;

/**
 * Hand-rolled draft-cavage HTTP Signature signing/verification plus the
 * RFC-3230 `Digest` header (SHA-256). rsa-sha256 only (what Mastodon uses).
 */
class SignatureService
{
    /** `SHA-256=<base64(sha256(body))>` */
    public static function digest(string $body): string
    {
        return 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    }

    /** Parse a `Signature:` header into its key="value" components. */
    public static function parseSignatureHeader(string $header): array
    {
        $out = [];
        if (preg_match_all('/(\w+)="([^"]*)"/', $header, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $out[$pair[1]] = $pair[2];
            }
        }
        return $out;
    }

    /**
     * Build the signing string for the given ordered header list.
     * `(request-target)` becomes "<method-lowercased> <path>".
     *
     * @param array<string,string> $headers lowercased-name => value
     * @param string[] $headerList ordered names, e.g. ['(request-target)','host','date','digest']
     */
    public function buildSigningString(string $method, string $path, array $headers, array $headerList): string
    {
        $lines = [];
        foreach ($headerList as $name) {
            $name = strtolower($name);
            if ($name === '(request-target)') {
                $lines[] = '(request-target): ' . strtolower($method) . ' ' . $path;
            } else {
                $lines[] = $name . ': ' . ($headers[$name] ?? '');
            }
        }
        return implode("\n", $lines);
    }

    /** RSA-SHA256 sign, returns base64 signature. */
    public function signString(string $signingString, string $privateKeyPem): string
    {
        $signature = '';
        if (!openssl_sign($signingString, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('openssl_sign failed: ' . openssl_error_string());
        }
        return base64_encode($signature);
    }

    /** RSA-SHA256 verify of a base64 signature. */
    public function verifyString(string $signingString, string $signatureB64, string $publicKeyPem): bool
    {
        $raw = base64_decode($signatureB64, true);
        if ($raw === false) {
            return false;
        }
        return openssl_verify($signingString, $raw, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `codecept run unit unit/SignatureServiceTest.php -c tests -v`
Expected: PASS (digest vector, sign↔verify round-trip, header parse).

- [ ] **Step 5: Commit**

```bash
git add services/SignatureService.php tests/codeception/unit/SignatureServiceTest.php
git commit -m "Add SignatureService for HTTP Signatures and Digest"
```

---

## Task 3: `RemoteActorResolver` — fetch remote actor → shadow user

**Files:**
- Create: `services/RemoteActorResolver.php`

(Exercised end-to-end by the inbox job; `php -l` for syntax. No standalone unit test — it performs network + DB writes.)

- [ ] **Step 1: Write `services/RemoteActorResolver.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\libs\HttpClient;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\user\models\Profile;
use humhub\modules\user\models\User;
use RuntimeException;
use Yii;

/**
 * Resolves a remote ActivityPub actor URL into a cached RemoteActor row backed
 * by a local "federated" shadow User (login disabled, hidden from directory).
 */
class RemoteActorResolver
{
    /** Get the cached RemoteActor for an actor URL, or fetch + create it. */
    public function resolve(string $actorUrl): RemoteActor
    {
        $existing = RemoteActor::findOne(['ap_id' => $actorUrl]);
        if ($existing !== null) {
            return $existing;
        }

        $doc = $this->fetchActorDocument($actorUrl);

        $user = $this->createShadowUser($doc, $actorUrl);

        $remote = new RemoteActor();
        $remote->user_id = $user->id;
        $remote->ap_id = $actorUrl;
        $remote->type = (string) ($doc['type'] ?? 'Person');
        $remote->preferred_username = isset($doc['preferredUsername']) ? (string) $doc['preferredUsername'] : null;
        $remote->domain = (string) parse_url($actorUrl, PHP_URL_HOST);
        $remote->handle = ($remote->preferred_username ?? 'user') . '@' . $remote->domain;
        $remote->inbox_url = (string) ($doc['inbox'] ?? '');
        $remote->shared_inbox_url = $doc['endpoints']['sharedInbox'] ?? null;
        $remote->outbox_url = $doc['outbox'] ?? null;
        $remote->public_key = $doc['publicKey']['publicKeyPem'] ?? null;
        $remote->icon_url = is_array($doc['icon'] ?? null) ? ($doc['icon']['url'] ?? null) : null;
        $remote->last_refreshed_at = date('Y-m-d H:i:s');

        if ($remote->inbox_url === '' || $remote->public_key === null) {
            throw new RuntimeException('Remote actor missing inbox or publicKey: ' . $actorUrl);
        }
        if (!$remote->save()) {
            throw new RuntimeException('Could not save RemoteActor: ' . implode(', ', $remote->getErrorSummary(true)));
        }
        return $remote;
    }

    /** Fetch and JSON-decode an actor document (SSRF-guarded). */
    public function fetchActorDocument(string $actorUrl): array
    {
        if (!UrlSafety::isSafePublicUrl($actorUrl)) {
            throw new RuntimeException('Refusing to fetch unsafe URL: ' . $actorUrl);
        }

        $client = new HttpClient();
        $response = $client->createRequest()
            ->setMethod('GET')
            ->setUrl($actorUrl)
            ->addHeaders(['Accept' => 'application/activity+json'])
            ->setOptions([CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5])
            ->send();

        if (!$response->getIsOk()) {
            throw new RuntimeException('Actor fetch failed (' . $response->getStatusCode() . '): ' . $actorUrl);
        }

        $data = json_decode($response->getContent(), true);
        if (!is_array($data) || empty($data['id'])) {
            throw new RuntimeException('Invalid actor document: ' . $actorUrl);
        }
        return $data;
    }

    private function createShadowUser(array $doc, string $actorUrl): User
    {
        $domain = (string) parse_url($actorUrl, PHP_URL_HOST);
        $preferred = isset($doc['preferredUsername']) ? (string) $doc['preferredUsername'] : 'user';

        $user = new User();
        $user->username = $this->uniqueUsername($preferred . '@' . $domain);
        $user->status = User::STATUS_ENABLED;
        $user->visibility = User::VISIBILITY_HIDDEN; // not listed in People directory
        if (!$user->save()) {
            throw new RuntimeException('Could not create shadow user: ' . implode(', ', $user->getErrorSummary(true)));
        }

        $name = trim((string) ($doc['name'] ?? '')) ?: $preferred;
        $profile = $user->profile;
        $profile->firstname = mb_substr($name, 0, 255);
        $profile->lastname = '';
        $profile->save();

        $defaultGroup = Yii::$app->getModule('user')->getDefaultGroup();
        if ($defaultGroup !== null) {
            $defaultGroup->addUser($user);
        }

        return $user;
    }

    private function uniqueUsername(string $base): string
    {
        $candidate = mb_substr($base, 0, 97);
        $i = 1;
        while (User::find()->where(['username' => $candidate])->exists()) {
            $candidate = mb_substr($base, 0, 90) . '_' . $i;
            $i++;
        }
        return $candidate;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l services/RemoteActorResolver.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add services/RemoteActorResolver.php
git commit -m "Add RemoteActorResolver creating federated shadow users"
```

---

## Task 4: `ActivityDelivery` — sign + POST an activity to a remote inbox

**Files:**
- Create: `services/ActivityDelivery.php`

- [ ] **Step 1: Write `services/ActivityDelivery.php`**

```php
<?php

namespace humhub\modules\fediverse\services;

use humhub\libs\HttpClient;
use humhub\modules\fediverse\models\LocalActor;
use RuntimeException;

/**
 * Signs an activity with a LocalActor's key and POSTs it to a remote inbox,
 * using draft-cavage HTTP Signatures over (request-target) host date digest.
 */
class ActivityDelivery
{
    private SignatureService $signatures;

    public function __construct(?SignatureService $signatures = null)
    {
        $this->signatures = $signatures ?? new SignatureService();
    }

    /**
     * @param LocalActor $signer the local actor whose key signs the request
     * @param string $inboxUrl absolute remote inbox URL
     * @param array $activity the activity to deliver (JSON-LD array)
     * @return bool true on a 2xx response
     */
    public function deliver(LocalActor $signer, string $inboxUrl, array $activity): bool
    {
        if (!UrlSafety::isSafePublicUrl($inboxUrl)) {
            throw new RuntimeException('Refusing to deliver to unsafe URL: ' . $inboxUrl);
        }

        $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parts = parse_url($inboxUrl);
        $host = $parts['host'] ?? '';
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = SignatureService::digest($body);

        $headerList = ['(request-target)', 'host', 'date', 'digest'];
        $signingString = $this->signatures->buildSigningString('POST', $path, [
            'host' => $host,
            'date' => $date,
            'digest' => $digest,
        ], $headerList);

        $signatureB64 = $this->signatures->signString($signingString, (string) $signer->private_key);
        $signatureHeader = sprintf(
            'keyId="%s#main-key",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $signer->ap_id,
            implode(' ', $headerList),
            $signatureB64
        );

        $client = new HttpClient();
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($inboxUrl)
            ->addHeaders([
                'Host' => $host,
                'Date' => $date,
                'Digest' => $digest,
                'Content-Type' => 'application/activity+json',
                'Signature' => $signatureHeader,
            ])
            ->setContent($body)
            ->setOptions([CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5])
            ->send();

        return $response->getIsOk();
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l services/ActivityDelivery.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add services/ActivityDelivery.php
git commit -m "Add ActivityDelivery for signed activity POSTs"
```

---

## Task 5: Inbox controller + URL rules

**Files:**
- Create: `controllers/InboxController.php`
- Modify: `config.php` (add inbox rules)

- [ ] **Step 1: Add inbox URL rules to `config.php`**

In `config.php`, add these two entries to the `urlManagerRules` array (place them BEFORE the existing `fediverse/actor/<guid:[\w\-]+>` rule so the more specific `/inbox` suffix matches first):
```php
        ['pattern' => 'fediverse/inbox', 'route' => 'fediverse/inbox/shared', 'verb' => ['POST']],
        ['pattern' => 'fediverse/actor/<guid:[\w\-]+>/inbox', 'route' => 'fediverse/inbox/user', 'verb' => ['POST']],
```

- [ ] **Step 2: Write `controllers/InboxController.php`**

```php
<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\jobs\ProcessInboxActivity;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class InboxController extends Controller
{
    /** Public, unauthenticated, machine-to-machine endpoint. */
    public $access = null;
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        Yii::$app->user->enableSession = false;
        return parent::beforeAction($action);
    }

    /** POST /fediverse/inbox (shared inbox) */
    public function actionShared()
    {
        return $this->accept(null);
    }

    /** POST /fediverse/actor/<guid>/inbox (per-user inbox) */
    public function actionUser($guid)
    {
        return $this->accept($guid);
    }

    private function accept(?string $targetGuid): Response
    {
        if (!DiscoveryPolicy::isGloballyEnabled()) {
            throw new BadRequestHttpException('Federation disabled');
        }

        $rawBody = Yii::$app->request->getRawBody();
        $signature = Yii::$app->request->headers->get('signature');
        if ($rawBody === '' || $signature === null) {
            throw new BadRequestHttpException('Missing body or signature');
        }

        // Capture only the headers the verifier needs; respond fast, do work in the job.
        $headers = [
            'signature' => $signature,
            'date' => (string) Yii::$app->request->headers->get('date'),
            'digest' => (string) Yii::$app->request->headers->get('digest'),
            'host' => (string) Yii::$app->request->headers->get('host'),
            'content-type' => (string) Yii::$app->request->headers->get('content-type'),
        ];

        Yii::$app->queue->push(new ProcessInboxActivity([
            'rawBody' => $rawBody,
            'headers' => $headers,
            'requestPath' => Yii::$app->request->getUrl(),
            'targetGuid' => $targetGuid,
        ]));

        Yii::$app->response->statusCode = 202; // Accepted
        Yii::$app->response->format = Response::FORMAT_RAW;
        return Yii::$app->response;
    }
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l controllers/InboxController.php && php -l config.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add controllers/InboxController.php config.php
git commit -m "Add inbox controller and POST URL rules"
```

- [ ] **Step 5: Live smoke check (human)**

A POST with no signature must be rejected fast (the route exists and reaches the controller):
```bash
curl -sS -o /dev/null -w "%{http_code}\n" -X POST \
  -H 'Content-Type: application/activity+json' \
  --data '{"type":"Follow"}' \
  https://humhub-develop.localhost/fediverse/inbox
# Expected: 400 (missing signature) — NOT 404/405. Confirms routing + CSRF-off + access.
```

---

## Task 6: `ProcessInboxActivity` job — verify & dispatch

**Files:**
- Create: `jobs/ProcessInboxActivity.php`

- [ ] **Step 1: Write `jobs/ProcessInboxActivity.php`**

```php
<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\ActivityDelivery;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\fediverse\services\SignatureService;
use humhub\modules\queue\ActiveJob;
use humhub\modules\user\models\User;
use Yii;

/**
 * Verifies an inbound activity's HTTP Signature, then dispatches by type.
 * Phase 3 handles Follow and Undo{Follow}; other types are logged and ignored.
 */
class ProcessInboxActivity extends ActiveJob
{
    public string $rawBody = '';
    public array $headers = [];
    public string $requestPath = '';
    public ?string $targetGuid = null;

    public function run()
    {
        $activity = json_decode($this->rawBody, true);
        if (!is_array($activity) || empty($activity['type']) || empty($activity['actor'])) {
            Yii::warning('Fediverse inbox: malformed activity', 'fediverse');
            return;
        }

        $actorUrl = is_array($activity['actor']) ? ($activity['actor']['id'] ?? '') : (string) $activity['actor'];
        if ($actorUrl === '') {
            return;
        }

        $resolver = new RemoteActorResolver();
        $remote = $resolver->resolve($actorUrl);

        if (!$this->verifySignature($remote)) {
            Yii::warning('Fediverse inbox: signature verification failed for ' . $actorUrl, 'fediverse');
            return;
        }

        switch ($activity['type']) {
            case 'Follow':
                $this->handleFollow($activity, $remote);
                break;
            case 'Undo':
                $this->handleUndo($activity, $remote);
                break;
            default:
                Yii::info('Fediverse inbox: ignoring unsupported type ' . $activity['type'], 'fediverse');
        }
    }

    private function verifySignature(RemoteActor $remote): bool
    {
        $parsed = SignatureService::parseSignatureHeader($this->headers['signature'] ?? '');
        if (empty($parsed['signature']) || empty($parsed['headers'])) {
            return false;
        }

        // Verify the body Digest first (if it was part of the signed set).
        $headerList = explode(' ', $parsed['headers']);
        if (in_array('digest', $headerList, true)) {
            if (!hash_equals(SignatureService::digest($this->rawBody), (string) ($this->headers['digest'] ?? ''))) {
                return false;
            }
        }

        $svc = new SignatureService();
        $signingString = $svc->buildSigningString('POST', $this->requestPath, [
            'host' => (string) ($this->headers['host'] ?? ''),
            'date' => (string) ($this->headers['date'] ?? ''),
            'digest' => (string) ($this->headers['digest'] ?? ''),
            'content-type' => (string) ($this->headers['content-type'] ?? ''),
        ], $headerList);

        return $svc->verifyString($signingString, $parsed['signature'], (string) $remote->public_key);
    }

    private function handleFollow(array $activity, RemoteActor $remote): void
    {
        $localUser = $this->resolveFollowTarget($activity['object'] ?? null);
        if ($localUser === null) {
            Yii::warning('Fediverse Follow: unknown target object', 'fediverse');
            return;
        }

        $follow = FederationFollow::findOne([
            'local_user_id' => $localUser->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
        ]);
        if ($follow === null) {
            $follow = new FederationFollow();
            $follow->local_user_id = $localUser->id;
            $follow->remote_actor_id = $remote->id;
            $follow->direction = FederationFollow::DIRECTION_INBOUND;
        }
        $follow->state = FederationFollow::STATE_ACCEPTED; // auto-accept (spec §12)
        $follow->activity_ap_id = $activity['id'] ?? null;
        $follow->save();

        $this->sendAccept($localUser, $remote, $activity);
    }

    private function handleUndo(array $activity, RemoteActor $remote): void
    {
        $inner = $activity['object'] ?? null;
        if (!is_array($inner) || ($inner['type'] ?? '') !== 'Follow') {
            return; // only Undo{Follow} in this phase
        }
        $localUser = $this->resolveFollowTarget($inner['object'] ?? null);
        if ($localUser === null) {
            return;
        }
        FederationFollow::deleteAll([
            'local_user_id' => $localUser->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
        ]);
    }

    /** Resolve a Follow `object` (our actor URL) to the local User. */
    private function resolveFollowTarget($object): ?User
    {
        $url = is_array($object) ? ($object['id'] ?? '') : (string) $object;
        if ($url === '') {
            return null;
        }
        $actor = LocalActor::findOne(['ap_id' => $url]);
        if ($actor === null || $actor->user_id === null) {
            return null;
        }
        return User::findOne($actor->user_id);
    }

    private function sendAccept(User $localUser, RemoteActor $remote, array $followActivity): void
    {
        $signer = LocalActor::findOne(['user_id' => $localUser->id]);
        if ($signer === null) {
            return;
        }

        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $signer->ap_id . '/accepts/' . bin2hex(random_bytes(8)),
            'type' => 'Accept',
            'actor' => $signer->ap_id,
            'object' => [
                'id' => $followActivity['id'] ?? null,
                'type' => 'Follow',
                'actor' => $remote->ap_id,
                'object' => $signer->ap_id,
            ],
        ];

        $inbox = $remote->shared_inbox_url ?: $remote->inbox_url;
        try {
            (new ActivityDelivery())->deliver($signer, $inbox, $accept);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Accept delivery failed: ' . $e->getMessage(), 'fediverse');
        }
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l jobs/ProcessInboxActivity.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add jobs/ProcessInboxActivity.php
git commit -m "Add inbox processing job with Follow/Accept handling"
```

---

## Done criteria for this plan

- Unit tests green: `UrlSafetyTest`, `SignatureServiceTest` (incl. sign↔verify round-trip and the empty-string digest vector).
- Live smoke: POST to `/fediverse/inbox` without a signature returns **400** (route + CSRF-off + public access confirmed); POST is not 404/405.
- **Deferred to a federating peer (Plan 5 / public deployment):** a full inbound `Follow` from a real Mastodon — verify a `FederationFollow` row (state `accepted`) is created and an `Accept` is delivered so the remote shows the follow as confirmed. Until a peer exists, the crypto path is covered by the round-trip unit test.

## Known follow-ups (not in this plan)

- **Authorized-fetch:** signing the actor-document GET with the instance actor (for Mastodon "secure mode" peers that refuse unsigned GETs).
- **Date-skew rejection** on inbound (reject signatures with a `Date` outside a tolerance window) — currently the signature simply must verify.
- **Delivery retries/backoff** and the `fediverse_delivery_log` are wired in Plan 4 (outbound), which generalises `ActivityDelivery` into queued, retried delivery.
- Lazy shadow-user/keypair rate-limiting (carried over from Plan 2's deferred hardening).

## Self-review note — the #1 live failure mode

HTTP Signature verification reconstructs the signed string from the request's `host` header and `(request-target)` (= `post <path>`). Behind the docker-dev Caddy reverse proxy these MUST equal what the remote actually signed:

- **`host`**: the remote signs `host: fedihub.social`; PHP must see that same value (not an internal upstream host). Confirm Yii's `request->headers->get('host')` returns the public host — set HumHub/Yii trusted-host / `X-Forwarded-Host` handling if Caddy rewrites it.
- **`(request-target)` path**: `Yii::$app->request->getUrl()` must equal the path the remote POSTed to (`/fediverse/inbox` or `/fediverse/actor/<guid>/inbox`) — watch for proxy path rewrites or a stripped prefix.

If signatures fail in the first peer test, check these two before anything else. (This is why the round-trip unit test passes but a real peer can still fail — the unit test controls both sides.)

## Post-review corrections (applied to shipped code)

The two-stage review found several real security issues; these deltas were applied (commit `06c9a7e`) on top of the task code above:

- **Verify-before-persist (was: persist-before-verify).** `ProcessInboxActivity` now `authenticate()`s first and only calls `resolveFromDocument()` (which creates the shadow `User`/`RemoteActor`) *after* the signature verifies. Previously an unauthenticated POST could create ghost users.
- **Key from `keyId`, with actor binding.** The public key is fetched from the `Signature` header's `keyId` URL (not the body), and the signer's `id` must equal the activity `actor` — else reject. (Previously `keyId` was parsed but unused.)
- **`digest` is mandatory** in the signed header set (reject if absent) so the body is always authenticated; plus a **Date-freshness window** (≤1 h old, ≤5 min future) for basic replay resistance.
- **`FederationHttpClient`** (new) — `humhub\libs\HttpClient::beforeSend()` *replaces* request options with `CURLHelper` defaults, silently dropping per-request timeouts. The subclass re-applies hardening **after** `parent::beforeSend()`: `CURLOPT_FOLLOWLOCATION => false` (closes SSRF-via-redirect), HTTPS-only, and enforced timeouts. Both `RemoteActorResolver` and `ActivityDelivery` use it.
- **Shadow-user email.** HumHub `user.emailRequired` defaults to `true`, so the shadow `User` now gets a deterministic synthetic address `remote-<sha1(actorUrl)>@federated.invalid` (the `.invalid` TLD never routes). Without this, every inbound activity failed on save. Plus: `profile->save()` return is checked/logged, and a concurrent-creation race is caught (`IntegrityException`).

**Deferred hardening (documented, not done):** DNS-rebinding (check-then-connect TOCTOU) — mitigated in practice by disabled redirects + the host pre-check, and the fetched body is never reflected to the caller (blind SSRF). Full pinning via `CURLOPT_RESOLVE` is a later item.

**Verified live:** `POST /fediverse/inbox` without a signature returns **400** on the public `https://test.fedihub.social` (route active, CSRF disabled, public access, rejection path correct). The full inbound `Follow` → `Accept` handshake awaits a real peer follow (now possible against the public instance — see the next session's interop test).

Next: **Plan 4 — Outbound** (per-container opt-in + §5 invariant, `Create{Note}`/`Update`/`Delete`/`Like` serializers, queued delivery to followers, outbox).
