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
 * Delivers one signed activity to one remote inbox (directed activities and the
 * per-inbox fan-out). Retryable: a transient failure (5xx / connection error)
 * throws so the queue retries it; a permanent failure (4xx/410) is logged and
 * dropped. Per-inbox granularity means a retry never re-delivers to inboxes that
 * already succeeded.
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
        return $attempt < 3; // up to 3 attempts; only transient failures reach here
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

        // A transport/connection error throws out of deliver() → propagates → the
        // queue retries it (transient).
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
