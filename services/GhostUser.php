<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\user\models\User;
use RuntimeException;
use Yii;

/**
 * The single global, login-disabled local system user that owns/admins every
 * federated community Space. Its id is stored in the module setting
 * `ghostUserId`; an admin may point it at a different user. It is an ordinary
 * local user (never opts into federation, authors no content) — no shadow-user
 * or UserSource machinery.
 */
class GhostUser
{
    public const SETTING = 'ghostUserId';

    /** The configured ghost user, or null if unset/missing. */
    public static function find(): ?User
    {
        $id = (int) Yii::$app->getModule('fediverse')->settings->get(self::SETTING, 0);
        return $id > 0 ? User::findOne($id) : null;
    }

    /** Get the ghost user, creating + persisting it (and the setting) on first use. */
    public static function getOrCreate(): User
    {
        $existing = self::find();
        if ($existing !== null) {
            return $existing;
        }

        $user = new User();
        $user->username = self::uniqueUsername('Fediverse');
        $user->email = 'fediverse-system@' . FederationUrl::domain();
        $user->status = User::STATUS_ENABLED;
        $user->visibility = User::VISIBILITY_HIDDEN; // off the People directory
        $user->allowAssignDefaultGroup(false);
        if (!$user->save()) {
            throw new RuntimeException('Could not create Fediverse ghost user: ' . implode(', ', $user->getErrorSummary(true)));
        }

        $profile = $user->profile;
        $profile->firstname = 'Fediverse';
        $profile->lastname = 'System';
        $profile->save(false);

        // No password auth is created → the account cannot be logged into.

        Yii::$app->getModule('fediverse')->settings->set(self::SETTING, (int) $user->id);
        return $user;
    }

    private static function uniqueUsername(string $base): string
    {
        $candidate = $base;
        $i = 1;
        while (User::find()->where(['username' => $candidate])->exists()) {
            $candidate = $base . '_' . $i;
            $i++;
        }
        return $candidate;
    }
}
