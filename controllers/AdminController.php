<?php

namespace humhub\modules\fediverse\controllers;

use humhub\modules\admin\components\Controller;
use humhub\modules\fediverse\models\ActivityLogEntry;
use humhub\modules\fediverse\models\ConfigureForm;
use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\FederationObject;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\post\models\Post;
use Yii;

class AdminController extends Controller
{
    public function actionIndex()
    {
        $model = new ConfigureForm();
        $model->loadSettings();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $this->view->saved();
            return $this->redirect(['index']);
        }

        return $this->render('index', [
            'model' => $model,
            'stats' => $this->gatherStats(),
        ]);
    }

    /** A few basic federation counters for the admin page. */
    private function gatherStats(): array
    {
        return [
            Yii::t('FediverseModule.base', 'Domain') => FederationUrl::domain(),
            Yii::t('FediverseModule.base', 'Local profile actors') =>
                (int) LocalActor::find()->where(['type' => LocalActor::TYPE_PERSON])->count(),
            Yii::t('FediverseModule.base', 'Remote actors (federated users)') =>
                (int) RemoteActor::find()->count(),
            Yii::t('FediverseModule.base', 'Followers (inbound)') =>
                (int) FederationFollow::find()->where(['direction' => FederationFollow::DIRECTION_INBOUND, 'state' => FederationFollow::STATE_ACCEPTED])->count(),
            Yii::t('FediverseModule.base', 'Following (outbound, accepted)') =>
                (int) FederationFollow::find()->where(['direction' => FederationFollow::DIRECTION_OUTBOUND, 'state' => FederationFollow::STATE_ACCEPTED])->count(),
            Yii::t('FediverseModule.base', 'Following (outbound, pending)') =>
                (int) FederationFollow::find()->where(['direction' => FederationFollow::DIRECTION_OUTBOUND, 'state' => FederationFollow::STATE_PENDING])->count(),
            Yii::t('FediverseModule.base', 'Federated posts (outgoing)') =>
                (int) FederationObject::find()->where(['direction' => FederationObject::DIRECTION_LOCAL, 'object_type' => 'Note', 'model_class' => Post::class])->count(),
            Yii::t('FediverseModule.base', 'Ingested posts (incoming)') =>
                (int) FederationObject::find()->where(['direction' => FederationObject::DIRECTION_REMOTE, 'object_type' => 'Note', 'model_class' => Post::class])->count(),
        ];
    }

    public function actionActivityLog()
    {
        if (Yii::$app->request->isPost && Yii::$app->request->post('clear')) {
            ActivityLogEntry::deleteAll();
            $this->view->success(Yii::t('FediverseModule.base', 'Log cleared.'));
            return $this->redirect(['activity-log']);
        }

        return $this->render('activity-log', [
            'entries' => ActivityLogEntry::find()->orderBy(['id' => SORT_DESC])->limit(100)->all(),
            'enabled' => (bool) Yii::$app->getModule('fediverse')->settings->get('activityLogEnabled', false),
        ]);
    }
}
