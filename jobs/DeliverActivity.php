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
    public int $signerUserId = 0;
    public string $activityJson = '';

    public function run()
    {
        // The signer may differ from the follower-set owner (e.g. user B replies on
        // user A's post → sign as B, but fan out to A's followers).
        $signerUserId = $this->signerUserId ?: $this->localUserId;
        $signer = LocalActor::findOne(['user_id' => $signerUserId]);
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
