<?php

use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $uri string */
/* @var $remote \humhub\modules\fediverse\models\RemoteActor|null */
/* @var $community array|null */
/* @var $error string|null */
?>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="panel panel-default">
                <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Follow a Fediverse account or community'); ?></div>
                <div class="panel-body">
                    <?php if ($error !== null) : ?>
                        <div class="alert alert-warning"><?= Html::encode($error); ?></div>
                        <?= Html::a(Yii::t('FediverseModule.base', 'Back to Fediverse settings'), ['/fediverse/settings'], ['class' => 'btn btn-default']); ?>
                    <?php elseif ($community !== null) : ?>
                        <p><?= Yii::t('FediverseModule.base', 'Do you want to join this Fediverse community?'); ?></p>
                        <p><strong><?= Html::encode($community['name']); ?></strong> <code><?= Html::encode($community['handle']); ?></code></p>
                        <?= Html::beginForm(['/fediverse/remote-interaction/index', 'uri' => $uri]); ?>
                            <?= Button::primary(Yii::t('FediverseModule.base', 'Join community'))->submit(); ?>
                            <?= Html::a(Yii::t('FediverseModule.base', 'Cancel'), ['/dashboard/dashboard'], ['class' => 'btn btn-link']); ?>
                        <?= Html::endForm(); ?>
                    <?php elseif ($remote !== null) : ?>
                        <p><?= Yii::t('FediverseModule.base', 'Do you want to follow this Fediverse account?'); ?></p>
                        <p><code>@<?= Html::encode($remote->handle); ?></code></p>
                        <?= Html::beginForm(['/fediverse/remote-interaction/index', 'uri' => $uri]); ?>
                            <?= Button::primary(Yii::t('FediverseModule.base', 'Follow'))->submit(); ?>
                            <?= Html::a(Yii::t('FediverseModule.base', 'Cancel'), ['/dashboard/dashboard'], ['class' => 'btn btn-link']); ?>
                        <?= Html::endForm(); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
