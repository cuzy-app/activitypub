<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\jobs\ProcessInboxActivity;
use humhub\modules\activitypub\models\SpaceCommunity;
use tests\codeception\_support\HumHubDbTestCase;

class AcceptCommunityTest extends HumHubDbTestCase
{
    public function testAcceptFromCommunityFlipsFollowStateToAccepted()
    {
        $c = new SpaceCommunity();
        $c->space_id = 1;
        $c->community_ap_id = 'https://lemmy.example/c/linux';
        $c->inbox_url = 'https://lemmy.example/c/linux/inbox';
        $c->follow_state = SpaceCommunity::FOLLOW_STATE_PENDING;
        $c->save(false);

        // Simulate the verified Accept from the community (signer == community_ap_id).
        $job = new ProcessInboxActivity();
        $handled = $job->acceptCommunityFollow('https://lemmy.example/c/linux');
        $this->assertTrue($handled);

        $c->refresh();
        $this->assertSame(SpaceCommunity::FOLLOW_STATE_ACCEPTED, $c->follow_state);
    }

    public function testAcceptFromUnknownSignerIsNotHandledAsCommunity()
    {
        $job = new ProcessInboxActivity();
        $this->assertFalse($job->acceptCommunityFollow('https://lemmy.example/c/unknown'));
    }
}
