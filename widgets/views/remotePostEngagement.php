<?php

use yii\helpers\Html;

/* @var $url string */
/* @var $likes int|null */
/* @var $shares int|null */

$parts = [];
if ($likes !== null) {
    $parts[] = Html::encode(Yii::t('FediverseModule.base', 'Federated Likes ({n})', ['n' => Yii::$app->formatter->asInteger($likes)]));
}
if ($shares !== null) {
    $parts[] = Html::encode(Yii::t('FediverseModule.base', 'Federated Boosts ({n})', ['n' => Yii::$app->formatter->asInteger($shares)]));
}
$parts[] = Html::a(Yii::t('FediverseModule.base', 'Open original') . ' ↗', $url, ['target' => '_blank', 'rel' => 'noopener']);
?>
<span class="text-muted" style="font-size:11px;"><?= implode(' &middot; ', $parts); ?></span>
