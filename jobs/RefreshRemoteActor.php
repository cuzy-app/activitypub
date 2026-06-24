<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\RemoteActorResolver;
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
