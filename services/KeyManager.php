<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\fediverse\models\LocalActor;
use humhub\modules\user\models\User;
use RuntimeException;

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
        if (!$actor->save()) {
            throw new RuntimeException('Could not create LocalActor: ' . implode(', ', $actor->getErrorSummary(true)));
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
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);
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
