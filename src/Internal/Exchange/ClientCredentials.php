<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Exchange;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shopify\App\Types\ClientCredentialsExchangeResult;
use Shopify\App\Types\ClientCredentialsAccessToken;
use Shopify\App\Types\Log;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::exchangeUsingClientCredentials() instead.
 */
class ClientCredentials
{
    public static function exchange(
        string $shop,
        $httpClient = null,
        array $appConfig = []
    ): ClientCredentialsExchangeResult {
        $clientId = $appConfig['clientId'] ?? '';
        $clientSecret = $appConfig['clientSecret'] ?? '';

        // Validate shop parameter (check is_string before empty to catch type coercion)
        if (!is_string($shop) || empty($shop)) {
            return new ClientCredentialsExchangeResult(
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

        // Validate shop format (alphanumeric and hyphens only)
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*$/', $shop)) {
            return new ClientCredentialsExchangeResult(
                ok: false,
                shop: null,
                accessToken: null,
                log: new Log(
                    code: 'configuration_error',
                    detail: "Expected shop to be a valid shop domain (e.g., 'shop-name')"
                ),
                httpLogs: [],
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Build the token endpoint URL
        $tokenEndpoint = "https://{$shop}.myshopify.com/admin/oauth/access_token";

        // Build request body
        $requestBody = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials'
        ];

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

        // Use injected client or create default one
        $client = $httpClient !== null ? $httpClient : new Client();
        $httpLogs = [];

        try {
            $response = $client->post($tokenEndpoint, [
                'headers' => $requestHeaders,
                'json' => $requestBody,
                'http_errors' => false
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
                return self::handleSuccessResponse($responseData, $shop, $httpLogs, $reqObj, $resObj);
            }

            // Handle error responses
            return self::handleErrorResponse($responseData, $shop, $httpLogs, $reqObj, $resObj);
        } catch (RequestException $e) {
            $resObj = [
                'status' => 0,
                'headers' => (object)[],
                'body' => ''
            ];
            $httpLogs[] = [
                'code' => 'network_error',
                'detail' => 'Network error occurred during client credentials exchange. Respond 500 Internal Server Error using the provided response.',
                'req' => $reqObj,
                'res' => $resObj
            ];
            return new ClientCredentialsExchangeResult(
                ok: false,
                shop: $shop,
                accessToken: null,
                log: new Log(
                    code: 'network_error',
                    detail: 'Network error occurred during client credentials exchange. Respond 500 Internal Server Error using the provided response.'
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

    private static function handleSuccessResponse(array $responseData, string $shop, array $httpLogs, array $reqObj, array $resObj): ClientCredentialsExchangeResult
    {
        $accessToken = $responseData['access_token'] ?? '';
        $expiresIn = $responseData['expires_in'] ?? null;
        $scope = $responseData['scope'] ?? '';

        // Calculate expiration timestamp (UTC)
        $expires = $expiresIn !== null ? gmdate('Y-m-d\TH:i:s\Z', time() + $expiresIn) : null;

        $accessTokenObj = new ClientCredentialsAccessToken(
            accessMode: 'offline',
            shop: $shop,
            token: $accessToken,
            expires: $expires,
            scope: $scope,
            user: null
        );

        $httpLogs[] = [
            'code' => 'success',
            'detail' => 'Client credentials exchange successful. Store the access token and proceed with business logic.',
            'req' => $reqObj,
            'res' => $resObj
        ];

        return new ClientCredentialsExchangeResult(
            ok: true,
            shop: $shop,
            accessToken: $accessTokenObj,
            log: new Log(
                code: 'success',
                detail: 'Client credentials exchange successful. Store the access token and proceed with business logic.'
            ),
            httpLogs: $httpLogs,
            response: new ResponseInfo(
                status: 200,
                body: '',
                headers: (object)[]
            )
        );
    }

    private static function handleErrorResponse(array $responseData, string $shop, array $httpLogs, array $reqObj, array $resObj): ClientCredentialsExchangeResult
    {
        $error = $responseData['error'] ?? 'unknown_error';

        // Handle invalid_client
        if ($error === 'invalid_client') {
            $httpLogs[] = [
                'code' => 'invalid_client',
                'detail' => 'Client credentials are invalid or the app has been uninstalled. Respond 500 Internal Server Error using the provided response.',
                'req' => $reqObj,
                'res' => $resObj
            ];
            return new ClientCredentialsExchangeResult(
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
            'detail' => "Client credentials exchange failed with error: {$error}. Respond 500 Internal Server Error using the provided response.",
            'req' => $reqObj,
            'res' => $resObj
        ];
        return new ClientCredentialsExchangeResult(
            ok: false,
            shop: $shop,
            accessToken: null,
            log: new Log(
                code: 'exchange_error',
                detail: "Client credentials exchange failed with error: {$error}. Respond 500 Internal Server Error using the provided response."
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
