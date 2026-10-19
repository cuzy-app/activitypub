<?php

namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\services\CommunityResolver;
use tests\codeception\_support\HumHubDbTestCase;

class CommunityResolverTest extends HumHubDbTestCase
{
    public function testParseHandleStripsBangAndAt()
    {
        $this->assertSame(['linux', 'lemmy.world'], CommunityResolver::parseHandle('!linux@lemmy.world'));
        $this->assertSame(['linux', 'lemmy.world'], CommunityResolver::parseHandle('@linux@lemmy.world'));
        $this->assertSame(['linux', 'lemmy.world'], CommunityResolver::parseHandle('linux@lemmy.world'));
        $this->assertNull(CommunityResolver::parseHandle('not a handle'));
        $this->assertNull(CommunityResolver::parseHandle('nodomain'));
    }

    public function testWebfingerUrlUsesAcctWithoutBang()
    {
        $this->assertSame(
            'https://lemmy.world/.well-known/webfinger?resource=acct:linux@lemmy.world',
            CommunityResolver::webfingerUrl('!linux@lemmy.world')
        );
        $this->assertSame('', CommunityResolver::webfingerUrl('garbage'));
    }

    public function testIsCommunityDocRejectsPerson()
    {
        $this->assertTrue(CommunityResolver::isCommunityDoc(['type' => 'Group', 'id' => 'x', 'inbox' => 'y']));
        $this->assertFalse(CommunityResolver::isCommunityDoc(['type' => 'Person', 'id' => 'x', 'inbox' => 'y']));
        $this->assertFalse(CommunityResolver::isCommunityDoc(['type' => 'Group'])); // missing inbox
    }
}
