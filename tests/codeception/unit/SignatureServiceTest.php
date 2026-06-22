<?php

namespace tests\codeception\unit\modules\fediverse;

use humhub\modules\fediverse\services\KeyManager;
use humhub\modules\fediverse\services\SignatureService;
use tests\codeception\_support\HumHubDbTestCase;

class SignatureServiceTest extends HumHubDbTestCase
{
    public function testDigestMatchesKnownVector()
    {
        // base64(sha256("")) is the well-known empty-string digest.
        $this->assertSame(
            'SHA-256=47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=',
            SignatureService::digest('')
        );
    }

    public function testSignThenVerifyRoundTrip()
    {
        [$priv, $pub] = (new KeyManager())->generateKeypair();
        $svc = new SignatureService();

        $method = 'POST';
        $path = '/fediverse/inbox';
        $body = '{"type":"Follow"}';
        $headers = [
            'host' => 'fedihub.social',
            'date' => 'Mon, 22 Jun 2026 17:00:00 GMT',
            'digest' => SignatureService::digest($body),
        ];
        $headerList = ['(request-target)', 'host', 'date', 'digest'];

        $signingString = $svc->buildSigningString($method, $path, $headers, $headerList);
        $this->assertStringContainsString('(request-target): post /fediverse/inbox', $signingString);

        $sigB64 = $svc->signString($signingString, $priv);
        $this->assertNotSame('', $sigB64);

        $this->assertTrue($svc->verifyString($signingString, $sigB64, $pub));
        $this->assertFalse($svc->verifyString($signingString . 'x', $sigB64, $pub));
    }

    public function testParseSignatureHeader()
    {
        $header = 'keyId="https://x/actor#main-key",algorithm="rsa-sha256",headers="(request-target) host date",signature="QUJD"';
        $parsed = SignatureService::parseSignatureHeader($header);
        $this->assertSame('https://x/actor#main-key', $parsed['keyId']);
        $this->assertSame('(request-target) host date', $parsed['headers']);
        $this->assertSame('QUJD', $parsed['signature']);
    }
}
