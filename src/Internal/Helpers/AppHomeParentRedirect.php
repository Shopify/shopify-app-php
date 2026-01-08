<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Helpers;

use Shopify\App\Internal\Utils\Headers;
use Shopify\App\Internal\Utils\Request;
use Shopify\App\Types\ResultForReq;
use Shopify\App\Types\LogWithReq;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::appHomeParentRedirect() instead.
 */
class AppHomeParentRedirect
{
    private const RESTRICTED_PARAMS = [
        'hmac',
        'locale',
        'protocol',
        'session',
        'id_token',
        'shop',
        'timestamp',
        'host',
        'embedded',
        'appLoadId'
    ];

    private const LINK_HEADER = '<https://cdn.shopify.com>; rel="preconnect", <https://cdn.shopify.com/shopifycloud/app-bridge.js>; rel="preload"; as="script", <https://cdn.shopify.com/shopifycloud/polaris.js>; rel="preload"; as="script"';

    /**
     * Generate a redirect response that breaks out of the app home iframe.
     *
     * @param array $request Request object with method, headers, url, and body
     * @param array $config App configuration with clientId
     * @param string $redirectUrl The URL to redirect to
     * @param string $shop The shop domain (e.g., "test-shop")
     * @param string|null $target Target window: "_top" or "_blank" (default: "_top")
     * @return ResultForReq Result with ok, shop, log, and response
     */
    public static function redirect(
        array $request,
        array $config,
        string $redirectUrl,
        string $shop,
        ?string $target = null
    ): ResultForReq {
        $clientId = $config['clientId'] ?? '';
        $shopDomain = "{$shop}.myshopify.com";

        // Validate request object
        $headers = $request['headers'] ?? null;
        if (!is_array($headers)) {
            return new ResultForReq(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: 'Expected request.headers to be an object',
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        $url = $request['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return new ResultForReq(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: 'Expected request.url to be a non-empty string',
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Default target to _top
        $target = $target ?? '_top';

        // Validate target
        if ($target !== '_top' && $target !== '_blank') {
            return new ResultForReq(
                ok: false,
                shop: $shop,
                log: new LogWithReq(
                    code: 'invalid_target',
                    detail: "Target must be '_top' or '_blank'. Received {$target}. Respond 400 Bad Request using the provided response.",
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 400,
                    body: 'Bad Request',
                    headers: (object)[]
                )
            );
        }

        // Validate redirect URL scheme (must be http or https)
        $parsedRedirectUrl = parse_url($redirectUrl);
        $scheme = $parsedRedirectUrl['scheme'] ?? '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return new ResultForReq(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'configuration_error',
                    detail: 'Redirect URL must use http or https scheme',
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 500,
                    body: '',
                    headers: (object)[]
                )
            );
        }

        // Normalize headers for case-insensitive access
        $normalizedHeaders = Headers::normalize($headers);

        // Determine request type
        $hasAuthHeader = isset($normalizedHeaders['authorization']);

        // Process redirect URL - strip restricted params if needed
        $processedRedirectUrl = self::processRedirectUrl($redirectUrl);

        // Determine response based on request type
        if ($hasAuthHeader) {
            // Fetch request - return 401 with reauthorize header
            return new ResultForReq(
                ok: true,
                shop: $shop,
                log: new LogWithReq(
                    code: 'app_home_parent_redirect_success',
                    detail: 'App Home Parent Redirect response constructed. Respond with the provided response to redirect outside the app iframe.',
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 401,
                    body: '',
                    headers: [
                        'X-Shopify-API-Request-Failure-Reauthorize-Url' => $processedRedirectUrl
                    ]
                )
            );
        }

        // JSON encode URL and target for safe embedding in JavaScript
        // JSON_HEX_TAG escapes < and > as \u003C and \u003E to prevent XSS
        $encodedUrl = self::jsonEncodeForJs($processedRedirectUrl);
        $encodedTarget = self::jsonEncodeForJs($target);

        // Document request - return HTML response with App Bridge
        $html = '<script data-api-key="' . $clientId . '" src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>';
        $html .= "<script>window.open({$encodedUrl}, {$encodedTarget});</script>";

        return new ResultForReq(
            ok: true,
            shop: $shop,
            log: new LogWithReq(
                code: 'app_home_parent_redirect_success',
                detail: 'App Home Parent Redirect response constructed. Respond with the provided response to redirect outside the app iframe.',
                req: Request::normalizeForLog($request)
            ),
            response: new ResponseInfo(
                status: 200,
                body: $html,
                headers: [
                    'Content-Type' => 'text/html',
                    'Link' => self::LINK_HEADER,
                    'Content-Security-Policy' => "frame-ancestors https://{$shopDomain} https://admin.shopify.com;"
                ]
            )
        );
    }

    /**
     * JSON encode a string for safe embedding in JavaScript.
     * Uses JSON_HEX_TAG to escape < and > as unicode escapes to prevent XSS.
     * Uses JSON_UNESCAPED_SLASHES to preserve forward slashes as-is.
     *
     * @param string $value The value to encode
     * @return string The JSON-encoded string (including surrounding quotes)
     */
    private static function jsonEncodeForJs(string $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Process redirect URL by stripping restricted params if needed.
     *
     * @param string $redirectUrl The original redirect URL
     * @return string The processed redirect URL
     */
    private static function processRedirectUrl(string $redirectUrl): string
    {
        $parsedUrl = parse_url($redirectUrl);
        $host = $parsedUrl['host'] ?? '';

        // Check if we need to strip restricted params
        $isAdminShopify = $host === 'admin.shopify.com';
        $isMyShopifyDomain = str_ends_with($host, '.myshopify.com');

        if (!$isAdminShopify && !$isMyShopifyDomain) {
            return $redirectUrl;
        }

        // Parse and filter query params
        $query = $parsedUrl['query'] ?? '';
        if (empty($query)) {
            return $redirectUrl;
        }

        parse_str($query, $queryParams);
        $filteredParams = [];
        foreach ($queryParams as $key => $value) {
            if (!in_array($key, self::RESTRICTED_PARAMS, true)) {
                $filteredParams[$key] = $value;
            }
        }

        // Rebuild URL
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $path = $parsedUrl['path'] ?? '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';

        $newUrl = "{$scheme}://{$host}{$path}";
        if (!empty($filteredParams)) {
            $newUrl .= '?' . http_build_query($filteredParams);
        }
        $newUrl .= $fragment;

        return $newUrl;
    }
}
