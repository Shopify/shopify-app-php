<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Verify;

use Shopify\App\Internal\Utils\Headers;
use Shopify\App\Internal\Utils\Request;
use Shopify\App\Types\ResultForReq;
use Shopify\App\Types\LogWithReq;
use Shopify\App\Types\ResponseInfo;

/**
 * Shared Body HMAC in Header Verification
 *
 * This class provides the core HMAC verification logic used by both
 * webhook and flow action request verification, where the HMAC signature
 * of the request body is provided in the X-Shopify-Hmac-SHA256 header.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
class BodyHmacInHeader
{
    /**
     * Verifies HMAC-signed requests from Shopify (webhooks, flow actions, etc.)
     *
     * @param array $req The request object containing method, headers, and body
     * @param array $config The app configuration with clientSecret
     * @param string $requestType The type of request for log messages (e.g., "Webhook", "Flow action")
     * @return ResultForReq Verification result with ok, shop, log, and response fields
     */
    public static function verify(array $req, array $config, string $requestType): ResultForReq
    {
        // Validate request object
        $method = $req['method'] ?? null;
        if (!is_string($method) || $method === '') {
            return new ResultForReq(
                ok: false,
                shop: null,
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
            return new ResultForReq(
                ok: false,
                shop: null,
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

        $body = $req['body'] ?? null;
        if (!is_string($body)) {
            return new ResultForReq(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: 'Expected request.body to be a string',
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

        // Request method validation
        if ($method !== 'POST') {
            return new ResultForReq(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'post_method_expected',
                    detail: "{$requestType} requests are expected to use the POST method. Respond 405 Method Not Allowed using the provided response.",
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 405,
                    body: 'Method not allowed',
                    headers: (object)[]
                )
            );
        }

        // Normalize headers for case-insensitive comparison
        $normalizedHeaders = Headers::normalize($headers);

        // Check for HMAC header first (most important for security)
        if (!isset($normalizedHeaders['x-shopify-hmac-sha256'])) {
            return new ResultForReq(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'missing_hmac_header',
                    detail: 'Required `X-Shopify-Hmac-SHA256` header is missing. Respond 400 Bad Request using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 400,
                    body: 'Bad Request',
                    headers: (object)[]
                )
            );
        }

        // HMAC validation
        $receivedHmac = $normalizedHeaders['x-shopify-hmac-sha256'] ?? '';

        // Try current secret first
        $calculatedHmac = base64_encode(hash_hmac('sha256', $body, $clientSecret, true));
        $hmacValid = hash_equals($calculatedHmac, $receivedHmac);

        // If current secret fails and old secret is provided, try old secret
        if (!$hmacValid && $oldClientSecret !== null) {
            $calculatedHmacOld = base64_encode(hash_hmac('sha256', $body, $oldClientSecret, true));
            $hmacValid = hash_equals($calculatedHmacOld, $receivedHmac);
        }

        if (!$hmacValid) {
            return new ResultForReq(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'invalid_hmac',
                    detail: '`X-Shopify-Hmac-SHA256` header value does not match the body\'s HMAC. Respond 401 Unauthorized using the provided response.',
                    req: Request::normalizeForLog($req)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: 'Unauthorized',
                    headers: (object)[]
                )
            );
        }

        // Extract shop from header
        $shopDomain = $normalizedHeaders['x-shopify-shop-domain'] ?? '';
        // Extract shop by removing .myshopify.com suffix
        $shop = str_replace('.myshopify.com', '', $shopDomain);

        return new ResultForReq(
            ok: true,
            shop: $shop,
            log: new LogWithReq(
                code: 'verified',
                detail: "{$requestType} request verified successfully. Respond 200 OK using the provided response.",
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
