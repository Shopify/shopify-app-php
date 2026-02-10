<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Utils;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp class instead.
 */
class Encoding
{
    /**
     * JSON encode a string for safe embedding in JavaScript.
     * Uses JSON_HEX_TAG to escape < and > as unicode escapes to prevent XSS.
     * Uses JSON_UNESCAPED_SLASHES to preserve forward slashes as-is.
     *
     * @param string $value The value to encode
     * @return string The JSON-encoded string (including surrounding quotes)
     */
    public static function jsonEncodeForJs(string $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
    }
}
