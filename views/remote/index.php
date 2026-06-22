<?php

use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $this \humhub\components\View */
/* @var $error string|null */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Follow a Fediverse account'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'Enter a handle like <code>@user@mastodon.social</code> to follow it. Their public posts will appear in your dashboard.'); ?></p>
        <?php if ($error !== null) : ?>
            <div class="alert alert-danger"><?= Html::encode($error); ?></div>
        <?php endif; ?>
        <?= Html::beginForm(); ?>
            <div class="form-group">
                <?= Html::textInput('handle', '', ['class' => 'form-control', 'placeholder' => '@user@domain']); ?>
            </div>
            <?= Button::primary(Yii::t('FediverseModule.base', 'Follow'))->submit(); ?>
        <?= Html::endForm(); ?>
    </div>
</div>
