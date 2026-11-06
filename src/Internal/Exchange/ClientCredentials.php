<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Exchange;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Shopify\App\Internal\Utils\Shop;
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
    /** The only assertion type Shopify's token endpoint accepts. Not a caller option. */
    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /**
     * Credentials that must never travel with a client assertion. Compared
     * case-insensitively against the injected client's configured headers.
     */
    private const CONFLICTING_CREDENTIAL_HEADERS = ['authorization', 'x-shopify-access-token'];

    /**
     * @param mixed $clientAssertion An app-created JWT, or null to keep
     *                               client-secret authentication.
     */
    public static function exchange(
        string $shop,
        $httpClient = null,
        array $appConfig = [],
        #[\SensitiveParameter] mixed $clientAssertion = null
    ): ClientCredentialsExchangeResult {
        $clientId = $appConfig['clientId'] ?? '';
        $clientSecret = $appConfig['clientSecret'] ?? '';
        $usesAssertion = $clientAssertion !== null;

        // Validate shop parameter (check is_string before empty to catch type coercion)
        if (!is_string($shop) || empty($shop)) {
            return self::configurationError("Expected shop to be a non-empty string, but got ''");
        }

        // Validate shop format (alphanumeric and hyphens only)
        if (!Shop::isValidShopName($shop)) {
            return self::configurationError(
                "Expected shop to be a valid shop domain (e.g., 'shop-name')"
            );
        }

        // A supplied assertion is opaque: it is never trimmed, decoded,
        // inspected or re-signed, but it must be a usable string. An invalid
        // one is an error, never a silent fallback to the client secret.
        if ($usesAssertion && !(is_string($clientAssertion) && trim($clientAssertion) !== '')) {
            return self::configurationError('Expected clientAssertion to be a non-empty string');
        }

        // Another credential on the shared client would authenticate this
        // request twice. Fail before sending, and leave the client untouched.
        if ($usesAssertion) {
            $clientError = self::assertionClientError($httpClient);
            if ($clientError !== null) {
                return self::configurationError($clientError);
            }
        }

        // Build the token endpoint URL. Assertion requests use the canonical
        // lowercase host; the secret path keeps its existing URL construction.
        $host = $usesAssertion ? strtolower($shop) : $shop;
        $tokenEndpoint = "https://{$host}.myshopify.com/admin/oauth/access_token";

        // Build request body
        $requestBody = $usesAssertion
            ? [
                'client_id' => $clientId,
                'grant_type' => 'client_credentials',
                'client_assertion_type' => self::ASSERTION_TYPE,
                'client_assertion' => $clientAssertion
            ]
            : [
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
        $reqObj = \Shopify\App\Internal\Utils\Request::redactForLog([
            'method' => 'POST',
            'url' => $tokenEndpoint,
            'headers' => $requestHeaders,
            'body' => json_encode($requestBody)
        ]);

        // Use injected client or create default one
        $client = $httpClient !== null ? $httpClient : new Client();
        $httpLogs = [];

        // Redirects can forward credentials. Assertion requests must also exclude other HTTP authentication.
        $requestOptions = [
            'headers' => $requestHeaders,
            'json' => $requestBody,
            'http_errors' => false,
            'allow_redirects' => false
        ];
        if ($usesAssertion) {
            $requestOptions['auth'] = null;
        }

        try {
            $response = $client->post($tokenEndpoint, $requestOptions);

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
            $responseData = json_decode($responseBody, true);

            // Build response object for logging. The token endpoint response
            // carries the newly issued access token, so redact it before this
            // goes into a log.
            $responseHeaders = [];
            foreach ($response->getHeaders() as $name => $values) {
                $responseHeaders[$name] = implode(', ', $values);
            }
            $resObj = [
                'status' => $statusCode,
                'headers' => empty($responseHeaders) ? (object)[] : $responseHeaders,
                'body' => \Shopify\App\Internal\Utils\Request::redactResponseBodyForLog($responseBody)
            ];

            // Handle 200 success
            if ($statusCode === 200) {
                return self::handleSuccessResponse($responseData, $shop, $httpLogs, $reqObj, $resObj);
            }

            // Redirects and proxy errors may have empty or HTML bodies.
            if (!is_array($responseData)) {
                $responseData = [];
            }
            return self::handleErrorResponse($responseData, $shop, $httpLogs, $reqObj, $resObj);
        } catch (TransferException $e) {
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

    /**
     * Build the existing configuration-error result. No HTTP request is made,
     * so there are no HTTP logs, and the supplied value is never echoed.
     */
    private static function configurationError(string $detail): ClientCredentialsExchangeResult
    {
        return new ClientCredentialsExchangeResult(
            ok: false,
            shop: null,
            accessToken: null,
            log: new Log(
                code: 'configuration_error',
                detail: $detail
            ),
            httpLogs: [],
            response: new ResponseInfo(
                status: 500,
                body: '',
                headers: (object)[]
            )
        );
    }

    /**
     * Guzzle 8 exposes getConfig() on Client, not ClientInterface. A wrapper
     * cannot describe its delegate's defaults. Custom subclasses and middleware
     * remain caller-controlled; this check establishes the supported API only.
     */
    private static function assertionClientError($httpClient): ?string
    {
        if ($httpClient === null) {
            return null;
        }
        if (!$httpClient instanceof Client) {
            return 'Client assertion requests require an inspectable HTTP client';
        }

        $headers = $httpClient->getConfig('headers') ?? [];
        if (!is_array($headers)) {
            return 'Client assertion requests require an inspectable HTTP client';
        }
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string)$name), self::CONFLICTING_CREDENTIAL_HEADERS, true)) {
                return 'Client assertion requests must not include other authentication credentials';
            }
        }

        $query = $httpClient->getConfig('query');
        if ($query !== null && $query !== '' && $query !== []) {
            return 'Client assertion requests must use the unmodified shop token endpoint';
        }
        return null;
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
