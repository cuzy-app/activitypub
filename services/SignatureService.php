<?php

namespace humhub\modules\fediverse\services;

use RuntimeException;

/**
 * Hand-rolled draft-cavage HTTP Signature signing/verification plus the
 * RFC-3230 `Digest` header (SHA-256). rsa-sha256 only (what Mastodon uses).
 */
class SignatureService
{
    /** `SHA-256=<base64(sha256(body))>` */
    public static function digest(string $body): string
    {
        return 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    }

    /** Parse a `Signature:` header into its key="value" components. */
    public static function parseSignatureHeader(string $header): array
    {
        $out = [];
        if (preg_match_all('/(\w+)="([^"]*)"/', $header, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $out[$pair[1]] = $pair[2];
            }
        }
        return $out;
    }

    /**
     * Build the signing string for the given ordered header list.
     * `(request-target)` becomes "<method-lowercased> <path>".
     *
     * @param array<string,string> $headers lowercased-name => value
     * @param string[] $headerList ordered names, e.g. ['(request-target)','host','date','digest']
     */
    public function buildSigningString(string $method, string $path, array $headers, array $headerList): string
    {
        $lines = [];
        foreach ($headerList as $name) {
            $name = strtolower($name);
            if ($name === '(request-target)') {
                $lines[] = '(request-target): ' . strtolower($method) . ' ' . $path;
            } else {
                $lines[] = $name . ': ' . ($headers[$name] ?? '');
            }
        }
        return implode("\n", $lines);
    }

    /** RSA-SHA256 sign, returns base64 signature. */
    public function signString(string $signingString, string $privateKeyPem): string
    {
        $signature = '';
        if (!openssl_sign($signingString, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('openssl_sign failed: ' . openssl_error_string());
        }
        return base64_encode($signature);
    }

    /** RSA-SHA256 verify of a base64 signature. */
    public function verifyString(string $signingString, string $signatureB64, string $publicKeyPem): bool
    {
        $raw = base64_decode($signatureB64, true);
        if ($raw === false) {
            return false;
        }
        return openssl_verify($signingString, $raw, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }
}
