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

    /**
     * Wrap an actor document in an Update activity, sent to followers when the
     * local profile (e.g. display name) changes.
     *
     * @param LocalActor $actor the local actor whose profile changed
     * @param array $document the actor document from serialize()
     * @param string $activityId a unique id for this Update activity
     */
    public function updateActivity(LocalActor $actor, array $document, string $activityId): array
    {
        return [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $activityId,
            'type' => 'Update',
            'actor' => $actor->ap_id,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => $document,
        ];
    }
}
