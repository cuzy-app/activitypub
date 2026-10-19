<?php

namespace humhub\modules\activitypub\jobs;

use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\activitypub\services\ActivityDelivery;
use humhub\modules\activitypub\services\ActivityLog;
use humhub\modules\activitypub\services\UrlSafety;
use humhub\modules\queue\ActiveJob;
use RuntimeException;
use Yii;
use yii\queue\RetryableJobInterface;

/**
 * Delivers one signed activity to one remote inbox (directed activities and the
 * per-inbox fan-out). Retryable: a transient failure (5xx / connection error)
 * throws so the queue retries it; a permanent failure (4xx/410) is logged and
 * dropped. Per-inbox granularity means a retry never re-delivers to inboxes that
 * already succeeded.
 */
class DeliverToInbox extends ActiveJob implements RetryableJobInterface
{
    public int $signerUserId = 0;
    public int $signerActorId = 0; // LocalActor PK; takes precedence over signerUserId (e.g. a Space Group actor)
    public string $inboxUrl = '';
    public string $activityJson = '';

    public function getTtr()
    {
        return 120;
    }

    public function canRetry($attempt, $error)
    {
        return $attempt < 3; // up to 3 attempts; only transient failures reach here
    }

    public function run()
    {
        $signer = $this->signerActorId > 0
            ? LocalActor::findOne(['id' => $this->signerActorId])
            : LocalActor::findOne(['user_id' => $this->signerUserId]);
        $activity = json_decode($this->activityJson, true);
        if ($signer === null || !is_array($activity) || $this->inboxUrl === '') {
            return; // nothing deliverable — permanent, no retry
        }
        if (!UrlSafety::isSafePublicUrl($this->inboxUrl)) {
            Yii::warning('ActivityPub delivery: refusing unsafe inbox URL ' . $this->inboxUrl, 'activitypub');
            return; // permanent
        }

        // A transport/connection error throws out of deliver() → propagates → the
        // queue retries it (transient).
        $status = (new ActivityDelivery())->deliver($signer, $this->inboxUrl, $activity);

        $type = $activity['type'] ?? null;
        if ($status >= 200 && $status < 300) {
            ActivityLog::record('out', $type, $this->inboxUrl, $this->activityJson, 'delivered');
            return; // delivered
        }
        if ($status >= 400 && $status < 500) {
            Yii::warning('ActivityPub delivery ' . $status . ' (permanent) to ' . $this->inboxUrl, 'activitypub');
            ActivityLog::record('out', $type, $this->inboxUrl, $this->activityJson, 'failed_permanent', (string) $status);
            return; // 4xx incl. 410 Gone — do not retry
        }
        // 5xx / other → transient: throw so the queue retries per canRetry().
        ActivityLog::record('out', $type, $this->inboxUrl, $this->activityJson, 'failed_transient', (string) $status);
        throw new RuntimeException('ActivityPub delivery transient failure (' . $status . ') to ' . $this->inboxUrl);
    }
}
