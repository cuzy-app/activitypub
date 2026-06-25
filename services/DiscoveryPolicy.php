<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\user\helpers\AuthHelper;
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
        return self::discoverabilityError($user) === null;
    }

    /**
     * The specific reason $user is not discoverable, or null if they are. The three
     * prerequisites are distinct so the message is actionable: a "make your profile
     * visible to all" hint is useless while guest access is globally off, because the
     * "members+guests" visibility option is then not even offered (see
     * User::getVisibilityOptions()).
     */
    public static function discoverabilityError(User $user): ?string
    {
        if (!self::isGloballyEnabled()) {
            return Yii::t('FediverseModule.base', 'Federation is currently disabled for this site. An administrator must enable it in the Fediverse module settings before you can follow remote accounts.');
        }
        if (!AuthHelper::isGuestAccessEnabled()) {
            return Yii::t('FediverseModule.base', 'This site does not allow guest access, so no profile can be exposed to the Fediverse. An administrator must enable guest access (Administration → Settings → Users) first.');
        }
        // Only profiles visible to guests (no login required) may federate.
        if ((int) $user->visibility !== User::VISIBILITY_ALL) {
            return Yii::t('FediverseModule.base', 'Your profile must be visible to all (including guests) before you can follow remote accounts. Set "Visibility" to "Visible for members+guests" in your account settings.');
        }
        return null;
    }
}
