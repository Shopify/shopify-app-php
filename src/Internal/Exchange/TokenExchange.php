<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Exchange;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shopify\App\Types\TokenExchangeResult;
use Shopify\App\Types\TokenExchangeAccessToken;
use Shopify\App\Types\IdToken;
use Shopify\App\Types\Log;
use Shopify\App\Internal\Utils\InputConverters;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::exchangeUsingTokenExchange() instead.
 */
class TokenExchange
{
    public static function exchange(
        string $accessMode,
        IdToken|array|null $idToken,  // ← Union type: accept IdToken object OR array
        ?array $invalidTokenResponse = null,
        $httpClient = null,
        array $appConfig = []
    ): TokenExchangeResult {
        $clientId = $appConfig['clientId'] ?? '';
        $clientSecret = $appConfig['clientSecret'] ?? '';

        // Validate required parameters
        if (empty($clientId)) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: "Expected clientId to be a non-empty string, but got ''"
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        if (empty($accessMode)) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: "Expected access mode to be 'online' or 'offline', but got ''"
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Validate access mode
        if (!in_array($accessMode, ['online', 'offline'])) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: "Expected access mode to be 'online' or 'offline', but got '{$accessMode}'"
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Validate and normalize idToken
        if ($idToken === null) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: 'Expected idToken to be an object with exchangeable, token, and claims properties'
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // If array, validate token field type before normalization
        if (is_array($idToken)) {
            if (!isset($idToken['token']) || !is_string($idToken['token'])) {
                return new TokenExchangeResult(
                    ok: false,
                    shop: null,
                    accessToken: null,
                    log: new Log(
                        code: 'configuration_error',
                        detail: 'Expected idToken.token to be a non-empty string'
                    ),
                    httpLogs: [],
                    response: new ResponseInfo(
                        status: 500,
                        body: '',
                        headers: (object)[]
                    )
                );
            }
        }

        // Normalize idToken to object
        $idToken = InputConverters::toIdToken($idToken);

        // Validate idToken structure
        if (!$idToken->exchangeable) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: 'ID token is not exchangeable. Only App Home, Admin UI extension & POS UI Extension Id tokens can be exchanged.'
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        $jwtString = $idToken->token;
        $claims = $idToken->claims;

        if (empty($jwtString) || !is_string($jwtString)) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: 'Expected idToken.token to be a non-empty string'
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Extract shop from claims
        $shop = $claims['dest'] ?? '';

        if (empty($shop) || !is_string($shop)) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: 'Expected idToken.claims.dest to be a non-empty string'
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Validate shop URL format (basic validation)
        if (strpos($shop, '.myshopify.com') === false) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: "Expected idToken.claims.dest to be a valid shop URL (e.g., 'https://shop.myshopify.com' or 'shop.myshopify.com')"
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Normalize shop URL for API request
        $shopUrl = (strpos($shop, 'https://') === 0) ? $shop : 'https://' . $shop;

        // Extract shop name (remove https:// and .myshopify.com)
        $shopName = str_replace(['https://', 'http://', '.myshopify.com'], '', $shop);

        // Step 2: Make the Token Exchange Request
        $requestedTokenType = "urn:shopify:params:oauth:token-type:{$accessMode}-access-token";

        $requestBody = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $jwtString,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => $requestedTokenType,
            'expiring' => 1
        ];

        $tokenEndpoint = "{$shopUrl}/admin/oauth/access_token";

        // Build request object for logging
        $requestHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => \Shopify\App\Internal\Utils\UserAgent::get()
        ];
        $reqObj = [
            'method' => 'POST',
            'url' => $tokenEndpoint,
            'headers' => $requestHeaders,
            'body' => json_encode($requestBody)
        ];

        // Make the request with retry logic for 429 responses
        // Use injected client or create default one
        $client = $httpClient !== null ? $httpClient : new Client();
        $maxRetries = 2;
        $attempt = 0;
        $httpLogs = [];

        while ($attempt <= $maxRetries) {
            try {
                $response = $client->post($tokenEndpoint, [
                    'headers' => $requestHeaders,
                    'json' => $requestBody,
                    'http_errors' => false // Don't throw exceptions on error status codes
                ]);

                $statusCode = $response->getStatusCode();
                $responseBody = (string) $response->getBody();
                $responseData = json_decode($responseBody, true);

                // Build response object for logging
                $responseHeaders = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $responseHeaders[$name] = implode(', ', $values);
                }
                $resObj = [
                    'status' => $statusCode,
                    'headers' => empty($responseHeaders) ? (object)[] : $responseHeaders,
                    'body' => $responseBody
                ];

                // Handle 200 success
                if ($statusCode === 200) {
                    $log = [
                        'code' => 'success',
                        'detail' => 'Token exchange successful. Store the access token and proceed with business logic.',
                    ];
                    $httpLogs[] = [
                        'code' => $log['code'],
                        'detail' => $log['detail'],
                        'req' => $reqObj,
                        'res' => $resObj
                    ];
                    return self::handleSuccessResponse($responseData, $shopName, $accessMode, $httpLogs);
                }

                // Handle 429 rate limit
                if ($statusCode === 429 && $attempt < $maxRetries) {
                    $retryAfter = $response->getHeader('Retry-After')[0] ?? 1;
                    $httpLogs[] = [
                        'code' => 'rate_limited_retry',
                        'detail' => "Rate limited. Retrying after {$retryAfter} seconds.",
                        'req' => $reqObj,
                        'res' => $resObj
                    ];
                    sleep((int) $retryAfter);
                    $attempt++;
                    continue;
                }

                if ($statusCode === 429 && $attempt === $maxRetries) {
                    $httpLogs[] = [
                        'code' => 'rate_limit_exceeded',
                        'detail' => 'Max retries reached after rate limiting. Respond 429 Too Many Requests using the provided response.',
                        'req' => $reqObj,
                        'res' => $resObj
                    ];
                    return new TokenExchangeResult(
                        ok: false,
                        shop: $shopName,
                        accessToken: null,
                        log: new Log(
                            code: 'rate_limit_exceeded',
                            detail: 'Max retries reached after rate limiting. Respond 429 Too Many Requests using the provided response.'
                        ),
                        httpLogs: $httpLogs,
                        response: new ResponseInfo(
                            status: 429,
                            body: json_encode(['error' => 'Too many requests']),
                            headers: ['Content-Type' => 'application/json']
                        )
                    );
                }

                // Handle error responses
                return self::handleErrorResponse($responseData, $shopName, $invalidTokenResponse, $httpLogs, $reqObj, $resObj);
            } catch (RequestException $e) {
                $resObj = [
                    'status' => 0,
                    'headers' => (object)[],
                    'body' => ''
                ];
                $httpLogs[] = [
                    'code' => 'network_error',
                    'detail' => 'Network error occurred during token exchange. Respond 500 Internal Server Error using the provided response.',
                    'req' => $reqObj,
                    'res' => $resObj
                ];
                return new TokenExchangeResult(
                    ok: false,
                    shop: $shopName,
                    accessToken: null,
                    log: new Log(
                        code: 'network_error',
                        detail: 'Network error occurred during token exchange. Respond 500 Internal Server Error using the provided response.'
                    ),
                    httpLogs: $httpLogs,
                    response: new ResponseInfo(
                        status: 500,
                        body: '',
                        headers: (object)[]
                    )
                );
            }
        }

        // Should not reach here, but return error just in case
        return new TokenExchangeResult(
            ok: false,
            shop: $shopName,
            accessToken: null,
            log: new Log(
                code: 'unexpected_error',
                detail: 'Unexpected error during token exchange. Return 500 Internal Server Error.'
            ),
            httpLogs: $httpLogs,
            response: new ResponseInfo(
                status: 500,
                body: '',
                headers: (object)[]
            )
        );
    }

    private static function handleSuccessResponse(array $responseData, string $shop, string $accessMode, array $httpLogs): TokenExchangeResult
    {
        $accessToken = $responseData['access_token'] ?? '';
        $expiresIn = $responseData['expires_in'] ?? null;
        $scope = $responseData['scope'] ?? '';
        $refreshToken = $responseData['refresh_token'] ?? '';
        $refreshTokenExpiresIn = $responseData['refresh_token_expires_in'] ?? null;
        $associatedUser = $responseData['associated_user'] ?? null;
        $associatedUserScope = $responseData['associated_user_scope'] ?? '';

        // Calculate expiration timestamp (UTC)
        // If expires_in is null or not provided, the token doesn't expire
        $expires = $expiresIn !== null ? gmdate('Y-m-d\TH:i:s\Z', time() + $expiresIn) : null;
        $refreshTokenExpires = $refreshTokenExpiresIn !== null
            ? gmdate('Y-m-d\TH:i:s\Z', time() + $refreshTokenExpiresIn)
            : null;

        // Build user array for online tokens
        $user = null;
        if ($accessMode === 'online' && $associatedUser !== null) {
            $user = [
                'id' => $associatedUser['id'] ?? 0,
                'firstName' => $associatedUser['first_name'] ?? '',
                'lastName' => $associatedUser['last_name'] ?? '',
                'scope' => $associatedUserScope,
                'email' => $associatedUser['email'] ?? '',
                'accountOwner' => $associatedUser['account_owner'] ?? false,
                'locale' => $associatedUser['locale'] ?? '',
                'collaborator' => $associatedUser['collaborator'] ?? false,
                'emailVerified' => $associatedUser['email_verified'] ?? false
            ];
        }

        $accessTokenObj = new TokenExchangeAccessToken(
            accessMode: $accessMode,
            shop: $shop,
            token: $accessToken,
            expires: $expires,
            scope: $scope,
            refreshToken: $refreshToken,
            refreshTokenExpires: $refreshTokenExpires,
            user: $user
        );

        return new TokenExchangeResult(
            ok: true,
            shop: $shop,
            accessToken: $accessTokenObj,
            log: new Log(
                code: 'success',
                detail: 'Token exchange successful. Store the access token and proceed with business logic.'
            ),
            httpLogs: $httpLogs,
            response: new ResponseInfo(
                status: 200,
                body: '',
                headers: (object)[]
            )
        );
    }

    private static function handleErrorResponse(array $responseData, string $shop, ?array $invalidTokenResponse, array $httpLogs, array $reqObj, array $resObj): TokenExchangeResult
    {
        $error = $responseData['error'] ?? 'unknown_error';

        // Handle invalid_subject_token
        if ($error === 'invalid_subject_token') {
            $httpLogs[] = [
                'code' => 'invalid_subject_token',
                'detail' => 'The ID token is invalid. Respond 401 Unauthorized using the provided response.',
                'req' => $reqObj,
                'res' => $resObj
            ];

            // Determine response based on invalidTokenResponse
            $response = $invalidTokenResponse ?? [
                'status' => 401,
                'body' => '',
                'headers' => (object)[]
            ];

            return new TokenExchangeResult(
                ok: false,
                shop: $shop,
                accessToken: null,
                log: new Log(
                    code: 'invalid_subject_token',
                    detail: 'The ID token is invalid. Respond 401 Unauthorized using the provided response.'
                ),
                httpLogs: $httpLogs,
                response: new ResponseInfo(
                    status: $response['status'],
                    body: $response['body'],
                    headers: $response['headers']
                )
            );
        }

        // Handle invalid_client
        if ($error === 'invalid_client') {
            $httpLogs[] = [
                'code' => 'invalid_client',
                'detail' => 'Client credentials are invalid or the app has been uninstalled. Respond 500 Internal Server Error using the provided response.',
                'req' => $reqObj,
                'res' => $resObj
            ];
            return new TokenExchangeResult(
                ok: false,
                shop: $shop,
                accessToken: null,
                log: new Log(
                    code: 'invalid_client',
                    detail: 'Client credentials are invalid or the app has been uninstalled. Respond 500 Internal Server Error using the provided response.'
                ),
                httpLogs: $httpLogs,
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Fallback for other errors
        $httpLogs[] = [
            'code' => 'exchange_error',
            'detail' => "Token exchange failed with error: {$error}. Respond 500 Internal Server Error using the provided response.",
            'req' => $reqObj,
            'res' => $resObj
        ];
        return new TokenExchangeResult(
            ok: false,
            shop: $shop,
            accessToken: null,
            log: new Log(
                code: 'exchange_error',
                detail: "Token exchange failed with error: {$error}. Respond 500 Internal Server Error using the provided response."
            ),
            httpLogs: $httpLogs,
            response: new ResponseInfo(
                status: 500,
                body: '',
                headers: (object)[]
            )
        );
    }
}
