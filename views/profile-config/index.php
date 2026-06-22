<?php

use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $this \humhub\components\View */
/* @var $container \humhub\modules\user\models\User */
/* @var $enabled bool */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Fediverse federation'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'When enabled, your public profile posts are published to the Fediverse and can be followed from Mastodon and other servers.'); ?></p>
        <?= Html::beginForm($container->createUrl('/fediverse/profile-config/index'), 'post'); ?>
            <div class="checkbox">
                <label>
                    <?= Html::checkbox('federationEnabled', $enabled); ?>
                    <?= Yii::t('FediverseModule.base', 'Federate my public profile posts'); ?>
                </label>
            </div>
            <?= Button::save()->submit(); ?>
        <?= Html::endForm(); ?>
    </div>
</div>
