<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\models\SpaceCommunity;
use tests\codeception\_support\HumHubDbTestCase;

class SpaceCommunityTest extends HumHubDbTestCase
{
    public function testCouplingRoundtripAndLookup()
    {
        $c = new SpaceCommunity();
        $c->space_id = 1;
        $c->community_ap_id = 'https://lemmy.example/c/linux';
        $c->handle = '!linux@lemmy.example';
        $c->inbox_url = 'https://lemmy.example/c/linux/inbox';
        $this->assertTrue($c->save(), implode(', ', $c->getErrorSummary(true)));
        $this->assertSame(SpaceCommunity::FOLLOW_STATE_PENDING, $c->follow_state);

        $found = SpaceCommunity::findOne(['community_ap_id' => 'https://lemmy.example/c/linux']);
        $this->assertNotNull($found);
        $this->assertSame(1, (int) $found->space_id);
    }
}
