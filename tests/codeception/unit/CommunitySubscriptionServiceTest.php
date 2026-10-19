<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\models\LocalActor;
use humhub\modules\activitypub\models\SpaceCommunity;
use humhub\modules\activitypub\services\CommunitySubscriptionService;
use humhub\modules\activitypub\services\GhostUser;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use tests\codeception\_support\HumHubDbTestCase;

class CommunitySubscriptionServiceTest extends HumHubDbTestCase
{
    private function doc(): array
    {
        return [
            'id' => 'https://lemmy.example/c/linux',
            'type' => 'Group',
            'preferredUsername' => 'linux',
            'name' => 'Linux',
            'summary' => '<p>Everything Linux.</p>',
            'inbox' => 'https://lemmy.example/c/linux/inbox',
            'endpoints' => ['sharedInbox' => 'https://lemmy.example/inbox'],
            'followers' => 'https://lemmy.example/c/linux/followers',
            'outbox' => 'https://lemmy.example/c/linux/outbox',
        ];
    }

    public function testProvisionCreatesPublicSpaceOwnedByGhost()
    {
        $subscriber = User::findOne(['id' => 2]); // a normal user fixture
        $space = (new CommunitySubscriptionService())
            ->provisionOrJoin($subscriber, $this->doc(), 'https://lemmy.example/c/linux', '!linux@lemmy.example');

        $this->assertInstanceOf(Space::class, $space);
        $this->assertEquals(Space::VISIBILITY_ALL, $space->visibility);

        // Owner is the ghost; the subscriber is a member.
        $ghost = GhostUser::find();
        $this->assertNotNull($ghost);
        $this->assertTrue($space->isSpaceOwner($ghost->id));
        $this->assertTrue($space->isMember($subscriber->id));

        // Coupling + Group actor exist.
        $coupling = SpaceCommunity::findOne(['community_ap_id' => 'https://lemmy.example/c/linux']);
        $this->assertNotNull($coupling);
        $this->assertSame((int) $space->id, (int) $coupling->space_id);
        $this->assertSame('https://lemmy.example/c/linux/inbox', $coupling->inbox_url);
        $this->assertNotNull(LocalActor::findOne(['space_id' => $space->id, 'type' => LocalActor::TYPE_GROUP]));
    }

    public function testSecondSubscriberJoinsExistingSpaceNoDuplicate()
    {
        $svc = new CommunitySubscriptionService();
        $a = User::findOne(['id' => 2]);
        $b = User::findOne(['id' => 3]);

        $space1 = $svc->provisionOrJoin($a, $this->doc(), 'https://lemmy.example/c/linux', '!linux@lemmy.example');
        $space2 = $svc->provisionOrJoin($b, $this->doc(), 'https://lemmy.example/c/linux', '!linux@lemmy.example');

        $this->assertSame($space1->id, $space2->id); // same Space, no duplicate
        $this->assertSame(1, (int) SpaceCommunity::find()->where(['community_ap_id' => 'https://lemmy.example/c/linux'])->count());
        $this->assertTrue($space2->isMember($b->id));
    }
}
