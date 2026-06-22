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
