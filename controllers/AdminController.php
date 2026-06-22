<?php

namespace humhub\modules\fediverse\controllers;

use humhub\modules\admin\components\Controller;
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
}
