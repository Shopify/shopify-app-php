<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Utils;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp class instead.
 */
class Request
{
    /**
     * Normalize request object for logging to ensure proper JSON serialization.
     *
     * Converts empty arrays to objects to match expected JSON format.
     * This is necessary because PHP's json_encode() converts empty arrays to []
     * instead of {}, which can cause inconsistency in log output.
     *
     * @param array $req Request object with method, headers, url, body
     * @return array Normalized request object safe for JSON serialization
     */
    public static function normalizeForLog(array $req): array
    {
        $normalized = $req;
        // Ensure headers is an object, not an array, when empty
        if (isset($normalized['headers']) && empty($normalized['headers'])) {
            $normalized['headers'] = (object)[];
        }
        return $normalized;
    }
}
