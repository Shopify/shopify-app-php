<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Helpers;

use Shopify\App\Internal\Utils\Request;
use Shopify\App\Types\AppHomePatchIdTokenResult;
use Shopify\App\Types\LogWithReq;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::appHomePatchIdToken() instead.
 */
class AppHomePatchIdToken
{
    /**
     * Renders the App Home Patch ID Token page HTML.
     *
     * Generates a lightweight HTML page that loads the App Bridge script to obtain
     * fresh session tokens for embedded apps.
     *
     * @param array $request Request object with method, headers, url, and body
     * @param array $config App configuration with clientId
     * @return AppHomePatchIdTokenResult Result with ok, shop, log, and response containing HTML and headers
     */
    public static function render(array $request, array $config): AppHomePatchIdTokenResult
    {
        $clientId = $config['clientId'] ?? '';

        // Check for missing client ID
        if (empty($clientId)) {
            return new AppHomePatchIdTokenResult(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'missing_client_id',
                    detail: 'Client ID is required but was not provided. Check configuration and respond 500 Internal Server Error using the provided response.'
                ),
                response: new ResponseInfo(
                    status: 500,
                    body: 'Internal Server Error',
                    headers: (object)[]
                )
            );
        }

        // Extract shop and shopify-reload from request query parameters
        $url = $request['url'] ?? '';

        if (empty($url)) {
            return new AppHomePatchIdTokenResult(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'missing_request_url',
                    detail: 'Request URL is required but was not provided.'
                ),
                response: new ResponseInfo(
                    status: 400,
                    body: 'Bad Request',
                    headers: (object)[]
                )
            );
        }

        $urlParts = parse_url($url);
        $query = $urlParts['query'] ?? '';
        parse_str($query, $queryParams);

        $shop = $queryParams['shop'] ?? '';
        $shopifyReload = $queryParams['shopify-reload'] ?? '';

        // Check for missing shop
        if (empty($shop)) {
            return new AppHomePatchIdTokenResult(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'missing_shop',
                    detail: 'Shop parameter is required in request URL query string but was not provided. Respond 400 Bad Request using the provided response.',
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 400,
                    body: 'Bad Request',
                    headers: (object)[]
                )
            );
        }

        // Check for missing shopify-reload
        if (empty($shopifyReload)) {
            return new AppHomePatchIdTokenResult(
                ok: false,
                shop: null,
                log: new LogWithReq(
                    code: 'missing_shopify_reload',
                    detail: 'shopify-reload parameter is required in request URL query string but was not provided. Respond 400 Bad Request using the provided response.',
                    req: Request::normalizeForLog($request)
                ),
                response: new ResponseInfo(
                    status: 400,
                    body: 'Bad Request',
                    headers: (object)[]
                )
            );
        }

        // Generate HTML with client ID from configuration
        $html = '<script data-api-key="' . $clientId . '" src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>';

        return new AppHomePatchIdTokenResult(
            ok: true,
            shop: $shop,
            log: new LogWithReq(
                code: 'patch_id_token_page_success',
                detail: 'App Home Patch ID Token page Response constructed. Respond with the provided response and App Bridge will obtain an id token.',
                req: Request::normalizeForLog($request)
            ),
            response: new ResponseInfo(
                status: 200,
                body: $html,
                headers: [
                    'Content-Type' => 'text/html',
                    'Link' => '<https://cdn.shopify.com/shopifycloud/app-bridge.js>; rel="preload"; as="script";',
                    'Content-Security-Policy' => "frame-ancestors https://{$shop} https://admin.shopify.com;"
                ]
            )
        );
    }
}
