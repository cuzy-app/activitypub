<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\activitypub\services\KeyManager;
use humhub\modules\space\models\Space;
use tests\codeception\_support\HumHubDbTestCase;

class SpaceActorTest extends HumHubDbTestCase
{
    public function testGetOrCreateForSpaceIsIdempotent()
    {
        $space = Space::findOne(['id' => 1]);
        $this->assertNotNull($space);

        $actor = (new KeyManager())->getOrCreateForSpace($space);
        $this->assertSame(LocalActor::TYPE_GROUP, $actor->type);
        $this->assertSame((int) $space->id, (int) $actor->space_id);
        $this->assertNotEmpty($actor->private_key);
        $this->assertStringContainsString('/activitypub/actor/space/' . $space->guid, $actor->ap_id);

        $again = (new KeyManager())->getOrCreateForSpace($space);
        $this->assertSame($actor->id, $again->id); // no duplicate
    }
}
