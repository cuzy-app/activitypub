<?php

namespace humhub\modules\activitypub\jobs;

use humhub\modules\activitypub\models\RemoteActor;
use humhub\modules\activitypub\services\RemoteActorResolver;
use humhub\modules\queue\ActiveJob;

/** Best-effort background refresh of a remote actor's cached data (profile + follower/following counts). */
class RefreshRemoteActor extends ActiveJob
{
    public int $remoteActorId = 0;

    public function run()
    {
        $remote = RemoteActor::findOne($this->remoteActorId);
        if ($remote !== null) {
            (new RemoteActorResolver())->refresh($remote);
        }
    }
}
