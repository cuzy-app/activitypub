<?php

namespace humhub\modules\fediverse\services;

use humhub\libs\HttpClient;

/**
 * HttpClient hardened for federation traffic: no redirect following (prevents
 * SSRF-via-redirect), HTTPS-only, enforced timeouts. These options are applied
 * AFTER humhub\libs\HttpClient::beforeSend() (which REPLACES request options
 * with CURLHelper defaults), so they survive.
 */
class FederationHttpClient extends HttpClient
{
    public function beforeSend($request)
    {
        parent::beforeSend($request);
        $request->addOptions([
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
    }
}
