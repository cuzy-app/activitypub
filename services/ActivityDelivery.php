<?php

namespace humhub\modules\fediverse\services;

use humhub\modules\fediverse\models\LocalActor;
use RuntimeException;

/**
 * Signs an activity with a LocalActor's key and POSTs it to a remote inbox,
 * using draft-cavage HTTP Signatures over (request-target) host date digest.
 */
class ActivityDelivery
{
    private SignatureService $signatures;

    public function __construct(?SignatureService $signatures = null)
    {
        $this->signatures = $signatures ?? new SignatureService();
    }

    /** @return int the HTTP status code of the delivery (throws on unsafe URL or transport error). */
    public function deliver(LocalActor $signer, string $inboxUrl, array $activity): int
    {
        if (!UrlSafety::isSafePublicUrl($inboxUrl)) {
            throw new RuntimeException('Refusing to deliver to unsafe URL: ' . $inboxUrl);
        }

        $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parts = parse_url($inboxUrl);
        $host = $parts['host'] ?? '';
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = SignatureService::digest($body);

        $headerList = ['(request-target)', 'host', 'date', 'digest'];
        $signingString = $this->signatures->buildSigningString('POST', $path, [
            'host' => $host,
            'date' => $date,
            'digest' => $digest,
        ], $headerList);

        $signatureB64 = $this->signatures->signString($signingString, (string) $signer->private_key);
        $signatureHeader = sprintf(
            'keyId="%s#main-key",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $signer->ap_id,
            implode(' ', $headerList),
            $signatureB64
        );

        $client = new FederationHttpClient();
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($inboxUrl)
            ->addHeaders([
                'Host' => $host,
                'Date' => $date,
                'Digest' => $digest,
                'Content-Type' => 'application/activity+json',
                'Signature' => $signatureHeader,
            ])
            ->setContent($body)
            ->send();

        return (int) $response->getStatusCode();
    }
}
