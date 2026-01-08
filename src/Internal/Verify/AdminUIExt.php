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
 *           Use ShopifyApp::verifyAdminUIExtReq() instead.
 */
class AdminUIExt
{
    public static function verify(array $req, array $config): ResultWithExchangeableIdToken
    {
        // Validate request object
        $method = $req['method'] ?? null;
        if (!is_string($method) || $method === '') {
            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: 'Expected request.method to be a non-empty string',
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

        $clientSecret = $config['clientSecret'] ?? '';
        $oldClientSecret = $config['oldClientSecret'] ?? null;
        $clientId = $config['clientId'] ?? '';

        // Normalize headers for case-insensitive comparison
        $normalizedHeaders = Headers::normalize($headers);

        // Handle OPTIONS requests for CORS preflight
        if ($method === 'OPTIONS') {
            $origin = $normalizedHeaders['origin'] ?? '';
            // If Origin is different from app URL, return CORS headers
            if ($origin && $origin !== $url) {
                return new ResultWithExchangeableIdToken(
                    ok: true,
                    shop: null,
                    idToken: null,
                    userId: null,
                    newIdTokenResponse: null,
                    log: new LogWithReq(
                        code: 'options_request',
                        detail: 'OPTIONS request handled for CORS preflight. Respond 204 No Content using the provided response.',
                        req: Request::normalizeForLog($req)
                    ),
                    response: new ResponseInfo(
                        status: 204,
                        body: '',
                        headers: [
                            'Access-Control-Max-Age' => '7200',
                            'Access-Control-Allow-Origin' => '*',
                            'Access-Control-Expose-Headers' => 'X-Shopify-Retry-Invalid-Session-Request',
                            'Access-Control-Allow-Headers' => 'Authorization, Content-Type'
                        ]
                    )
                );
            }
        }

        // Check for Authorization header
        if (!isset($normalizedHeaders['authorization'])) {
            return new ResultWithExchangeableIdToken(
                ok: false,
                shop: null,
                idToken: null,
                userId: null,
                newIdTokenResponse: null,
                log: new LogWithReq(
                    code: 'missing_authorization_header',
                    detail: 'Required `Authorization` header is missing. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: (object)[]
                )
            );
        }

        // Extract the Bearer token
        $authHeader = $normalizedHeaders['authorization'] ?? '';
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

        // Try to verify with old secret first (if provided), then new secret
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
                // We need to verify the audience claim for Admin UI Extension tokens.
                // Decode first, then manually verify aud
                $payload = JWT::decode($idToken, new Key($secret, 'HS256'));
                $payload = json_decode(json_encode($payload), true); // Convert to array
                break; // Successfully decoded
            } catch (Exception $e) {
                $verificationError = $e;
                continue; // Try next secret
            }
        }

        if ($payload === null) {
            // Determine if it was an expiration error
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
                    headers: [
                        'X-Shopify-Retry-Invalid-Session-Request' => '1'
                    ]
                )
            );
        }

        // Extract shop from dest claim
        $dest = $payload['dest'] ?? '';
        $shop = str_replace(['.myshopify.com', 'https://'], '', $dest);

        // Extract userId from sub claim
        $userId = $payload['sub'] ?? '';

        return new ResultWithExchangeableIdToken(
            ok: true,
            shop: $shop,
            idToken: new IdToken(
                exchangeable: true,
                token: $idToken,
                claims: $payload
            ),
            userId: $userId,
            newIdTokenResponse: [
                'status' => 401,
                'body' => '',
                'headers' => [
                    'X-Shopify-Retry-Invalid-Session-Request' => '1'
                ]
            ],
            log: new LogWithReq(
                code: 'verified',
                detail: 'Admin UI Extension request verified. Proceed with business logic.',
                req: Request::normalizeForLog($req)
            ),
            response: new ResponseInfo(
                status: 200,
                body: '',
                headers: (object)[]
            )
        );
    }
}
