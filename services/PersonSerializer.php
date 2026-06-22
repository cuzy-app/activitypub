<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\fediverse\models\LocalActor;

/**
 * Renders a LocalActor into an ActivityPub actor document (Person or
 * Application). The inbox/outbox/collection endpoints are advertised here but
 * implemented in later plans.
 */
class PersonSerializer
{
    /**
     * @param LocalActor $actor the local actor (its `type` selects Person/Application)
     * @param string $name human-readable display name
     * @param string $profileUrl absolute HTML profile URL
     */
    public function serialize(LocalActor $actor, string $name, string $profileUrl): array
    {
        $id = $actor->ap_id;

        return [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $id,
            'type' => $actor->type, // 'Person' | 'Application'
            'preferredUsername' => $actor->preferred_username,
            'name' => $name,
            'url' => $profileUrl,
            'inbox' => $id . '/inbox',
            'outbox' => $id . '/outbox',
            'followers' => $id . '/followers',
            'following' => $id . '/following',
            'endpoints' => [
                'sharedInbox' => FederationUrl::sharedInboxUrl(),
            ],
            'publicKey' => [
                'id' => $id . '#main-key',
                'owner' => $id,
                'publicKeyPem' => $actor->public_key,
            ],
        ];
    }
}
