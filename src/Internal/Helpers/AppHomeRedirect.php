<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Helpers;

use Shopify\App\Internal\Utils\Headers;
use Shopify\App\Internal\Utils\Encoding;
use Shopify\App\Internal\Utils\Request;
use Shopify\App\Types\ResultForReq;
use Shopify\App\Types\LogWithReq;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::appHomeRedirect() instead.
 */
class AppHomeRedirect
{
    private const LINK_HEADER = '<https://cdn.shopify.com>; rel="preconnect", <https://cdn.shopify.com/shopifycloud/app-bridge.js>; rel="preload"; as="script", <https://cdn.shopify.com/shopifycloud/polaris.js>; rel="preload"; as="script"';

    /**
     * Generate a redirect response that stays within the app home iFrame.
     *
     * @param array $request Request object with method, headers, url, and body
     * @param array $config App configuration with clientId
     * @param string $redirectUrl The relative URL to redirect to (must start with '/')
     * @param string $shop The shop domain (e.g., "test-shop")
     * @return ResultForReq Result with ok, shop, log, and response
     */
    public static function redirect(
        array $request,
        array $config,
        string $redirectUrl,
        string $shop
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

        // Validate redirect URL is a relative path starting with /
        if (!self::isValidRelativeUrl($redirectUrl)) {
            return new ResultForReq(
                ok: false,
                shop: $shop,
                log: new LogWithReq(
                    code: 'invalid_redirect_url',
                    detail: "Redirect URL must be a relative path starting with '/'. Received {$redirectUrl}. Respond 400 Bad Request using the provided response.",
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 400,
                    body: 'Bad Request',
                    headers: (object)[]
                )
            );
        }

        // Normalize headers for case-insensitive access
        $normalizedHeaders = Headers::normalize($headers);

        // Determine request type
        $hasAuthHeader = isset($normalizedHeaders['authorization']);
        $hasBounceHeader = isset($normalizedHeaders['x-shopify-bounce']);

        // Build redirect URL with merged params
        $mergedUrl = self::mergeUrlParams($url, $redirectUrl);

        // Determine response based on request type
        if ($hasAuthHeader && $hasBounceHeader) {
            // Bounce request - return HTML response with App Bridge using _self
            // JSON encode URL for safe embedding in JavaScript to prevent XSS
            $encodedUrl = Encoding::jsonEncodeForJs($mergedUrl);
            $html = '<script data-api-key="' . $clientId . '" src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>';
            $html .= "<script>window.open({$encodedUrl}, \"_self\");</script>";

            return new ResultForReq(
                ok: true,
                shop: $shop,
                log: new LogWithReq(
                    code: 'app_home_redirect_success',
                    detail: 'App Home Redirect response constructed. Respond with the provided response to redirect within the app.',
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

        if ($hasAuthHeader) {
            // Fetch request - return plain 302 redirect
            return new ResultForReq(
                ok: true,
                shop: $shop,
                log: new LogWithReq(
                    code: 'app_home_redirect_success',
                    detail: 'App Home Redirect response constructed. Respond with the provided response to redirect within the app.',
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 302,
                    body: '',
                    headers: [
                        'Location' => $mergedUrl
                    ]
                )
            );
        }

        // Document request - return 302 redirect with CSP and Link headers
        return new ResultForReq(
            ok: true,
            shop: $shop,
            log: new LogWithReq(
                code: 'app_home_redirect_success',
                detail: 'App Home Redirect response constructed. Respond with the provided response to redirect within the app.',
                req: Request::normalizeForLog($request)
            ),
            response: new ResponseInfo(
                status: 302,
                body: '',
                headers: [
                    'Location' => $mergedUrl,
                    'Link' => self::LINK_HEADER,
                    'Content-Security-Policy' => "frame-ancestors https://{$shopDomain} https://admin.shopify.com;"
                ]
            )
        );
    }

    /**
     * Check if the redirect URL is a valid relative path starting with '/'.
     *
     * @param string $redirectUrl The redirect URL to validate
     * @return bool True if valid relative URL, False otherwise
     */
    private static function isValidRelativeUrl(string $redirectUrl): bool
    {
        // Must be non-empty and start with /
        if (empty($redirectUrl) || $redirectUrl[0] !== '/') {
            return false;
        }

        // Must not be protocol-relative (//evil.com)
        if (str_starts_with($redirectUrl, '//')) {
            return false;
        }

        return true;
    }

    /**
     * Merge URL params from request URL into redirect URL.
     * New params in redirect URL take precedence over existing ones.
     *
     * @param string $requestUrl The original request URL with params to copy
     * @param string $redirectUrl The redirect URL (may have its own params)
     * @return string The redirect URL with merged params
     */
    private static function mergeUrlParams(string $requestUrl, string $redirectUrl): string
    {
        // Parse request URL to get existing params
        $parsedRequest = parse_url($requestUrl);
        $requestQuery = $parsedRequest['query'] ?? '';
        $requestParams = [];
        if (!empty($requestQuery)) {
            parse_str($requestQuery, $requestParams);
        }

        // Parse redirect URL
        $parsedRedirect = parse_url($redirectUrl);
        $redirectPath = $parsedRedirect['path'] ?? '';
        $redirectQuery = $parsedRedirect['query'] ?? '';
        $redirectFragment = $parsedRedirect['fragment'] ?? '';
        $redirectParams = [];
        if (!empty($redirectQuery)) {
            parse_str($redirectQuery, $redirectParams);
        }

        // Merge params - redirect params take precedence (they overwrite request params)
        // Start with redirect params, then add request params that aren't already there
        $mergedParams = $redirectParams;
        foreach ($requestParams as $key => $value) {
            if (!array_key_exists($key, $mergedParams)) {
                $mergedParams[$key] = $value;
            }
        }

        // Build the merged URL
        $result = $redirectPath;
        if (!empty($mergedParams)) {
            $queryString = http_build_query($mergedParams);
            $result = "{$redirectPath}?{$queryString}";
        }

        // Append fragment if present
        if (!empty($redirectFragment)) {
            $result .= "#{$redirectFragment}";
        }

        return $result;
    }
}
