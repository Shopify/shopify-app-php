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
    public static function verify(array $req, array $config): ResultForReq
    {
        return BodyHmacInHeader::verify($req, $config, 'Flow action');
    }
}
