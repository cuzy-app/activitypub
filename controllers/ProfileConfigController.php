<?php

namespace humhub\modules\fediverse\controllers;

use humhub\modules\content\components\ContentContainerController;
use humhub\modules\user\models\User;
use Yii;

class ProfileConfigController extends ContentContainerController
{
    /** Only the profile owner (or an admin) may toggle federation. */
    public function actionIndex()
    {
        $container = $this->contentContainer; // set by ContentContainerController
        if (!$container instanceof User || (!$container->isCurrentUser() && !Yii::$app->user->isAdmin())) {
            throw new \yii\web\ForbiddenHttpException();
        }

        $settings = Yii::$app->getModule('fediverse')->settings->contentContainer($container);

        if (Yii::$app->request->isPost) {
            $enabled = (bool) Yii::$app->request->post('federationEnabled', false);
            $settings->set('federationEnabled', $enabled ? 1 : 0);
            $this->view->saved();
            return $this->redirect($container->createUrl('/fediverse/profile-config/index'));
        }

        return $this->render('index', [
            'container' => $container,
            'enabled' => (bool) $settings->get('federationEnabled', false),
        ]);
    }
}
