<?php

namespace humhub\modules\activitypub\services;

use humhub\modules\user\models\User;
use humhub\modules\user\source\BaseUserSource;
use humhub\modules\user\source\UserSourceInterface;

/**
 * UserSource governing federated (remote ActivityPub) shadow users. These users
 * are provisioned by RemoteActorResolver on inbound activity (not via a login
 * flow), tagged with user.user_source = self::ID. The source makes their synced
 * profile fields read-only, forbids self-deletion, and (via the core per-source
 * email-optional support) lets them exist without an email address.
 */
class FederatedUserSource extends BaseUserSource
{
    public const ID = 'activitypub';

    /** Name of the user group federated shadow users are assigned to (for People filtering). */
    public const REMOTE_GROUP_NAME = 'Remote Users';

    public string $id = self::ID;
    public string $title = 'Fediverse';
    public array $managedAttributes = ['firstname', 'lastname', 'about'];
    public bool $deleteAccount = false; // federated users cannot self-delete
    public string $usernameStrategy = UserSourceInterface::USERNAME_AUTO_GENERATE;

    /** Federated users have no email. */
    public function isEmailRequired(): bool
    {
        return false;
    }

    /**
     * Not used: federated shadow users are created by RemoteActorResolver in
     * response to inbound activity, not via the standard provisioning flow.
     */
    public function createUser(array $attributes): ?User
    {
        return null;
    }
}
