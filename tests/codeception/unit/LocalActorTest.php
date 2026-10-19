<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\models\LocalActor;
use tests\codeception\_support\HumHubDbTestCase;

class LocalActorTest extends HumHubDbTestCase
{
    public function testInstanceActorRoundtrip()
    {
        $actor = new LocalActor();
        $actor->type = 'Application';
        $actor->preferred_username = 'instance.actor';
        $actor->ap_id = 'https://example.com/activitypub/actor/instance';
        $actor->public_key = 'PUB';
        $actor->private_key = 'PRIV';
        $actor->federation_enabled = true;

        $this->assertTrue($actor->save(), implode(', ', $actor->getErrorSummary(true)));

        $reloaded = LocalActor::findOne(['preferred_username' => 'instance.actor']);
        $this->assertNotNull($reloaded);
        $this->assertSame('Application', $reloaded->type);
        $this->assertNull($reloaded->user_id);
        $this->assertNotEmpty($reloaded->created_at);
    }
}
