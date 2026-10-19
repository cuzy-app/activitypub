<?php

namespace humhub\modules\activitypub\services;

use humhub\modules\content\widgets\richtext\RichText;
use humhub\modules\activitypub\models\LocalActor;
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
     * @param array|null $image optional banner image object (Person actors only)
     */
    public function serialize(LocalActor $actor, string $name, string $profileUrl, ?string $summary = null, ?array $icon = null, ?array $image = null): array
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
        if ($image !== null) {
            $doc['image'] = $image;
        }
        return $doc;
    }

    /**
     * Derive a user's actor-document extras: bio (summary), avatar (icon) and
     * banner (image). `summary`/`image` are null when the user has no bio/banner.
     * Call from contexts with a request (web), so the image URLs resolve to
     * absolute https URLs.
     *
     * @return array{summary:?string,icon:array,image:?array}
     */
    public static function profileExtras(User $user): array
    {
        $about = trim((string) ($user->profile?->about ?? ''));
        return [
            'summary' => $about !== '' ? RichText::convert($about, RichText::FORMAT_HTML) : null,
            'icon' => ['type' => 'Image', 'url' => $user->getProfileImage()->getUrl(null, true)],
            // Only advertise a banner the user actually set, else Mastodon would show a blank header.
            'image' => $user->bannerImage->exists()
                ? ['type' => 'Image', 'url' => $user->bannerImage->getUrl(null, true)]
                : null,
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
