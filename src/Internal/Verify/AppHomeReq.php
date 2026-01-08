<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Verify;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;
use Shopify\App\Internal\Utils\Headers;
use Shopify\App\Internal\Utils\Request;
use Shopify\App\Types\ResultWithExchangeableIdToken;
use Shopify\App\Types\IdToken;
use Shopify\App\Types\LogWithReq;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::verifyAppHomeReq() instead.
 */
class AppHomeReq
{
    /**
     * Build app home patch id token page redirect response.
     *
     * @param array $urlParts Parsed URL components from parse_url()
     * @param string $path Request path
     * @param array $queryParams Query parameters
     * @param string $appHomePatchIdTokenPath Path to the patch id token page
     * @return ResultWithExchangeableIdToken Redirect response with 302 status and Location header
     */
    private static function buildPatchIdTokenRedirect(array $urlParts, string $path, array $queryParams, string $appHomePatchIdTokenPath, array $req): ResultWithExchangeableIdToken
    {
        $cleanParams = $queryParams;
        unset($cleanParams['id_token']);

        // Build reload path with query string (preserve base64 = padding)
        $reloadParts = [];
        foreach ($cleanParams as $key => $value) {
            $reloadParts[] = $key . '=' . $value;
        }
        $reloadQuery = implode('&', $reloadParts);
        $reloadPath = $path . ($reloadQuery ? '?' . $reloadQuery : '');

        // Build patch id token URL with shopify-reload parameter
        $patchIdTokenQueryParts = [];
        foreach ($cleanParams as $key => $value) {
            $patchIdTokenQueryParts[] = $key . '=' . $value;
        }
        $patchIdTokenQueryParts[] = 'shopify-reload=' . rawurlencode($reloadPath);
        $patchIdTokenQuery = implode('&', $patchIdTokenQueryParts);

        $patchIdTokenLocation = $urlParts['scheme'] . '://' . $urlParts['host'] . $appHomePatchIdTokenPath . '?' . $patchIdTokenQuery;

        return new ResultWithExchangeableIdToken(
            ok: false,
            shop: null,
            idToken: null,
            userId: null,
            newIdTokenResponse: null,
            log: new LogWithReq(
                code: 'redirect_to_patch_id_token_page',
                detail: 'Embedded app without id_token. Redirect to the patch ID token page to obtain a new token using the provided response.',
                req: Request::normalizeForLog($req)
            ),
            response: new ResponseInfo(
                status: 302,
                body: '',
                headers: ['Location' => $patchIdTokenLocation]
            )
        );
    }

    public static function verify(array $req, array $config, mixed $appHomePatchIdTokenPath): ResultWithExchangeableIdToken
    {
        // Validate appHomePatchIdTokenPath
        if (!is_string($appHomePatchIdTokenPath)) {
            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: 'Expected appHomePatchIdTokenPath to be a non-empty string',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        if ($appHomePatchIdTokenPath === '') {
            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: "Expected appHomePatchIdTokenPath to be a non-empty string, but got ''",
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Validate request object
        $url = $req['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: 'Expected request.url to be a non-empty string',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        $headers = $req['headers'] ?? null;
        if (!is_array($headers)) {
            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: 'Expected request.headers to be an object',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        $clientSecret = $config['clientSecret'] ?? '';
        $oldClientSecret = $config['oldClientSecret'] ?? null;
        $clientId = $config['clientId'] ?? '';

        // Normalize headers for case-insensitive comparison
        $normalizedHeaders = Headers::normalize($headers);

        // Parse URL for query parameters
        $urlParts = parse_url($url);
        $path = $urlParts['path'] ?? '';
        $query = $urlParts['query'] ?? '';
        parse_str($query, $queryParams);

        // Check for Authorization header to determine request type
        $authHeader = $normalizedHeaders['authorization'] ?? '';
        $hasAuthorizationHeader = !empty($authHeader);
        $hasIdToken = strpos($authHeader, 'Bearer ') === 0;

        $idToken = null;

        // If no Authorization header, check if this is a document request
        if (!$hasAuthorizationHeader) {
            // Document request - check for id_token
            $idTokenParam = $queryParams['id_token'] ?? '';

            // If no id_token, redirect to patch ID token page
            if (empty($idTokenParam)) {
                return self::buildPatchIdTokenRedirect($urlParts, $path, $queryParams, $appHomePatchIdTokenPath, $req);
            }

            $idToken = $idTokenParam;
        } else {
            if (strpos($authHeader, 'Bearer ') !== 0) {
                return new ResultWithExchangeableIdToken(
                    ok: false,
                    shop: null,
                    idToken: null,
                    userId: null,
                    newIdTokenResponse: null,
                    log: new LogWithReq(
                        code: 'invalid_id_token',
                        detail: 'ID token verification failed. Respond 401 Unauthorized using the provided response.',
                        req: Request::normalizeForLog($req)
                    ),
                    response: new ResponseInfo(
                        status: 401,
                        body: 'Unauthorized',
                        headers: [
                            'X-Shopify-Retry-Invalid-Session-Request' => '1'
                        ]
                    )
                );
            }
            $idToken = substr($authHeader, 7); // Remove "Bearer " prefix
        }

        if (empty($idToken)) {
            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: 'missing_authorization_and_id_token',
                    detail: 'Neither Authorization header nor id_token query parameter present. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: (object)[]
                )
            );
        }

        $payload = null;
        $verificationError = null;

        $secretsToTry = [];
        if ($oldClientSecret !== null) {
            $secretsToTry[] = $oldClientSecret;
        }
        $secretsToTry[] = $clientSecret;

        foreach ($secretsToTry as $secret) {
            try {
                // Set leeway for clock tolerance (10 seconds)
                JWT::$leeway = 10;
                $payload = JWT::decode($idToken, new Key($secret, 'HS256'));
                $payload = json_decode(json_encode($payload), true);
                break;
            } catch (Exception $e) {
                $verificationError = $e;
                continue; // Try next secret
            }
        }

        if ($payload === null) {
            // For document requests with invalid/stale tokens, redirect to patch ID token page
            if (!$hasAuthorizationHeader) {
                return self::buildPatchIdTokenRedirect($urlParts, $path, $queryParams, $appHomePatchIdTokenPath, $req);
            }

            $errorCode = 'invalid_id_token';
            $detailMsg = 'ID token verification failed. Respond 401 Unauthorized using the provided response.';

            if ($verificationError && stripos($verificationError->getMessage(), 'expired') !== false) {
                $errorCode = 'expired_id_token';
                $detailMsg = 'ID token has expired. Respond 401 Unauthorized using the provided response.';
            }

            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: $errorCode,
                    detail: $detailMsg,
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: [
                        'X-Shopify-Retry-Invalid-Session-Request' => '1'
                    ]
                )
            );
        }

        // Verify the audience (aud) matches the clientId
        $tokenAud = $payload['aud'] ?? '';
        if ($tokenAud !== $clientId) {
            // For Authorization header requests, include retry header
            $responseHeaders = (object)[];
            if ($hasAuthorizationHeader) {
                $responseHeaders = [
                    'X-Shopify-Retry-Invalid-Session-Request' => '1'
                ];
            }

            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: 'invalid_aud',
                    detail: 'ID token audience (aud) claim does not match clientId. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: $responseHeaders
                )
            );
        }

        $dest = $payload['dest'] ?? '';
        $destParts = parse_url($dest);
        $shopHostname = $destParts['host'] ?? $dest;
        $shop = str_replace('.myshopify.com', '', $shopHostname);

        // Extract userId from sub claim
        $userId = $payload['sub'] ?? null;

        // For document requests, add security and preload headers
        $responseHeaders = [];
        if (!$hasAuthorizationHeader) {
            $responseHeaders = [
                'Content-Security-Policy' => "frame-ancestors https://{$shopHostname} https://admin.shopify.com;",
                'Link' => '<https://cdn.shopify.com>; rel="preconnect", <https://cdn.shopify.com/shopifycloud/app-bridge.js>; rel="preload"; as="script", <https://cdn.shopify.com/shopifycloud/polaris.js>; rel="preload"; as="script"'
            ];
        }

        // Build newIdTokenResponse
        $newIdTokenResponse = null;
        if (!$hasAuthorizationHeader) {
            // Document request - build patch ID token URL
            $cleanParams = $queryParams;
            unset($cleanParams['id_token']);

            $reloadParts = [];
            foreach ($cleanParams as $key => $value) {
                $reloadParts[] = $key . '=' . $value;
            }
            $reloadQuery = implode('&', $reloadParts);
            $reloadPath = $path . ($reloadQuery ? '?' . $reloadQuery : '');

            $patchIdTokenQueryParts = [];
            foreach ($cleanParams as $key => $value) {
                $patchIdTokenQueryParts[] = $key . '=' . $value;
            }
            $patchIdTokenQueryParts[] = 'shopify-reload=' . rawurlencode($reloadPath);
            $patchIdTokenQuery = implode('&', $patchIdTokenQueryParts);

            $patchIdTokenLocation = $urlParts['scheme'] . '://' . $urlParts['host'] . $appHomePatchIdTokenPath . '?' . $patchIdTokenQuery;

            $newIdTokenResponse = [
                'status' => 302,
                'body' => '',
                'headers' => [
                    'Location' => $patchIdTokenLocation
                ]
            ];
        } else {
            // Fetch request
            $newIdTokenResponse = [
                'status' => 401,
                'body' => '',
                'headers' => [
                    'X-Shopify-Retry-Invalid-Session-Request' => '1'
                ]
            ];
        }

        return new ResultWithExchangeableIdToken(
            ok: true,
            shop: $shop,
            idToken: new IdToken(
                exchangeable: true,
                token: $idToken,
                claims: $payload
            ),
            userId: $userId,
            newIdTokenResponse: $newIdTokenResponse,
            log: new LogWithReq(
                code: 'verified',
                detail: 'App Home request verified. Proceed with business logic.' . (!$hasAuthorizationHeader ? '  Include the headers in the provided response.' : ''),
                req: Request::normalizeForLog($req)
            ),
            response: new ResponseInfo(
                status: 200,
                body: '',
                headers: empty($responseHeaders) ? (object)[] : $responseHeaders
            )
        );
    }
}
