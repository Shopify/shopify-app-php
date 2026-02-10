<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Verify;

use Shopify\App\Types\ResultForReq;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::verifyWebhookReq() instead.
 */
class Webhook
{
    /** Webhook and event header names (first present is used). */
    private const HMAC_HEADER_NAMES = ['x-shopify-hmac-sha256', 'shopify-hmac-sha256'];
    private const SHOP_HEADER_NAMES = ['x-shopify-shop-domain', 'shopify-shop-domain'];

    public static function verify(array $req, array $config): ResultForReq
    {
        return BodyHmacInHeader::verify(
            $req,
            $config,
            'Webhook',
            self::HMAC_HEADER_NAMES,
            self::SHOP_HEADER_NAMES
        );
    }
}
