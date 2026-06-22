<?php

use humhub\libs\Html;
use humhub\widgets\Button;
use humhub\modules\fediverse\models\ConfigureForm;
use yii\bootstrap\ActiveForm;

/* @var $model ConfigureForm */
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
            <?= Button::save()->submit(); ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
