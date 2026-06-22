<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\FederationUrl;
use tests\codeception\_support\HumHubDbTestCase;

class FederationUrlTest extends HumHubDbTestCase
{
    public function testNormalizeHandle()
    {
        $this->assertSame('john_doe', FederationUrl::normalizeHandle('john_doe'));
        $this->assertSame('john_doe', FederationUrl::normalizeHandle('John.Doe'));
        $this->assertSame('a_b_c', FederationUrl::normalizeHandle('a b-c'));
        $this->assertSame('user', FederationUrl::normalizeHandle('üser!')); // non-ascii/punct stripped
        $this->assertSame('user', FederationUrl::normalizeHandle('user@example.com'));
    }

    public function testActorUrlUsesGuid()
    {
        $url = FederationUrl::actorUrl('abc-123');
        $this->assertStringContainsString('/fediverse/actor/abc-123', $url);
        $this->assertStringStartsWith('http', $url);
    }

    public function testInstanceActorUrl()
    {
        $this->assertStringContainsString('/fediverse/instance-actor', FederationUrl::instanceActorUrl());
    }

    public function testDomainHasNoScheme()
    {
        $domain = FederationUrl::domain();
        $this->assertStringNotContainsString('://', $domain);
        $this->assertStringNotContainsString('/', $domain);
    }
}
