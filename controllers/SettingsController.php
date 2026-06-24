<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\DiscoveryPolicy;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\user\models\User;
use Yii;

/**
 * Consolidated per-user Fediverse settings (User → Settings → Fediverse):
 * identity/handle, publish opt-in, follow remote accounts, and the following list.
 */
class SettingsController extends Controller
{
    public function init()
    {
        parent::init();
        // Render inside the account-settings layout (left settings sidebar).
        $this->subLayout = '@humhub/modules/user/views/account/_layout';
    }

    protected function getAccessRules()
    {
        return [['login']];
    }

    public function actionIndex()
    {
        $user = Yii::$app->user->getIdentity();
        $settings = Yii::$app->getModule('fediverse')->settings->contentContainer($user);

        $follows = FederationFollow::find()
            ->where([
                'local_user_id' => $user->id,
                'direction' => FederationFollow::DIRECTION_OUTBOUND,
            ])
            ->andWhere(['!=', 'state', FederationFollow::STATE_REJECTED])
            ->with('remoteActor')
            ->orderBy(['id' => SORT_DESC])
            ->all();

        return $this->render('index', [
            'handle' => '@' . FederationUrl::normalizeHandle($user->username) . '@' . FederationUrl::domain(),
            'actorUrl' => FederationUrl::actorUrl($user->guid),
            'discoverable' => DiscoveryPolicy::isDiscoverable($user),
            'publishEnabled' => (bool) $settings->get('federationEnabled', false),
            'follows' => $follows,
        ]);
    }

    public function actionPublish()
    {
        $this->forcePostRequest();
        $user = Yii::$app->user->getIdentity();
        $settings = Yii::$app->getModule('fediverse')->settings->contentContainer($user);
        $settings->set('federationEnabled', Yii::$app->request->post('federationEnabled') ? 1 : 0);
        $this->view->saved();
        return $this->redirect(['index']);
    }

    public function actionFollow()
    {
        $this->forcePostRequest();
        $user = Yii::$app->user->getIdentity();
        if (!DiscoveryPolicy::isDiscoverable($user)) {
            $this->view->error(Yii::t('FediverseModule.base', 'Your profile must be discoverable (visible to all, including guests) before you can follow remote accounts.'));
            return $this->redirect(['index']);
        }
        $handle = (string) Yii::$app->request->post('handle', '');
        try {
            $remote = (new RemoteActorResolver())->resolveByHandle($handle);
            $shadow = User::findOne($remote->user_id);
            if ($shadow !== null) {
                $shadow->follow($user->id); // fires Follow::EVENT_FOLLOWING_CREATED → outbound Follow
                $this->view->success(Yii::t('FediverseModule.base', 'Now following {handle}.', ['handle' => $remote->handle]));
            }
        } catch (\Throwable $e) {
            $this->view->error(Yii::t('FediverseModule.base', 'Could not resolve that account: {msg}', ['msg' => $e->getMessage()]));
        }
        return $this->redirect(['index']);
    }

    public function actionUnfollow()
    {
        $this->forcePostRequest();
        $user = Yii::$app->user->getIdentity();
        $remote = RemoteActor::findOne((int) Yii::$app->request->post('remoteActorId', 0));
        if ($remote !== null) {
            $shadow = User::findOne($remote->user_id);
            if ($shadow !== null) {
                $shadow->unfollow($user->id); // fires followRemoved → outbound Undo{Follow}
                $this->view->success(Yii::t('FediverseModule.base', 'Unfollowed {handle}.', ['handle' => $remote->handle]));
            }
        }
        return $this->redirect(['index']);
    }
}
