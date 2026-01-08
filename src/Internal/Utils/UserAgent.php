<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Utils;

use const Shopify\App\VERSION;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp class instead.
 */
class UserAgent
{
    private const PACKAGE_NAME = 'shopify-app-php';

    /**
     * Get the User-Agent string for HTTP requests.
     *
     * Format: "{package-name} v{version} | PHP {php_version}"
     * Example: "shopify-app-php v1.0.0 | PHP 8.2.0"
     *
     * @return string
     */
    public static function get(): string
    {
        return sprintf(
            '%s v%s | PHP %s',
            self::PACKAGE_NAME,
            VERSION,
            PHP_VERSION
        );
    }
}
