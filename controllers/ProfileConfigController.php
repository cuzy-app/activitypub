<?php

namespace humhub\modules\fediverse\controllers;

use humhub\components\Controller;
use Yii;

/**
 * Lets the logged-in user opt their own profile in/out of federation.
 */
class ProfileConfigController extends Controller
{
    public function actionIndex()
    {
        $user = Yii::$app->user->getIdentity();
        if ($user === null) {
            return $this->redirect(['/user/auth/login']);
        }

        $settings = Yii::$app->getModule('fediverse')->settings->contentContainer($user);

        if (Yii::$app->request->isPost) {
            $settings->set('federationEnabled', Yii::$app->request->post('federationEnabled') ? 1 : 0);
            $this->view->saved();
            return $this->redirect(['index']);
        }

        return $this->render('index', [
            'enabled' => (bool) $settings->get('federationEnabled', false),
        ]);
    }
}
