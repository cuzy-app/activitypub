<?php

namespace humhub\modules\activitypub\services;

use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use RuntimeException;
use yii\db\IntegrityException;

/**
 * Provisions and retrieves the local ActivityPub actors and their RSA keypairs.
 */
class KeyManager
{
    /** Get (or lazily create) the LocalActor for a user. */
    public function getOrCreateForUser(User $user): LocalActor
    {
        $actor = LocalActor::findOne(['user_id' => $user->id]);
        if ($actor !== null) {
            return $actor;
        }

        [$privateKey, $publicKey] = $this->generateKeypair();

        $actor = new LocalActor();
        $actor->user_id = $user->id;
        $actor->type = LocalActor::TYPE_PERSON;
        $actor->preferred_username = $this->uniqueHandle($user->username);
        $actor->ap_id = FederationUrl::actorUrl($user->guid);
        $actor->private_key = $privateKey;
        $actor->public_key = $publicKey;

        try {
            if (!$actor->save()) {
                throw new RuntimeException('Could not create LocalActor: ' . implode(', ', $actor->getErrorSummary(true)));
            }
        } catch (IntegrityException $e) {
            // Lost a race with a concurrent provisioning for the same user; reuse the existing row.
            $existing = LocalActor::findOne(['user_id' => $user->id]);
            if ($existing === null) {
                throw $e;
            }
            return $existing;
        }

        return $actor;
    }

    /** Get (or lazily create) the Group LocalActor for a Space. */
    public function getOrCreateForSpace(Space $space): LocalActor
    {
        $actor = LocalActor::findOne(['space_id' => $space->id]);
        if ($actor !== null) {
            return $actor;
        }

        [$privateKey, $publicKey] = $this->generateKeypair();

        $actor = new LocalActor();
        $actor->space_id = $space->id;
        $actor->type = LocalActor::TYPE_GROUP;
        $actor->preferred_username = $this->uniqueHandle($space->url ?: ('space_' . $space->id));
        $actor->ap_id = FederationUrl::spaceActorUrl($space->guid);
        $actor->private_key = $privateKey;
        $actor->public_key = $publicKey;

        try {
            if (!$actor->save()) {
                throw new RuntimeException('Could not create Space LocalActor: ' . implode(', ', $actor->getErrorSummary(true)));
            }
        } catch (IntegrityException $e) {
            // Lost a race with a concurrent provisioning for the same space; reuse the existing row.
            $existing = LocalActor::findOne(['space_id' => $space->id]);
            if ($existing === null) {
                throw $e;
            }
            return $existing;
        }

        return $actor;
    }

    /** Get (or lazily create) the singleton instance Application actor. */
    public function getOrCreateInstanceActor(): LocalActor
    {
        $actor = LocalActor::findOne(['type' => LocalActor::TYPE_APPLICATION, 'user_id' => null]);
        if ($actor !== null) {
            return $actor;
        }

        [$privateKey, $publicKey] = $this->generateKeypair();

        $actor = new LocalActor();
        $actor->user_id = null;
        $actor->type = LocalActor::TYPE_APPLICATION;
        $actor->preferred_username = $this->uniqueHandle(FederationUrl::domain());
        $actor->ap_id = FederationUrl::instanceActorUrl();
        $actor->private_key = $privateKey;
        $actor->public_key = $publicKey;
        if (!$actor->save()) {
            throw new RuntimeException('Could not create instance actor: ' . implode(', ', $actor->getErrorSummary(true)));
        }
        return $actor;
    }

    /**
     * @return array{0:string,1:string} [privateKeyPem, publicKeyPem]
     */
    public function generateKeypair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }
        if (!openssl_pkey_export($resource, $privateKey)) {
            throw new RuntimeException('openssl_pkey_export failed: ' . openssl_error_string());
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new RuntimeException('openssl_pkey_get_details failed: ' . openssl_error_string());
        }
        return [(string) $privateKey, (string) $details['key']];
    }

    /** Derive a unique preferred_username, suffixing on collision. */
    private function uniqueHandle(string $source): string
    {
        $base = FederationUrl::normalizeHandle($source);
        if ($base === '') {
            $base = 'user';
        }
        $handle = $base;
        $i = 1;
        while (LocalActor::find()->where(['preferred_username' => $handle])->exists()) {
            $handle = $base . '_' . $i;
            $i++;
        }
        return $handle;
    }
}
