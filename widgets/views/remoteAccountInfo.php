<?php

use yii\helpers\Html;

/* @var $remote \humhub\modules\fediverse\models\RemoteActor */

$url = $remote->profile_url ?: $remote->ap_id;
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('FediverseModule.base', 'Remote account'); ?></div>
    <div class="panel-body">
        <p><?= Yii::t('FediverseModule.base', 'This is a profile from the Fediverse, mirrored here. The full profile lives on its home server.'); ?></p>
        <p style="overflow-wrap:anywhere;word-break:break-word;">
            <strong><?= Html::a(Html::encode('@' . $remote->handle), $url, ['target' => '_blank', 'rel' => 'noopener']); ?></strong>
        </p>
        <p>
            <?= Html::a(Yii::t('FediverseModule.base', 'View full profile') . ' ↗', $url, [
                'target' => '_blank',
                'rel' => 'noopener',
                'class' => 'btn btn-default btn-sm',
                'style' => 'white-space:normal;max-width:100%;',
            ]); ?>
        </p>
        <?php if ($remote->followers_count !== null || $remote->following_count !== null) : ?>
            <hr>
            <small class="text-muted">
                <?php if ($remote->followers_count !== null) : ?>
                    <?= Yii::t('FediverseModule.base', '{n} followers', ['n' => Yii::$app->formatter->asInteger($remote->followers_count)]); ?>
                <?php endif; ?>
                <?php if ($remote->following_count !== null) : ?>
                    &middot; <?= Yii::t('FediverseModule.base', '{n} following', ['n' => Yii::$app->formatter->asInteger($remote->following_count)]); ?>
                <?php endif; ?>
            </small>
        <?php endif; ?>
    </div>
</div>
