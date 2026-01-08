<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Verify;

use Shopify\App\Internal\Utils\Request;
use Shopify\App\Types\ResultWithLoggedInCustomerId;
use Shopify\App\Types\LogWithReq;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::verifyAppProxyReq() instead.
 */
class AppProxy
{
    public static function verify(array $req, array $config): ResultWithLoggedInCustomerId
    {
        // Validate request object
        $url = $req['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return new ResultWithLoggedInCustomerId(
                ok: false,
                shop: null,
                loggedInCustomerId: null,
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

        // Parse query parameters from URL
        $parsedUrl = parse_url($url);
        $queryString = $parsedUrl['query'] ?? '';

        // Manual parsing to preserve original parameter names (e.g., "extra[]" not "extra")
        $params = [];
        if ($queryString !== '') {
            $pairs = explode('&', $queryString);
            foreach ($pairs as $pair) {
                if (strpos($pair, '=') !== false) {
                    [$key, $value] = explode('=', $pair, 2);
                    $key = urldecode($key);
                    $value = urldecode($value);

                    // Check if this key already exists (for multiple values with same key)
                    if (isset($params[$key])) {
                        // Convert to array if not already
                        if (!is_array($params[$key])) {
                            $params[$key] = [$params[$key]];
                        }
                        $params[$key][] = $value;
                    } else {
                        $params[$key] = $value;
                    }
                }
            }
        }

        // Check for missing timestamp
        if (!isset($params['timestamp'])) {
            return new ResultWithLoggedInCustomerId(
                ok: false,
                shop: null,
                loggedInCustomerId: null,
                log: new LogWithReq(
                    code: 'missing_timestamp',
                    detail: 'Required `timestamp` query parameter is missing. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: (object)[]
                )
            );
        }

        // Check timestamp is valid and not too old (prevents replay attacks)
        // Validate that timestamp is a numeric string
        if (!is_numeric($params['timestamp'])) {
            return new ResultWithLoggedInCustomerId(
                ok: false,
                shop: null,
                loggedInCustomerId: null,
                log: new LogWithReq(
                    code: 'invalid_timestamp',
                    detail: 'The `timestamp` query parameter is not a valid integer. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: (object)[]
                )
            );
        }

        $timestamp = intval($params['timestamp']);
        $currentTime = time();
        $timeDiff = abs($currentTime - $timestamp);

        if ($timeDiff > 90) {
            return new ResultWithLoggedInCustomerId(
                ok: false,
                shop: null,
                loggedInCustomerId: null,
                log: new LogWithReq(
                    code: 'timestamp_too_old',
                    detail: 'The `timestamp` query parameter is more than 90 seconds old. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: (object)[]
                )
            );
        }

        // Check for missing signature
        if (!isset($params['signature']) || !is_string($params['signature'])) {
            return new ResultWithLoggedInCustomerId(
                ok: false,
                shop: null,
                loggedInCustomerId: null,
                log: new LogWithReq(
                    code: 'missing_signature',
                    detail: 'Required `signature` query parameter is missing. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: (object)[]
                )
            );
        }

        // Extract and remove signature from params
        $receivedSignature = $params['signature'];
        unset($params['signature']);

        // Generate param string
        $paramString = self::generateParamString($params);

        // Try current secret first
        $calculatedHmac = hash_hmac('sha256', $paramString, $clientSecret);
        $signatureValid = hash_equals($calculatedHmac, $receivedSignature);

        // If current secret fails and old secret is provided, try old secret
        if (!$signatureValid && $oldClientSecret !== null) {
            $calculatedHmacOld = hash_hmac('sha256', $paramString, $oldClientSecret);
            $signatureValid = hash_equals($calculatedHmacOld, $receivedSignature);
        }

        if (!$signatureValid) {
            return new ResultWithLoggedInCustomerId(
                ok: false,
                shop: null,
                loggedInCustomerId: null,
                log: new LogWithReq(
                    code: 'invalid_signature',
                    detail: '`signature` query parameter does not match the expected HMAC. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: (object)[]
                )
            );
        }

        // Extract shop by removing .myshopify.com suffix from shop param
        $shopDomain = $params['shop'] ?? '';
        $shop = str_replace('.myshopify.com', '', $shopDomain);
        if (empty($shop)) {
            $shop = null;
        }

        // Extract logged in customer ID
        $loggedInCustomerId = $params['logged_in_customer_id'] ?? null;

        return new ResultWithLoggedInCustomerId(
            ok: true,
            shop: $shop,
            loggedInCustomerId: $loggedInCustomerId,
            log: new LogWithReq(
                code: 'verified',
                detail: 'App Proxy request verified successfully. Proceed with business logic.',
                req: Request::normalizeForLog($req)
            ),
            response: new ResponseInfo(
                status: 200,
                body: '',
                headers: (object)[]
            )
        );
    }

    /**
     * Generate the param string for HMAC calculation.
     *
     * Alphabetically sorts params and stringifies them according to Shopify's spec:
     * - Separate key & value using =
     * - No separators between key-value pairs
     * - Array values are comma-separated
     */
    private static function generateParamString(array $params): string
    {
        // Sort params alphabetically by key
        ksort($params);

        // Build param string
        $paramString = '';
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                // Arrays are stringified as comma-separated values
                $paramString .= $key . '=' . implode(',', $value);
            } else {
                $paramString .= $key . '=' . $value;
            }
        }

        return $paramString;
    }
}
