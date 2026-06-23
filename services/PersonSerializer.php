<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\content\widgets\richtext\RichText;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\user\models\User;

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
     * @param string|null $summary optional HTML bio (Person actors only)
     * @param array|null $icon optional avatar image object (Person actors only)
     */
    public function serialize(LocalActor $actor, string $name, string $profileUrl, ?string $summary = null, ?array $icon = null): array
    {
        $id = $actor->ap_id;

        $doc = [
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
        if ($summary !== null && $summary !== '') {
            $doc['summary'] = $summary;
        }
        if ($icon !== null) {
            $doc['icon'] = $icon;
        }
        return $doc;
    }

    /**
     * Derive a user's actor-document extras: bio (summary) and avatar (icon).
     * `summary` is null when the user has no bio. Call from contexts with a
     * request (web), so the avatar URL resolves to an absolute https URL.
     *
     * @return array{summary:?string,icon:array}
     */
    public static function profileExtras(User $user): array
    {
        $about = trim((string) ($user->profile?->about ?? ''));
        return [
            'summary' => $about !== '' ? RichText::output($about) : null,
            'icon' => ['type' => 'Image', 'url' => $user->getProfileImage()->getUrl(null, true)],
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
