<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Utils;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp class instead.
 */
class Request
{
    private const REDACTED = '[REDACTED]';
    private const SENSITIVE_BODY_FIELDS = ['client_secret', 'subject_token', 'refresh_token'];
    private const SENSITIVE_HEADER_FIELDS = [
        'x-shopify-access-token',
        'authorization',
        'x-shopify-hmac-sha256',
        'shopify-hmac-sha256',
    ];
    private const SENSITIVE_URL_PARAMS = ['signature', 'id_token', 'hmac'];

    /**
     * Redact sensitive values from a request object for logging.
     *
     * Redacts sensitive headers, URL parameters, and body fields, then converts
     * empty header arrays to objects to match expected JSON format (PHP's
     * json_encode() converts empty arrays to [] instead of {}).
     *
     * @param array $req Request object with method, headers, url, body
     * @return array Redacted request object safe for logging
     */
    public static function redactForLog(array $req): array
    {
        $result = $req;

        // Redact sensitive headers (case-insensitive key match)
        if (isset($result['headers']) && is_array($result['headers'])) {
            foreach ($result['headers'] as $key => $value) {
                if (in_array(strtolower($key), self::SENSITIVE_HEADER_FIELDS)) {
                    $result['headers'][$key] = self::REDACTED;
                }
            }
            // Ensure headers is an object, not an array, when empty
            if (empty($result['headers'])) {
                $result['headers'] = (object)[];
            }
        }

        // Redact sensitive URL query parameters
        if (isset($result['url']) && is_string($result['url'])) {
            $result['url'] = self::sanitizeUrl($result['url']);
        }

        // Redact sensitive fields in JSON body
        if (isset($result['body']) && is_string($result['body'])) {
            $body = json_decode($result['body'], true);
            if (is_array($body)) {
                foreach (self::SENSITIVE_BODY_FIELDS as $field) {
                    if (array_key_exists($field, $body)) {
                        $body[$field] = self::REDACTED;
                    }
                }
                $result['body'] = json_encode($body);
            }
        }

        return $result;
    }

    /**
     * Redact sensitive query parameter values in a URL string.
     *
     * @param string $url The URL to sanitize
     * @return string The URL with sensitive query parameter values replaced by [REDACTED]
     */
    private static function sanitizeUrl(string $url): string
    {
        foreach (self::SENSITIVE_URL_PARAMS as $param) {
            $url = preg_replace(
                '/([?&]' . preg_quote($param, '/') . '=)[^&]*/i',
                '${1}' . self::REDACTED,
                $url
            ) ?? $url;
        }
        return $url;
    }
}
