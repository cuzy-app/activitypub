<?php
namespace tests\codeception\unit\modules\activitypub;

use humhub\modules\activitypub\services\RemoteActorResolver;
use tests\codeception\_support\HumHubDbTestCase;

class WebfingerHandleTest extends HumHubDbTestCase
{
    public function testWebfingerUrlFromHandle()
    {
        $this->assertSame(
            'https://mastodon.social/.well-known/webfinger?resource=acct:cmburns@mastodon.social',
            RemoteActorResolver::webfingerUrl('@cmburns@mastodon.social')
        );
        $this->assertSame(
            'https://example.org/.well-known/webfinger?resource=acct:alice@example.org',
            RemoteActorResolver::webfingerUrl('alice@example.org')
        );
    }

    public function testInvalidHandleReturnsEmpty()
    {
        $this->assertSame('', RemoteActorResolver::webfingerUrl('not-a-handle'));
        $this->assertSame('', RemoteActorResolver::webfingerUrl(''));
    }
}
