<?php

use humhub\modules\ui\icon\widgets\Icon;
use yii\helpers\Html;

/* @var $url string */
/* @var $likes int|null */
/* @var $shares int|null */
?>
<span class="text-muted" title="<?= Html::encode(Yii::t('FediverseModule.base', 'Engagement on the original post (Fediverse)')); ?>">
    <?= Icon::get('globe'); ?>
    <?php if ($likes !== null) : ?>
        &nbsp;<?= Icon::get('star'); ?> <?= Yii::$app->formatter->asInteger($likes); ?>
    <?php endif; ?>
    <?php if ($shares !== null) : ?>
        &nbsp;<?= Icon::get('retweet'); ?> <?= Yii::$app->formatter->asInteger($shares); ?>
    <?php endif; ?>
    &middot; <?= Html::a(Yii::t('FediverseModule.base', 'View original'), $url, ['target' => '_blank', 'rel' => 'noopener']); ?>
</span>
