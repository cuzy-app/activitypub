<?php

namespace humhub\modules\activitypub\services;

use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\activitypub\models\SpaceCommunity;
use humhub\modules\activitypub\jobs\DeliverToInbox;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use RuntimeException;
use Yii;

/**
 * Subscribes HumHub users to remote Lemmy-family communities. Each community is
 * mirrored 1:1 into a public HumHub Space owned by the global ghost user; the
 * first subscriber provisions it, later subscribers just join. The Space's Group
 * actor sends a single signed Follow to the community; the community's Accept
 * (handled in ProcessInboxActivity) flips follow_state to accepted.
 */
class CommunitySubscriptionService
{
    /** Resolve a handle and provision-or-join its Space. Network path (used by UI/console). */
    public function subscribe(User $subscriber, string $handle): Space
    {
        $doc = (new CommunityResolver())->resolveByHandle($handle);
        return $this->provisionOrJoin($subscriber, $doc, (string) $doc['id'], $handle);
    }

    /**
     * Given an already-resolved community Group document, either join the
     * existing coupled Space or provision a new one. Network-free except the
     * outbound Follow on first provisioning.
     */
    public function provisionOrJoin(User $subscriber, array $communityDoc, string $communityApId, string $handle): Space
    {
        $coupling = SpaceCommunity::findOne(['community_ap_id' => $communityApId]);
        if ($coupling !== null) {
            $space = Space::findOne(['id' => $coupling->space_id]);
            if ($space === null) {
                throw new RuntimeException('Coupling references a missing Space: ' . $communityApId);
            }
            $space->addMember((int) $subscriber->id);
            return $space;
        }

        $space = null;
        $coupling = null;

        // Provision the Space + coupling atomically: a partial failure would
        // otherwise leave an orphan Space (unique name) with no coupling to
        // dedup against, so a retry could never recover.
        Yii::$app->db->transaction(function () use (&$space, &$coupling, $subscriber, $communityDoc, $communityApId, $handle) {
            $ghost = GhostUser::getOrCreate();
            $space = $this->createPublicSpace($communityDoc, $ghost);

            // Enable this module on the Space (per-container).
            $space->moduleManager->enable('activitypub');

            $coupling = new SpaceCommunity();
            $coupling->space_id = $space->id;
            $coupling->community_ap_id = $communityApId;
            $coupling->handle = $handle;
            $coupling->preferred_username = isset($communityDoc['preferredUsername']) ? (string) $communityDoc['preferredUsername'] : null;
            $coupling->name = isset($communityDoc['name']) ? mb_substr((string) $communityDoc['name'], 0, 255) : null;
            $coupling->summary = isset($communityDoc['summary']) ? (string) $communityDoc['summary'] : null;
            $coupling->icon_url = is_array($communityDoc['icon'] ?? null) ? ($communityDoc['icon']['url'] ?? null) : null;
            $coupling->image_url = is_array($communityDoc['image'] ?? null) ? ($communityDoc['image']['url'] ?? null) : null;
            $coupling->inbox_url = (string) ($communityDoc['inbox'] ?? '');
            $coupling->shared_inbox_url = $communityDoc['endpoints']['sharedInbox'] ?? null;
            $coupling->followers_url = $communityDoc['followers'] ?? null;
            $coupling->outbox_url = $communityDoc['outbox'] ?? null;
            $coupling->follow_state = SpaceCommunity::FOLLOW_STATE_PENDING;
            if (!$coupling->save()) {
                throw new RuntimeException('Could not save SpaceCommunity: ' . implode(', ', $coupling->getErrorSummary(true)));
            }

            $space->addMember((int) $subscriber->id);
        });

        // Only after the subscription is committed do we enqueue the outbound
        // Follow — a queue push must not fire for a rolled-back subscription.
        $this->sendFollow($space, $coupling);

        return $space;
    }

    /** Create a public (guest-visible) Space owned by the ghost user, from the community doc. */
    private function createPublicSpace(array $communityDoc, User $ghost): Space
    {
        $name = trim((string) ($communityDoc['name'] ?? '')) ?: (string) ($communityDoc['preferredUsername'] ?? 'Community');

        $space = new Space();
        $space->scenario = Space::SCENARIO_CREATE;
        $space->name = mb_substr($name, 0, 45); // Space.name max length
        $space->description = isset($communityDoc['summary']) ? mb_substr(strip_tags((string) $communityDoc['summary']), 0, 100) : '';
        $space->visibility = Space::VISIBILITY_ALL;       // public / guest-visible (§5 invariant)
        $space->join_policy = Space::JOIN_POLICY_FREE;
        $space->created_by = $ghost->id;                  // ownership seed (queue/console has no identity)
        // The Space is created on behalf of the system, not the subscriber: skip the
        // visibility rule, which checks the current user's (admin-only by default)
        // CreatePublicSpace permission, but keep all other validation.
        if (!$space->validate(array_diff($space->activeAttributes(), ['visibility'])) || !$space->save(false)) {
            throw new RuntimeException('Could not create community Space: ' . implode(', ', $space->getErrorSummary(true)));
        }
        // Ensure the ghost is the owner regardless of who triggered creation.
        $space->setSpaceOwner($ghost->id);
        return $space;
    }

    /** Send the Space Group actor's signed Follow to the community inbox. */
    private function sendFollow(Space $space, SpaceCommunity $coupling): void
    {
        $actor = (new KeyManager())->getOrCreateForSpace($space);
        $activityId = $actor->ap_id . '/follows/' . bin2hex(random_bytes(8));

        $follow = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Follow',
            'actor' => $actor->ap_id,
            'object' => $coupling->community_ap_id,
        ];

        $coupling->follow_activity_ap_id = $activityId;
        $coupling->save(false);

        Yii::$app->queue->push(new DeliverToInbox([
            'signerActorId' => (int) $actor->id,
            'inboxUrl' => $coupling->inbox_url,
            'activityJson' => json_encode($follow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }
}
