<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Verify;

use Shopify\App\Types\ResultForReq;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::verifyFlowActionReq() instead.
 */
class FlowAction
{
    /** Flow actions use only webhook header names. */
    private const HMAC_HEADER_NAMES = ['x-shopify-hmac-sha256'];
    private const SHOP_HEADER_NAMES = ['x-shopify-shop-domain'];

    public static function verify(array $req, array $config): ResultForReq
    {
        return BodyHmacInHeader::verify(
            $req,
            $config,
            'Flow action',
            self::HMAC_HEADER_NAMES,
            self::SHOP_HEADER_NAMES
        );
    }
}
