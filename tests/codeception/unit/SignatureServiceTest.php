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

    public function testSigningStringCandidatesTryReceivedThenCanonicalValues()
    {
        $svc = new SignatureService();
        $headers = ['host' => 'humhub:8080', 'date' => 'Mon, 22 Jun 2026 17:00:00 GMT', 'digest' => 'SHA-256=x'];
        $headerList = ['(request-target)', 'host', 'date', 'digest'];

        // Proxy rewrote only the Host header: same path twice collapses to two candidates,
        // the received one first, the canonical one second.
        $candidates = $svc->signingStringCandidates('POST', ['/fediverse/inbox', '/fediverse/inbox'], ['humhub:8080', 'fedihub.social'], $headers, $headerList);
        $this->assertCount(2, $candidates);
        $this->assertStringContainsString("host: humhub:8080\n", $candidates[0]);
        $this->assertStringContainsString("host: fedihub.social\n", $candidates[1]);
        $this->assertStringContainsString('(request-target): post /fediverse/inbox', $candidates[1]);

        // Proxy also stripped a path prefix: all four combinations, received pair first.
        $candidates = $svc->signingStringCandidates('POST', ['/fediverse/inbox', '/humhub/fediverse/inbox'], ['humhub:8080', 'fedihub.social'], $headers, $headerList);
        $this->assertCount(4, $candidates);
        $this->assertStringContainsString("(request-target): post /fediverse/inbox\nhost: humhub:8080", $candidates[0]);
        $this->assertStringContainsString("(request-target): post /humhub/fediverse/inbox\nhost: fedihub.social", $candidates[3]);

        // `host` not signed: the host candidates make no difference.
        $candidates = $svc->signingStringCandidates('POST', ['/fediverse/inbox'], ['humhub:8080', 'fedihub.social'], $headers, ['(request-target)', 'date', 'digest']);
        $this->assertCount(1, $candidates);

        // Empty candidates (Host header missing, no canonical path) are skipped and
        // never produce an empty "host: " line — an empty host is never what was signed.
        $candidates = $svc->signingStringCandidates('POST', ['/fediverse/inbox', ''], ['', 'fedihub.social'], $headers, $headerList);
        $this->assertCount(1, $candidates);
        $this->assertStringContainsString("host: fedihub.social\n", $candidates[0]);
    }

    public function testVerifyAcceptsSignatureWhenProxyRewroteHost()
    {
        [$priv, $pub] = (new KeyManager())->generateKeypair();
        $svc = new SignatureService();
        $body = '{"type":"Create"}';
        $headerList = ['(request-target)', 'host', 'date', 'digest'];

        // What the remote signed: our public host and path.
        $signed = $svc->buildSigningString('POST', '/fediverse/inbox', [
            'host' => 'fedihub.social',
            'date' => 'Mon, 22 Jun 2026 17:00:00 GMT',
            'digest' => SignatureService::digest($body),
        ], $headerList);
        $sigB64 = $svc->signString($signed, $priv);

        // What PHP received behind a proxy that rewrote Host to the upstream address.
        $received = [
            'host' => 'humhub:8080',
            'date' => 'Mon, 22 Jun 2026 17:00:00 GMT',
            'digest' => SignatureService::digest($body),
        ];

        $verifies = static function (array $candidates) use ($svc, $sigB64, $pub): bool {
            foreach ($candidates as $candidate) {
                if ($svc->verifyString($candidate, $sigB64, $pub)) {
                    return true;
                }
            }
            return false;
        };

        // Received-only reconstruction fails; adding the canonical host recovers it.
        $this->assertFalse($verifies($svc->signingStringCandidates('POST', ['/fediverse/inbox'], ['humhub:8080'], $received, $headerList)));
        $this->assertTrue($verifies($svc->signingStringCandidates('POST', ['/fediverse/inbox'], ['humhub:8080', 'fedihub.social'], $received, $headerList)));
        // A wrong canonical host does not help — the signature still binds to our real host.
        $this->assertFalse($verifies($svc->signingStringCandidates('POST', ['/fediverse/inbox'], ['humhub:8080', 'other.example'], $received, $headerList)));
    }

    public function testMissingSignedHeaders()
    {
        $headers = ['host' => 'fedihub.social', 'date' => 'x', 'digest' => 'y'];
        $this->assertSame([], SignatureService::missingSignedHeaders($headers, ['(request-target)', 'host', 'date', 'digest']));
        $this->assertSame(['content-type'], SignatureService::missingSignedHeaders($headers, ['(request-target)', 'host', 'date', 'digest', 'content-type']));
        // Case-insensitive on the signed list; empty values count as missing.
        $this->assertSame(['content-type'], SignatureService::missingSignedHeaders($headers + ['content-type' => ''], ['Host', 'Content-Type']));
    }
}
