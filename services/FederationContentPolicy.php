<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\components\ContentContainerActiveRecord;
use humhub\modules\content\models\Content;
use humhub\modules\user\helpers\AuthHelper;
use humhub\modules\user\models\User;
use Yii;

/**
 * Enforces the spec §5 invariant: a post may be federated only if it is already
 * guest-visible public content in an opted-in, guest-visible user profile.
 */
class FederationContentPolicy
{
    public static function mayFederate(Content $content): bool
    {
        if (!DiscoveryPolicy::isGloballyEnabled() || !AuthHelper::isGuestAccessEnabled()) {
            return false;
        }
        if ((int) $content->visibility !== Content::VISIBILITY_PUBLIC) {
            return false;
        }
        $container = $content->getContainer();
        // Phase 4 federates user-profile posts only (Spaces become Group actors in Phase 3).
        if (!$container instanceof User) {
            return false;
        }
        if ((int) $container->visibility !== User::VISIBILITY_ALL) {
            return false;
        }
        return self::isContainerOptedIn($container);
    }

    public static function isContainerOptedIn(ContentContainerActiveRecord $container): bool
    {
        return (bool) Yii::$app->getModule('fediverse')
            ->settings->contentContainer($container)->get('federationEnabled', false);
    }
}
