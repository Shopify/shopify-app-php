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
    private const SENSITIVE_BODY_FIELDS = [
        'client_secret',
        'subject_token',
        'refresh_token',
        'access_token',
    ];
    /**
     * Fields an OAuth token endpoint returns that are reusable credentials. A
     * debug log is a lower-trust sink than the app runtime, so these must never
     * reach it.
     */
    private const SENSITIVE_RESPONSE_BODY_FIELDS = [
        'access_token',
        'refresh_token',
        'client_secret',
    ];
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
     * Redact issued OAuth credentials from a response body before logging it.
     *
     * A 200 from the OAuth token endpoint carries the access_token and
     * refresh_token that were just issued. Both are reusable credentials, so
     * logging the response verbatim would let anyone with log access replay
     * them. Every other field is preserved so the log stays useful for
     * debugging.
     *
     * The body is only re-encoded when something was actually redacted, so a
     * body that carries no credentials keeps its original formatting.
     *
     * @param string $body The raw response body
     * @return string The response body safe for logging
     */
    public static function redactResponseBodyForLog(string $body): string
    {
        $parsed = json_decode($body, true);

        if (!is_array($parsed)) {
            return $body;
        }

        $modified = false;
        foreach (self::SENSITIVE_RESPONSE_BODY_FIELDS as $field) {
            if (array_key_exists($field, $parsed)) {
                $parsed[$field] = self::REDACTED;
                $modified = true;
            }
        }

        if (!$modified) {
            return $body;
        }

        $encoded = json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Fail closed: never fall back to the unredacted body.
        return $encoded === false ? self::REDACTED : $encoded;
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
