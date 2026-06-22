<?php

namespace humhub\modules\fediverse\services;

use humhub\libs\HttpClient;
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

    /**
     * @param LocalActor $signer the local actor whose key signs the request
     * @param string $inboxUrl absolute remote inbox URL
     * @param array $activity the activity to deliver (JSON-LD array)
     * @return bool true on a 2xx response
     */
    public function deliver(LocalActor $signer, string $inboxUrl, array $activity): bool
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

        $client = new HttpClient();
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
            ->setOptions([CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5])
            ->send();

        return $response->getIsOk();
    }
}
