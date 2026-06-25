<?php

use humhub\widgets\bootstrap\Button;
use humhub\widgets\form\ActiveForm;
use humhub\modules\fediverse\models\ConfigureForm;
use yii\helpers\Html;

/* @var $this \humhub\components\View */
/* @var $model ConfigureForm */
/* @var $stats array */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Fediverse'); ?></div>
    <div class="panel-body">
        <?php if (!$model->isGuestAccessEnabled()) : ?>
            <div class="alert alert-warning">
                <?= Yii::t('FediverseModule.base', 'Guest access is currently disabled. Federation only publishes content that is visible to guests, so nothing will be federated until you enable guest access in the user settings.'); ?>
            </div>
        <?php endif; ?>

        <?php $form = ActiveForm::begin(); ?>
            <?= $form->field($model, 'enabled')->checkbox(); ?>
            <?= $form->field($model, 'activityLogEnabled')->checkbox(); ?>
            <?= $form->field($model, 'remoteUserGroupId')
                ->dropDownList($model->getGroupOptions(), ['prompt' => Yii::t('FediverseModule.base', '(none)')])
                ->hint(Yii::t('FediverseModule.base', 'Federated (remote) users are added to this group, so the People directory can be filtered by local vs. remote.')); ?>
            <?= Button::save()->submit(); ?>
        <?php ActiveForm::end(); ?>

        <hr>
        <p><?= Html::a(Yii::t('FediverseModule.base', 'View federation activity log'), ['/fediverse/admin/activity-log'], ['class' => 'btn btn-default btn-sm']); ?></p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Statistics'); ?></div>
    <div class="panel-body">
        <table class="table table-sm">
            <?php foreach ($stats as $label => $value) : ?>
                <tr>
                    <th style="width:55%;"><?= Html::encode((string) $label); ?></th>
                    <td><?= Html::encode((string) $value); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
