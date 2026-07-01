<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\models\LocalActor;
use tests\codeception\_support\HumHubDbTestCase;

class LocalActorGroupTest extends HumHubDbTestCase
{
    public function testGroupActorForSpaceRoundtrip()
    {
        $actor = new LocalActor();
        $actor->space_id = 1; // space fixture id 1 exists in the HumHub test DB
        $actor->type = LocalActor::TYPE_GROUP;
        $actor->preferred_username = 'group.actor.test';
        $actor->ap_id = 'https://example.com/fediverse/actor/space/abc-guid';
        $actor->public_key = 'PUB';
        $actor->private_key = 'PRIV';

        $this->assertTrue($actor->save(), implode(', ', $actor->getErrorSummary(true)));

        $reloaded = LocalActor::findOne(['space_id' => 1]);
        $this->assertNotNull($reloaded);
        $this->assertSame('Group', $reloaded->type);
        $this->assertNull($reloaded->user_id);
        $this->assertSame(1, (int) $reloaded->space_id);
    }
}
