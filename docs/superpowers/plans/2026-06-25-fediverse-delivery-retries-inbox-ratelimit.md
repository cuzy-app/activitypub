# Fediverse — delivery retries + inbox rate limit (hardening)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. **DO NOT COMMIT — the controller commits on the human's explicit word.**

**Goal:** Make outbound delivery survive transient failures (retry per-inbox without duplicating), and add a DoS backstop on the inbound inbox.

**Mechanics (verified):**
- HumHub's queue only retries jobs implementing `yii\queue\RetryableJobInterface` (queue `Events.php` drops non-retryable jobs on attempt > 1). So a job must (a) implement that interface and (b) THROW on a transient failure for the queue to retry it.
- `ActivityDelivery::deliver()` returns `bool` (2xx); throws on unsafe URL; the HTTP client's `send()` throws on connection/transport errors. Callers: `DeliverToInbox`, `DeliverActivity` (inline fan-out), `ProcessInboxActivity::sendAccept` (inline).
- `DeliverActivity` fans out inline to every follower inbox in one job → retrying it would re-blast all. Fix: enqueue one `DeliverToInbox` per (deduped) inbox; make `DeliverToInbox` the retryable unit.
- `InboxController::accept()` enqueues a `ProcessInboxActivity` per POST with no throttle.

---

## Task 1: deliver() returns the HTTP status

**Modify** `services/ActivityDelivery.php` — change the return type and final return:
- signature `public function deliver(...): bool` → `: int`
- `return $response->getIsOk();` → `return $response->getStatusCode();`
- update the `@return` docblock to "HTTP status code (throws on unsafe URL / transport error)".

(After Task 2/3, the only caller of `deliver()` is `DeliverToInbox`.)

## Task 2: retryable DeliverToInbox

**Modify** `jobs/DeliverToInbox.php`:
```php
<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\services\ActivityDelivery;
use humhub\modules\fediverse\services\UrlSafety;
use humhub\modules\queue\ActiveJob;
use RuntimeException;
use Yii;
use yii\queue\RetryableJobInterface;

/**
 * Delivers one signed activity to one remote inbox. Retryable: a transient
 * failure (5xx / connection error) throws so the queue retries it; a permanent
 * failure (4xx) is logged and dropped. Per-inbox granularity means a retry never
 * re-delivers to inboxes that already succeeded.
 */
class DeliverToInbox extends ActiveJob implements RetryableJobInterface
{
    public int $signerUserId = 0;
    public string $inboxUrl = '';
    public string $activityJson = '';

    public function getTtr()
    {
        return 120;
    }

    public function canRetry($attempt, $error)
    {
        return $attempt < 3; // up to 3 attempts (only transient failures reach here)
    }

    public function run()
    {
        $signer = LocalActor::findOne(['user_id' => $this->signerUserId]);
        $activity = json_decode($this->activityJson, true);
        if ($signer === null || !is_array($activity) || $this->inboxUrl === '') {
            return; // nothing deliverable — permanent, no retry
        }
        if (!UrlSafety::isSafePublicUrl($this->inboxUrl)) {
            Yii::warning('Fediverse delivery: refusing unsafe inbox URL ' . $this->inboxUrl, 'fediverse');
            return; // permanent
        }

        // A transport/connection error throws out of deliver() → propagates →
        // queue retries (transient).
        $status = (new ActivityDelivery())->deliver($signer, $this->inboxUrl, $activity);

        if ($status >= 200 && $status < 300) {
            return; // delivered
        }
        if ($status >= 400 && $status < 500) {
            Yii::warning('Fediverse delivery ' . $status . ' (permanent) to ' . $this->inboxUrl, 'fediverse');
            return; // 4xx incl. 410 Gone — do not retry
        }
        // 5xx / other → transient: throw so the queue retries per canRetry().
        throw new RuntimeException('Fediverse delivery transient failure (' . $status . ') to ' . $this->inboxUrl);
    }
}
```
(`UrlSafety` is same-namespace `services`, referenced with a `use`. Confirm the class name/namespace via an existing import.)

## Task 3: fan-out + Accept enqueue per inbox

**Modify** `jobs/DeliverActivity.php` `run()` — replace the inline `$delivery->deliver(...)` loop with enqueuing one `DeliverToInbox` per deduped inbox (keep the signer-validity check and the eager-loaded follows):
```php
        $delivered = [];
        foreach ($follows as $follow) {
            $remote = $follow->remoteActor;
            if ($remote === null) {
                continue;
            }
            $inbox = $remote->shared_inbox_url ?: $remote->inbox_url;
            if ($inbox === null || $inbox === '' || isset($delivered[$inbox])) {
                continue;
            }
            $delivered[$inbox] = true;
            Yii::$app->queue->push(new DeliverToInbox([
                'signerUserId' => $signerUserId,
                'inboxUrl' => $inbox,
                'activityJson' => $this->activityJson,
            ]));
        }
```
(Remove the now-unused `ActivityDelivery`/`$delivery` usage in this file; add `use humhub\modules\fediverse\jobs\DeliverToInbox;` if needed — same namespace, so no import.)

**Modify** `jobs/ProcessInboxActivity.php` `sendAccept()` — replace the inline `deliver()` with enqueuing a `DeliverToInbox` (so the Accept is retryable too):
```php
        $inbox = $remote->inbox_url ?: $remote->shared_inbox_url;
        if ($inbox === '') {
            return;
        }
        Yii::$app->queue->push(new DeliverToInbox([
            'signerUserId' => (int) $localUser->id,
            'inboxUrl' => $inbox,
            'activityJson' => json_encode($accept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
```
(Add `use humhub\modules\fediverse\jobs\DeliverToInbox;` to ProcessInboxActivity — different namespace branch; it's `jobs\` and the file is in `jobs\`, so same namespace, no import. Remove the now-unused `ActivityDelivery` import if nothing else uses it.)

## Task 4: inbox rate limit (DoS backstop)

**Modify** `controllers/InboxController.php` `accept()` — before enqueuing, throttle per source IP via cache:
```php
        // DoS backstop: cap inbox POSTs per source IP. Generous — legitimate
        // senders (Mastodon) back off and retry on 429, so bursts are not lost.
        $ip = (string) Yii::$app->request->getUserIP();
        $key = 'fediverse.inbox.rl.' . md5($ip);
        $window = (int) Yii::$app->cache->get($key);
        if ($window >= 300) { // ~5/s sustained per IP
            Yii::$app->response->statusCode = 429;
            Yii::$app->response->format = Response::FORMAT_RAW;
            return Yii::$app->response;
        }
        Yii::$app->cache->set($key, $window + 1, 60);
```
(Place at the start of `accept()`, after the `isGloballyEnabled` check. `Response` is already imported.)

## Task 5: CHANGELOG
```markdown
- Enh: Outbound delivery is resilient — each activity is delivered per-inbox by a
  retryable job that retries transient failures (5xx / connection errors, up to 3
  attempts) and drops permanent ones (4xx/410), without re-delivering to inboxes
  that already succeeded. The inbox endpoint has a per-IP rate-limit backstop.
```

---

## Deploy + verification (controller + human)
1. `php -l` all; flush cache.
2. (controller) Live: enqueue a `DeliverToInbox` to a known-good inbox (e.g. cmburns) and confirm it still delivers (2xx, no warning); enqueue one to a bad host and confirm it retries then gives up (transient) / logs once (4xx).
3. **Admin:** post/like/follow as before → still federates to followers (now via per-inbox jobs). cmburns still sees it.
4. Inbox rate limit: hammering the inbox from one IP past the window returns 429; normal traffic unaffected.

## Self-review risks
- **Core delivery path:** the only `deliver()` caller after the refactor is `DeliverToInbox`; signature change is contained. Verify no other caller remains.
- **No duplicates on retry:** per-inbox jobs mean a retry only re-hits the failed inbox; receivers also dedupe by activity `id`.
- **Retry only transient:** 4xx/410/unsafe-URL never retry (avoids hammering dead inboxes); only 5xx/connection errors do, capped at 3 attempts.
- **Rate limit safety:** generous (300/IP/min) and senders retry on 429, so legitimate bursts are not dropped; it only caps a single-source flood.
- **Accept reliability:** moving sendAccept to a queued DeliverToInbox decouples it from inbox processing and makes it retryable.
