<?php

namespace humhub\modules\fediverse\widgets;

use humhub\components\Widget;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\user\models\User;

/** Sidebar panel shown on a federated shadow user's profile: origin link + metadata. */
class RemoteAccountInfo extends Widget
{
    public $user;

    public function run()
    {
        if (!$this->user instanceof User) {
            return '';
        }
        $remote = RemoteActor::findOne(['user_id' => $this->user->id]);
        if ($remote === null) {
            return '';
        }
        return $this->render('remoteAccountInfo', ['remote' => $remote]);
    }
}
