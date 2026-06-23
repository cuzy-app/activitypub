<?php
namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\RemoteTargetResolver;
use tests\codeception\_support\HumHubDbTestCase;

class RemoteTargetResolverTest extends HumHubDbTestCase
{
    public function testUnknownContentResolvesNull()
    {
        $this->assertNull((new RemoteTargetResolver())->resolve(999999));
    }
}
