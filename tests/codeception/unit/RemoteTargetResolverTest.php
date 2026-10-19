<?php
namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\services\RemoteTargetResolver;
use tests\codeception\_support\HumHubDbTestCase;

class RemoteTargetResolverTest extends HumHubDbTestCase
{
    public function testUnknownContentResolvesNull()
    {
        $this->assertNull((new RemoteTargetResolver())->resolve(999999));
    }

    public function testUnknownCommentResolvesNull()
    {
        $this->assertNull((new RemoteTargetResolver())->resolveComment(999999));
    }
}
