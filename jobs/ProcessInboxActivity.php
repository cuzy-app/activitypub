<?php

namespace humhub\modules\fediverse\jobs;

use humhub\modules\fediverse\models\FederationFollow;
use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\fediverse\models\RemoteActor;
use humhub\modules\fediverse\services\ActivityDelivery;
use humhub\modules\fediverse\services\FederationUrl;
use humhub\modules\fediverse\services\RemoteActorResolver;
use humhub\modules\fediverse\services\SignatureService;
use humhub\modules\queue\ActiveJob;
use humhub\modules\user\models\User;
use Yii;

/**
 * Verifies an inbound activity's HTTP Signature, then dispatches by type.
 * Phase 3 handles Follow and Undo{Follow}; other types are logged and ignored.
 */
class ProcessInboxActivity extends ActiveJob
{
    public string $rawBody = '';
    public array $headers = [];
    public string $requestPath = '';
    public ?string $targetGuid = null;

    public function run()
    {
        $activity = json_decode($this->rawBody, true);
        if (!is_array($activity) || empty($activity['type']) || empty($activity['actor'])) {
            Yii::warning('Fediverse inbox: malformed activity', 'fediverse');
            return;
        }

        $actorUrl = is_array($activity['actor']) ? ($activity['actor']['id'] ?? '') : (string) $activity['actor'];
        if ($actorUrl === '') {
            return;
        }

        $resolver = new RemoteActorResolver();
        $remote = $resolver->resolve($actorUrl);

        if (!$this->verifySignature($remote)) {
            Yii::warning('Fediverse inbox: signature verification failed for ' . $actorUrl, 'fediverse');
            return;
        }

        switch ($activity['type']) {
            case 'Follow':
                $this->handleFollow($activity, $remote);
                break;
            case 'Undo':
                $this->handleUndo($activity, $remote);
                break;
            default:
                Yii::info('Fediverse inbox: ignoring unsupported type ' . $activity['type'], 'fediverse');
        }
    }

    private function verifySignature(RemoteActor $remote): bool
    {
        $parsed = SignatureService::parseSignatureHeader($this->headers['signature'] ?? '');
        if (empty($parsed['signature']) || empty($parsed['headers'])) {
            return false;
        }

        // Verify the body Digest first (if it was part of the signed set).
        $headerList = explode(' ', $parsed['headers']);
        if (in_array('digest', $headerList, true)) {
            if (!hash_equals(SignatureService::digest($this->rawBody), (string) ($this->headers['digest'] ?? ''))) {
                return false;
            }
        }

        $svc = new SignatureService();
        $signingString = $svc->buildSigningString('POST', $this->requestPath, [
            'host' => (string) ($this->headers['host'] ?? ''),
            'date' => (string) ($this->headers['date'] ?? ''),
            'digest' => (string) ($this->headers['digest'] ?? ''),
            'content-type' => (string) ($this->headers['content-type'] ?? ''),
        ], $headerList);

        return $svc->verifyString($signingString, $parsed['signature'], (string) $remote->public_key);
    }

    private function handleFollow(array $activity, RemoteActor $remote): void
    {
        $localUser = $this->resolveFollowTarget($activity['object'] ?? null);
        if ($localUser === null) {
            Yii::warning('Fediverse Follow: unknown target object', 'fediverse');
            return;
        }

        $follow = FederationFollow::findOne([
            'local_user_id' => $localUser->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
        ]);
        if ($follow === null) {
            $follow = new FederationFollow();
            $follow->local_user_id = $localUser->id;
            $follow->remote_actor_id = $remote->id;
            $follow->direction = FederationFollow::DIRECTION_INBOUND;
        }
        $follow->state = FederationFollow::STATE_ACCEPTED; // auto-accept (spec §12)
        $follow->activity_ap_id = $activity['id'] ?? null;
        $follow->save();

        $this->sendAccept($localUser, $remote, $activity);
    }

    private function handleUndo(array $activity, RemoteActor $remote): void
    {
        $inner = $activity['object'] ?? null;
        if (!is_array($inner) || ($inner['type'] ?? '') !== 'Follow') {
            return; // only Undo{Follow} in this phase
        }
        $localUser = $this->resolveFollowTarget($inner['object'] ?? null);
        if ($localUser === null) {
            return;
        }
        FederationFollow::deleteAll([
            'local_user_id' => $localUser->id,
            'remote_actor_id' => $remote->id,
            'direction' => FederationFollow::DIRECTION_INBOUND,
        ]);
    }

    /** Resolve a Follow `object` (our actor URL) to the local User. */
    private function resolveFollowTarget($object): ?User
    {
        $url = is_array($object) ? ($object['id'] ?? '') : (string) $object;
        if ($url === '') {
            return null;
        }
        $actor = LocalActor::findOne(['ap_id' => $url]);
        if ($actor === null || $actor->user_id === null) {
            return null;
        }
        return User::findOne($actor->user_id);
    }

    private function sendAccept(User $localUser, RemoteActor $remote, array $followActivity): void
    {
        $signer = LocalActor::findOne(['user_id' => $localUser->id]);
        if ($signer === null) {
            return;
        }

        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $signer->ap_id . '/accepts/' . bin2hex(random_bytes(8)),
            'type' => 'Accept',
            'actor' => $signer->ap_id,
            'object' => [
                'id' => $followActivity['id'] ?? null,
                'type' => 'Follow',
                'actor' => $remote->ap_id,
                'object' => $signer->ap_id,
            ],
        ];

        $inbox = $remote->shared_inbox_url ?: $remote->inbox_url;
        try {
            (new ActivityDelivery())->deliver($signer, $inbox, $accept);
        } catch (\Throwable $e) {
            Yii::warning('Fediverse Accept delivery failed: ' . $e->getMessage(), 'fediverse');
        }
    }
}
