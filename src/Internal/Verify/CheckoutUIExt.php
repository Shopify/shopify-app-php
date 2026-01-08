<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Verify;

use Shopify\App\Types\ResultWithNonExchangeableIdToken;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::verifyCheckoutUIExtReq() instead.
 */
class CheckoutUIExt
{
    public static function verify(array $req, array $config): ResultWithNonExchangeableIdToken
    {
        return NonExchangeableIdToken::verify($req, $config, 'Checkout UI Extension');
    }
}
