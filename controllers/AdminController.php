<?php

namespace humhub\modules\activitypub\controllers;

use humhub\modules\admin\components\Controller;
use humhub\modules\activitypub\models\ActivityLogEntry;
use humhub\modules\activitypub\models\ConfigureForm;
use humhub\modules\activitypub\models\FederationFollow;
use humhub\modules\activitypub\models\FederationObject;
use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\activitypub\models\RemoteActor;
use humhub\modules\activitypub\services\FederationUrl;
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
            Yii::t('ActivitypubModule.base', 'Domain') => FederationUrl::domain(),
            Yii::t('ActivitypubModule.base', 'Local profile actors') =>
                (int) LocalActor::find()->where(['type' => LocalActor::TYPE_PERSON])->count(),
            Yii::t('ActivitypubModule.base', 'Remote actors (federated users)') =>
                (int) RemoteActor::find()->count(),
            Yii::t('ActivitypubModule.base', 'Followers (inbound)') =>
                (int) FederationFollow::find()->where(['direction' => FederationFollow::DIRECTION_INBOUND, 'state' => FederationFollow::STATE_ACCEPTED])->count(),
            Yii::t('ActivitypubModule.base', 'Following (outbound, accepted)') =>
                (int) FederationFollow::find()->where(['direction' => FederationFollow::DIRECTION_OUTBOUND, 'state' => FederationFollow::STATE_ACCEPTED])->count(),
            Yii::t('ActivitypubModule.base', 'Following (outbound, pending)') =>
                (int) FederationFollow::find()->where(['direction' => FederationFollow::DIRECTION_OUTBOUND, 'state' => FederationFollow::STATE_PENDING])->count(),
            Yii::t('ActivitypubModule.base', 'Federated posts (outgoing)') =>
                (int) FederationObject::find()->where(['direction' => FederationObject::DIRECTION_LOCAL, 'object_type' => 'Note', 'model_class' => Post::class])->count(),
            Yii::t('ActivitypubModule.base', 'Ingested posts (incoming)') =>
                (int) FederationObject::find()->where(['direction' => FederationObject::DIRECTION_REMOTE, 'object_type' => 'Note', 'model_class' => Post::class])->count(),
        ];
    }

    public function actionActivityLog()
    {
        if (Yii::$app->request->isPost && Yii::$app->request->post('clear')) {
            ActivityLogEntry::deleteAll();
            $this->view->success(Yii::t('ActivitypubModule.base', 'Log cleared.'));
            return $this->redirect(['activity-log']);
        }

        return $this->render('activity-log', [
            'entries' => ActivityLogEntry::find()->orderBy(['id' => SORT_DESC])->limit(100)->all(),
            'enabled' => (bool) Yii::$app->getModule('activitypub')->settings->get('activityLogEnabled', false),
        ]);
    }
}
