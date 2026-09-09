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

    /**
     * All signing strings an inbound request may legitimately verify against.
     *
     * A reverse proxy in front of PHP (WAF, load balancer, Docker network) may
     * rewrite `Host` to the upstream address or strip a path prefix, but the remote
     * signed the public host and path it addressed. So besides the values as
     * received, the caller passes the canonical host/path derived from our own base
     * URL; every combination is tried, received values first. Only OUR identities
     * may be candidates — never client-controlled headers such as X-Forwarded-Host,
     * or a signature meant for another server could be replayed against us.
     *
     * @param string[] $paths request-target paths, received first (empty entries skipped)
     * @param string[] $hosts host values, received first (empty entries skipped)
     * @param array<string,string> $headers lowercased-name => value, as received
     * @param string[] $headerList the signed header names, in order
     * @return string[] distinct signing strings, in the order they should be tried
     */
    public function signingStringCandidates(string $method, array $paths, array $hosts, array $headers, array $headerList): array
    {
        $paths = array_values(array_unique(array_filter($paths, static fn($p) => $p !== '')));
        $hosts = array_values(array_unique(array_filter($hosts, static fn($h) => $h !== '')));
        if ($hosts === []) {
            $hosts = [null]; // no host candidate: build with the headers as they are
        }

        $out = [];
        foreach ($paths as $path) {
            foreach ($hosts as $host) {
                $h = $headers;
                if ($host !== null) {
                    $h['host'] = $host;
                }
                $out[] = $this->buildSigningString($method, $path, $h, $headerList);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Signed header names (pseudo-headers like `(request-target)` excluded) that are
     * absent or empty in the received headers — the usual reason a signature cannot
     * be reconstructed. Diagnostic only.
     *
     * @param array<string,string> $headers lowercased-name => value
     * @param string[] $headerList
     * @return string[] lowercased names
     */
    public static function missingSignedHeaders(array $headers, array $headerList): array
    {
        $missing = [];
        foreach ($headerList as $name) {
            $name = strtolower($name);
            if ($name === '' || $name[0] === '(') {
                continue;
            }
            if (!isset($headers[$name]) || $headers[$name] === '') {
                $missing[] = $name;
            }
        }
        return $missing;
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
