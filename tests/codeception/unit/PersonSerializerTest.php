<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\activitypub\services\PersonSerializer;
use tests\codeception\_support\HumHubDbTestCase;

class PersonSerializerTest extends HumHubDbTestCase
{
    public function testPersonDocumentShape()
    {
        $actor = new LocalActor();
        $actor->type = LocalActor::TYPE_PERSON;
        $actor->preferred_username = 'alice';
        $actor->ap_id = 'https://humhub.example/activitypub/actor/guid-1';
        $actor->public_key = "-----BEGIN PUBLIC KEY-----\nABC\n-----END PUBLIC KEY-----\n";

        $doc = (new PersonSerializer())->serialize($actor, 'Alice Example', 'https://humhub.example/u/alice');

        $this->assertContains('https://www.w3.org/ns/activitystreams', $doc['@context']);
        $this->assertContains('https://w3id.org/security/v1', $doc['@context']);
        $this->assertSame('Person', $doc['type']);
        $this->assertSame($actor->ap_id, $doc['id']);
        $this->assertSame('alice', $doc['preferredUsername']);
        $this->assertSame('Alice Example', $doc['name']);
        $this->assertSame('https://humhub.example/u/alice', $doc['url']);
        $this->assertSame($actor->ap_id . '/inbox', $doc['inbox']);
        $this->assertSame($actor->ap_id . '/outbox', $doc['outbox']);
        $this->assertSame($actor->ap_id . '#main-key', $doc['publicKey']['id']);
        $this->assertSame($actor->ap_id, $doc['publicKey']['owner']);
        $this->assertSame($actor->public_key, $doc['publicKey']['publicKeyPem']);
        $this->assertArrayHasKey('sharedInbox', $doc['endpoints']);
    }
}
