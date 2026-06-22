<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\user\models\User;
use Yii;

/**
 * Lets a logged-in, federatable user follow a remote Fediverse account by handle.
 */
class RemoteController extends Controller
{
    public function actionIndex()
    {
        $user = Yii::$app->user->getIdentity();
        if ($user === null) {
            return $this->redirect(['/user/auth/login']);
        }

        $error = null;
        if (Yii::$app->request->isPost) {
            $handle = (string) Yii::$app->request->post('handle', '');
            if (!DiscoveryPolicy::isDiscoverable($user)) {
                $error = Yii::t('FediverseModule.base', 'Your profile must be federation-enabled (public/guest-visible) before you can follow remote accounts.');
            } else {
                try {
                    $remote = (new RemoteActorResolver())->resolveByHandle($handle);
                    $shadow = User::findOne($remote->user_id);
                    if ($shadow !== null) {
                        $shadow->follow($user->id); // fires Follow::EVENT_FOLLOWING_CREATED → outbound Follow
                        $this->view->success(Yii::t('FediverseModule.base', 'Now following {handle}.', ['handle' => $remote->handle]));
                        return $this->redirect(['index']);
                    }
                } catch (\Throwable $e) {
                    $error = Yii::t('FediverseModule.base', 'Could not resolve that account: {msg}', ['msg' => $e->getMessage()]);
                }
            }
        }

        return $this->render('index', ['error' => $error]);
    }
}
