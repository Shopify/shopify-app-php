<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Exchange;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shopify\App\Types\TokenExchangeResult;
use Shopify\App\Types\TokenExchangeAccessToken;
use Shopify\App\Types\Log;
use Shopify\App\Internal\Utils\InputConverters;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::refreshTokenExchangedAccessToken() instead.
 */
class RefreshToken
{
    public static function refresh(TokenExchangeAccessToken|array $accessToken, array $appConfig, $httpClient = null): TokenExchangeResult
    {
        // Normalize array to TokenExchangeAccessToken object using InputConverters
        $accessToken = InputConverters::toTokenExchangeAccessToken($accessToken);

        $shop = $accessToken->shop ?? '';
        $refreshToken = $accessToken->refreshToken;
        $expires = $accessToken->expires ?? '';
        $refreshTokenExpires = $accessToken->refreshTokenExpires ?? '';

        $clientId = $appConfig['clientId'] ?? '';
        $clientSecret = $appConfig['clientSecret'] ?? '';

        // Validate refresh token expiration
        if (!empty($refreshTokenExpires)) {
            $refreshTokenExpiryTime = strtotime($refreshTokenExpires);
            if ($refreshTokenExpiryTime !== false && $refreshTokenExpiryTime <= time()) {
                return new TokenExchangeResult(
                    ok: false,
                    shop: $shop,
                    accessToken: null,
                    log: new Log(
                        code: 'refresh_token_expired',
                        detail: 'Refresh token has expired. User must re-authenticate. Respond 401 Unauthorized using the provided response.'
                    ),
                    httpLogs: [],
                    response: new ResponseInfo(
                        status: 401,
                        body: '',
                        headers: (object)[]
                    )
                );
            }
        }

        // Check if access token is still valid (with 60-second buffer)
        if (!empty($expires)) {
            $expiryTime = strtotime($expires);
            if ($expiryTime !== false && $expiryTime > (time() + 60)) {
                return new TokenExchangeResult(
                    ok: true,
                    shop: $shop,
                    accessToken: null,
                    log: new Log(
                        code: 'token_still_valid',
                        detail: 'Access token is still valid. No refresh needed. Proceed with business logic.'
                    ),
                    httpLogs: [],
                    response: new ResponseInfo(
                        status: 200,
                        body: '',
                        headers: (object)[]
                    )
                );
            }
        }

        // Validate required parameters
        if (empty($shop)) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: "Expected shop to be a non-empty string, but got ''"
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

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

        if (empty($refreshToken)) {
            return new TokenExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: "Expected refresh token to be a non-empty string, but got ''"
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Construct shop URL for API request
        $shopUrl = 'https://' . $shop . '.myshopify.com';

        // Build the request body
        $requestBody = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ];

        $tokenEndpoint = "{$shopUrl}/admin/oauth/access_token";

        // Make the request with retry logic for 5xx responses
        $client = $httpClient !== null ? $httpClient : new Client();
        $maxRetries = 2;
        $attempt = 0;
        $httpLogs = [];

        // Build the request headers for logging
        $requestHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => \Shopify\App\Internal\Utils\UserAgent::get()
        ];

        while ($attempt <= $maxRetries) {
            try {
                $response = $client->post($tokenEndpoint, [
                    'headers' => $requestHeaders,
                    'json' => $requestBody,
                    'http_errors' => false
                ]);

                $statusCode = $response->getStatusCode();
                $responseBody = (string) $response->getBody();
                $responseData = json_decode($responseBody, true) ?? [];
                $responseHeaders = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $responseHeaders[$name] = implode(', ', $values);
                }

                // Build request/response objects for httpLogs
                $reqLog = [
                    'url' => $tokenEndpoint,
                    'method' => 'POST',
                    'headers' => $requestHeaders,
                    'body' => ''  // Don't log sensitive body
                ];
                $resLog = [
                    'status' => $statusCode,
                    'headers' => $responseHeaders,
                    'body' => ''  // Don't log sensitive body
                ];

                // Handle 200 success
                if ($statusCode === 200) {
                    return self::handleSuccessResponse($responseData, $shop, $httpLogs, $reqLog, $resLog);
                }

                // Handle 5xx server errors with retry
                if ($statusCode >= 500 && $statusCode <= 504) {
                    if ($attempt < $maxRetries) {
                        $httpLogs[] = [
                            'code' => 'server_error_retry',
                            'detail' => "Server error {$statusCode}, retrying (attempt " . ($attempt + 1) . " of {$maxRetries}).",
                            'req' => $reqLog,
                            'res' => $resLog
                        ];
                        $attempt++;
                        continue;
                    }

                    $httpLogs[] = [
                        'code' => 'server_error',
                        'detail' => 'Max retries reached after server errors. Respond 500 Internal Server Error using the provided response.',
                        'req' => $reqLog,
                        'res' => $resLog
                    ];
                    return new TokenExchangeResult(
                        ok: false,
                        shop: $shop,
                        accessToken: null,
                        log: new Log(
                            code: 'server_error',
                            detail: 'Max retries reached after server errors. Respond 500 Internal Server Error using the provided response.'
                        ),
                        httpLogs: $httpLogs,
                        response: new ResponseInfo(
                            status: 500,
                            body: '',
                            headers: (object)[]
                        )
                    );
                }

                // Handle error responses
                return self::handleErrorResponse($responseData, $shop, $httpLogs, $reqLog, $resLog);
            } catch (RequestException $e) {
                $reqLog = [
                    'url' => $tokenEndpoint,
                    'method' => 'POST',
                    'headers' => $requestHeaders,
                    'body' => ''
                ];
                $resLog = [
                    'status' => 0,
                    'headers' => (object)[],
                    'body' => ''
                ];
                $httpLogs[] = [
                    'code' => 'network_error',
                    'detail' => 'Network error occurred during token refresh. Respond 500 Internal Server Error using the provided response.',
                    'req' => $reqLog,
                    'res' => $resLog
                ];
                return new TokenExchangeResult(
                    ok: false,
                    shop: $shop,
                    accessToken: null,
                    log: new Log(
                        code: 'network_error',
                        detail: 'Network error occurred during token refresh. Respond 500 Internal Server Error using the provided response.'
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
            shop: $shop,
            accessToken: null,
            log: new Log(
                code: 'unexpected_error',
                detail: 'Unexpected error during token refresh. Return 500 Internal Server Error.'
            ),
            httpLogs: $httpLogs,
            response: new ResponseInfo(
                status: 500,
                body: '',
                headers: (object)[]
            )
        );
    }

    private static function handleSuccessResponse(array $responseData, string $shop, array $httpLogs, array $reqLog, array $resLog): TokenExchangeResult
    {
        $accessToken = $responseData['access_token'] ?? '';
        $expiresIn = $responseData['expires_in'] ?? 0;
        $scope = $responseData['scope'] ?? '';
        $refreshToken = $responseData['refresh_token'] ?? '';
        $refreshTokenExpiresIn = $responseData['refresh_token_expires_in'] ?? 0;

        // Calculate expiration timestamp (UTC)
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + $expiresIn);
        $refreshTokenExpires = gmdate('Y-m-d\TH:i:s\Z', time() + $refreshTokenExpiresIn);

        $accessTokenObj = new TokenExchangeAccessToken(
            accessMode: 'offline',
            shop: $shop,
            token: $accessToken,
            expires: $expires,
            scope: $scope,
            refreshToken: $refreshToken,
            refreshTokenExpires: $refreshTokenExpires,
            user: null
        );

        $httpLogs[] = [
            'code' => 'success',
            'detail' => 'Token refresh successful. Store the new access and refresh token then proceed with business logic.',
            'req' => $reqLog,
            'res' => $resLog
        ];

        return new TokenExchangeResult(
            ok: true,
            shop: $shop,
            accessToken: $accessTokenObj,
            log: new Log(
                code: 'success',
                detail: 'Token refresh successful. Store the new access and refresh token then proceed with business logic.'
            ),
            httpLogs: $httpLogs,
            response: new ResponseInfo(
                status: 200,
                body: '',
                headers: (object)[]
            )
        );
    }

    private static function handleErrorResponse(array $responseData, string $shop, array $httpLogs, array $reqLog, array $resLog): TokenExchangeResult
    {
        $error = $responseData['error'] ?? 'unknown_error';

        // Handle invalid_grant
        if ($error === 'invalid_grant') {
            $httpLogs[] = [
                'code' => 'invalid_grant',
                'detail' => 'Refresh token is invalid, expired, or has been revoked. User must re-authenticate. Respond 401 Unauthorized using the provided response.',
                'req' => $reqLog,
                'res' => $resLog
            ];
            return new TokenExchangeResult(
                ok: false,
                shop: $shop,
                accessToken: null,
                log: new Log(
                    code: 'invalid_grant',
                    detail: 'Refresh token is invalid, expired, or has been revoked. User must re-authenticate. Respond 401 Unauthorized using the provided response.'
                ),
                httpLogs: $httpLogs,
                response: new ResponseInfo(
                    status: 401,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Handle invalid_client
        if ($error === 'invalid_client') {
            $httpLogs[] = [
                'code' => 'invalid_client',
                'detail' => 'Client credentials are invalid or app has been uninstalled. Respond 500 Internal Server Error using the provided response.',
                'req' => $reqLog,
                'res' => $resLog
            ];
            return new TokenExchangeResult(
                ok: false,
                shop: $shop,
                accessToken: null,
                log: new Log(
                    code: 'invalid_client',
                    detail: 'Client credentials are invalid or app has been uninstalled. Respond 500 Internal Server Error using the provided response.'
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
            'code' => 'refresh_error',
            'detail' => "Token refresh failed with error: {$error}. Respond 500 Internal Server Error using the provided response.",
            'req' => $reqLog,
            'res' => $resLog
        ];
        return new TokenExchangeResult(
            ok: false,
            shop: $shop,
            accessToken: null,
            log: new Log(
                code: 'refresh_error',
                detail: "Token refresh failed with error: {$error}. Respond 500 Internal Server Error using the provided response."
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
