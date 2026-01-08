<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Utils;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp class instead.
 */
class Headers
{
    /**
     * Normalize HTTP headers to lowercase for case-insensitive comparison.
     *
     * HTTP headers are case-insensitive per RFC 2616, but different frameworks
     * and clients may send them with different casing. This function normalizes
     * all header names to lowercase to ensure consistent access.
     *
     * @param array $headers Associative array of headers
     * @return array Associative array with lowercase header names
     */
    public static function normalize(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }
        return $normalized;
    }
}
