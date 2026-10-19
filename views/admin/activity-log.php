<?php

use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $this \humhub\components\View */
/* @var $entries \humhub\modules\activitypub\models\ActivityLogEntry[] */
/* @var $enabled bool */
?>
<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('ActivitypubModule.base', 'Federation activity log'); ?></div>
    <div class="panel-body">
        <?php if (!$enabled) : ?>
            <div class="alert alert-info"><?= Yii::t('ActivitypubModule.base', 'Activity logging is disabled. Enable it in the ActivityPub settings to start recording.'); ?></div>
        <?php endif; ?>

        <p>
            <?= Html::a(Yii::t('ActivitypubModule.base', 'Back'), ['/activitypub/admin/index'], ['class' => 'btn btn-default btn-sm']); ?>
        </p>

        <?php if (!$entries) : ?>
            <p class="text-muted"><?= Yii::t('ActivitypubModule.base', 'No entries.'); ?></p>
        <?php else : ?>
            <?= Html::beginForm(['/activitypub/admin/activity-log']); ?>
                <?= Html::hiddenInput('clear', '1'); ?>
                <?= Button::danger(Yii::t('ActivitypubModule.base', 'Clear log'))->submit(); ?>
            <?= Html::endForm(); ?>

            <div style="overflow-x:auto;">
                <table class="table table-sm">
                    <thead><tr>
                        <th><?= Yii::t('ActivitypubModule.base', 'Time'); ?></th>
                        <th><?= Yii::t('ActivitypubModule.base', 'Dir'); ?></th>
                        <th><?= Yii::t('ActivitypubModule.base', 'Type'); ?></th>
                        <th><?= Yii::t('ActivitypubModule.base', 'Actor / target'); ?></th>
                        <th><?= Yii::t('ActivitypubModule.base', 'Status'); ?></th>
                        <th><?= Yii::t('ActivitypubModule.base', 'Body'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($entries as $e) : ?>
                        <tr>
                            <td style="white-space:nowrap;"><small><?= Html::encode($e->created_at); ?></small></td>
                            <td><?= Html::encode($e->direction); ?></td>
                            <td><?= Html::encode((string) $e->activity_type); ?></td>
                            <td style="word-break:break-all;"><small><?= Html::encode((string) $e->actor_uri); ?></small></td>
                            <td>
                                <?= Html::encode($e->status); ?>
                                <?php if ($e->note) : ?><br><small class="text-muted"><?= Html::encode($e->note); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($e->body) : ?>
                                    <details>
                                        <summary><?= Yii::t('ActivitypubModule.base', 'view'); ?></summary>
                                        <pre style="max-height:300px;overflow:auto;white-space:pre-wrap;word-break:break-all;"><?= Html::encode($e->body); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
