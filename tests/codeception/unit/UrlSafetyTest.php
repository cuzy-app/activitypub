<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\UrlSafety;
use tests\codeception\_support\HumHubDbTestCase;

class UrlSafetyTest extends HumHubDbTestCase
{
    public function testRejectsNonHttpsAndMalformed()
    {
        $this->assertFalse(UrlSafety::isSafePublicUrl('not a url'));
        $this->assertFalse(UrlSafety::isSafePublicUrl('http://example.com/actor')); // not https
        $this->assertFalse(UrlSafety::isSafePublicUrl('ftp://example.com'));
    }

    public function testRejectsPrivateAndLoopbackLiterals()
    {
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://127.0.0.1/x'));
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://10.0.0.5/x'));
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://192.168.1.10/x'));
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://169.254.169.254/x')); // link-local metadata
        $this->assertFalse(UrlSafety::isSafePublicUrl('https://[::1]/x'));
    }

    public function testAllowsPublicHttps()
    {
        $this->assertTrue(UrlSafety::isSafePublicUrl('https://mastodon.social/users/alice'));
    }
}
