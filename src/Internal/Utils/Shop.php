<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Utils;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp class instead.
 */
class Shop
{
    private const MYSHOPIFY_SUFFIX = '.myshopify.com';

    /**
     * Check that a value is a valid shop name, e.g. "shop-name".
     *
     * The D modifier makes $ match only at the end of the value, not before a final newline.
     */
    public static function isValidShopName(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*$/D', $value) === 1;
    }

    /**
     * Check that a value is a valid shop domain, e.g. "shop-name.myshopify.com".
     */
    public static function isValidShopDomain(mixed $value): bool
    {
        return is_string($value)
            && str_ends_with($value, self::MYSHOPIFY_SUFFIX)
            && self::isValidShopName(substr($value, 0, -strlen(self::MYSHOPIFY_SUFFIX)));
    }
}
