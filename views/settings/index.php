<?php

use humhub\modules\fediverse\models\FederationFollow;
use humhub\widgets\bootstrap\Button;
use yii\helpers\Html;

/* @var $this \humhub\components\View */
/* @var $handle string */
/* @var $actorUrl string */
/* @var $discoverable bool */
/* @var $discoverabilityError ?string */
/* @var $publishEnabled bool */
/* @var $follows FederationFollow[] */
?>
<div class="panel-heading">
    <?= Yii::t('FediverseModule.base', '<strong>Fediverse</strong> account'); ?>
</div>
<div class="panel-body">

    <h4><?= Yii::t('FediverseModule.base', 'Your Fediverse handle'); ?></h4>
    <p><?= Yii::t('FediverseModule.base', 'People on Mastodon and other Fediverse servers can search for this handle to follow you:'); ?></p>
    <p><code><?= Html::encode($handle); ?></code></p>
    <p><?= Html::a(Yii::t('FediverseModule.base', 'View your federation profile'), $actorUrl, ['target' => '_blank', 'rel' => 'noopener']); ?></p>
    <?php if ($discoverable) : ?>
        <div class="alert alert-success"><?= Yii::t('FediverseModule.base', 'Your profile is discoverable — remote users can follow you.'); ?></div>
    <?php else : ?>
        <div class="alert alert-warning">
            <?= Yii::t('FediverseModule.base', 'Your profile is not yet discoverable, so remote users cannot follow you.'); ?>
            <?= Html::encode((string) $discoverabilityError); ?>
        </div>
    <?php endif; ?>

    <hr>

    <h4><?= Yii::t('FediverseModule.base', 'Publish your posts'); ?></h4>
    <p><?= Yii::t('FediverseModule.base', 'When enabled, your public profile posts are delivered to your Fediverse followers.'); ?></p>
    <div class="alert alert-info">
        <?= Yii::t('FediverseModule.base', 'Heads-up: liking or commenting on federated content is also sent to the Fediverse — your like or reply is delivered to the remote author and their server, and is publicly visible there.'); ?>
    </div>
    <?= Html::beginForm(['publish']); ?>
        <div class="checkbox">
            <label>
                <?= Html::checkbox('federationEnabled', $publishEnabled); ?>
                <?= Yii::t('FediverseModule.base', 'Publish my public posts to the Fediverse'); ?>
            </label>
        </div>
        <?= Button::primary(Yii::t('FediverseModule.base', 'Save'))->submit(); ?>
    <?= Html::endForm(); ?>

    <hr>

    <h4><?= Yii::t('FediverseModule.base', 'Follow a Fediverse account or community'); ?></h4>
    <p><?= Yii::t('FediverseModule.base', 'Enter a person like {person} — their public posts appear in your dashboard — or a Lemmy community like {community} to join its mirrored Space.', ['person' => '@user@mastodon.social', 'community' => '!community@lemmy.world']); ?></p>
    <?= Html::beginForm(['follow']); ?>
        <div class="form-group">
            <?= Html::textInput('handle', '', ['class' => 'form-control', 'placeholder' => '@user@domain or !community@domain']); ?>
        </div>
        <?= Button::primary(Yii::t('FediverseModule.base', 'Follow'))->submit(); ?>
    <?= Html::endForm(); ?>

    <hr>

    <h4><?= Yii::t('FediverseModule.base', 'Accounts you follow'); ?></h4>
    <?php if (empty($follows)) : ?>
        <p class="text-muted"><?= Yii::t('FediverseModule.base', 'You are not following any Fediverse accounts yet.'); ?></p>
    <?php else : ?>
        <ul class="list-group">
            <?php foreach ($follows as $follow) : ?>
                <?php $remote = $follow->remoteActor; ?>
                <?php if ($remote === null) {
                    continue;
                } ?>
                <li class="list-group-item clearfix">
                    <code>@<?= Html::encode($remote->handle); ?></code>
                    <?php if ($follow->state === FederationFollow::STATE_PENDING) : ?>
                        <span class="label label-default"><?= Yii::t('FediverseModule.base', 'pending'); ?></span>
                    <?php else : ?>
                        <span class="label label-success"><?= Yii::t('FediverseModule.base', 'following'); ?></span>
                    <?php endif; ?>
                    <span class="pull-right">
                        <?= Html::beginForm(['unfollow']); ?>
                            <?= Html::hiddenInput('remoteActorId', $remote->id); ?>
                            <?= Button::danger(Yii::t('FediverseModule.base', 'Unfollow'))->submit(); ?>
                        <?= Html::endForm(); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

</div>
