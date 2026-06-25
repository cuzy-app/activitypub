<?php

namespace humhub\modules\fediverse\controllers;

use humhub\modules\admin\components\Controller;
use humhub\modules\fediverse\models\ActivityLogEntry;
use humhub\modules\fediverse\models\ConfigureForm;
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
        ]);
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
