<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\user\models\User;
use Yii;

/**
 * Decides whether a user may be exposed as a discoverable/fetchable actor.
 * Discovery (a fetchable profile actor) is NOT content federation — content
 * still requires the per-container opt-in and the visibility invariant (§5),
 * enforced in Plan 4.
 */
class DiscoveryPolicy
{
    public static function isGloballyEnabled(): bool
    {
        return (bool) Yii::$app->getModule('fediverse')->settings->get('enabled', false);
    }

    public static function isDiscoverable(User $user): bool
    {
        if (!self::isGloballyEnabled()) {
            return false;
        }
        // Only profiles visible to guests (no login required) may federate.
        return (int) $user->visibility === User::VISIBILITY_ALL;
    }
}
