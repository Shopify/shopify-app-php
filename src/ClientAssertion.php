<?php

declare(strict_types=1);

namespace Shopify\App;

use Firebase\JWT\JWT;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Shopify\App\Internal\Utils\Shop;
use Throwable;

/**
 * Creates a signed client assertion for the `client_credentials` grant.
 *
 * This is a standalone helper, not a method on {@see ShopifyApp}: it needs no
 * app instance, no client secret, no ID token and no HTTP request. The app
 * calls it explicitly and then passes the returned string to
 * `exchangeUsingClientCredentials(clientAssertion: ...)`. The exchange never
 * calls this helper for you, and there is no app wide signing mode.
 *
 * The private key is supplied as an already loaded, native
 * {@see OpenSSLAsymmetricKey}. The app owns the key: this helper never reads,
 * imports, generates, persists, caches, rotates or registers key material, and
 * never returns it in a result, log or error message.
 */
final class ClientAssertion
{
    /** Assertion lifetime, in seconds, when the caller does not supply one. */
    private const DEFAULT_LIFETIME_SECONDS = 60;
    private const MIN_LIFETIME_SECONDS = 1;
    private const MAX_LIFETIME_SECONDS = 300;

    /** RSASSA-PKCS1-v1_5 with SHA-256 needs a key of at least this size. */
    private const MIN_RSA_BITS = 2048;

    /**
     * Names OpenSSL reports for the single supported EC curve (NIST P-256).
     */
    private const P256_CURVE_NAMES = ['prime256v1', 'secp256r1'];

    private function __construct()
    {
    }

    /**
     * Create one signed client assertion JWT.
     *
     * Every argument is accepted as `mixed` and validated here. Declared scalar
     * types would let a weakly typed caller coerce `123`, `true` or `"60"` into
     * an apparently valid value, which would hide the mistake instead of
     * reporting it.
     *
     * @param mixed $shop            Shop name (e.g. `shop-name`), not a domain or URL.
     * @param mixed $clientId        The app's client ID, used unchanged as `iss` and `sub`.
     * @param mixed $kid             The identifier registered for the public key.
     * @param mixed $privateKey      A native RSA (>= 2048 bit) or EC P-256 private key.
     * @param mixed $lifetimeSeconds Integer seconds from 1 to 300. Defaults to 60.
     *
     * @return string The compact JWT to pass to `exchangeUsingClientCredentials`.
     *
     * @throws InvalidArgumentException When an input or the key is unsupported.
     * @throws RuntimeException         When signing support is unavailable or signing fails.
     */
    public static function create(
        mixed $shop,
        mixed $clientId,
        mixed $kid,
        #[\SensitiveParameter] mixed $privateKey,
        mixed $lifetimeSeconds = self::DEFAULT_LIFETIME_SECONDS
    ): string {
        if (!Shop::isValidShopName($shop)) {
            throw new InvalidArgumentException(
                "Expected shop to be a valid shop domain (e.g., 'shop-name')"
            );
        }

        if (!self::isNonBlankString($clientId)) {
            throw new InvalidArgumentException('Expected clientId to be a non-empty string');
        }

        if (!self::isNonBlankString($kid)) {
            throw new InvalidArgumentException('Expected kid to be a non-empty string');
        }

        // is_int() rejects booleans, floats, numeric strings and null, so an
        // invalid lifetime is never clamped or replaced with the default.
        if (
            !is_int($lifetimeSeconds)
            || $lifetimeSeconds < self::MIN_LIFETIME_SECONDS
            || $lifetimeSeconds > self::MAX_LIFETIME_SECONDS
        ) {
            throw new InvalidArgumentException(sprintf(
                'Expected lifetimeSeconds to be an integer between %d and %d',
                self::MIN_LIFETIME_SECONDS,
                self::MAX_LIFETIME_SECONDS
            ));
        }

        if (!extension_loaded('openssl')) {
            throw new RuntimeException(
                'Client assertion signing requires the OpenSSL extension. Install or enable ext-openssl.'
            );
        }

        $algorithm = self::algorithmFor($privateKey);

        // One clock read, as integer Unix seconds, for both time claims.
        $issuedAt = time();

        $claims = [
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => sprintf(
                'https://%s.myshopify.com/admin/oauth/access_token',
                strtolower($shop)
            ),
            'iat' => $issuedAt,
            'exp' => $issuedAt + $lifetimeSeconds,
            // A fresh, unpredictable identifier for this assertion only.
            'jti' => bin2hex(random_bytes(16)),
        ];

        try {
            // JWT::encode writes the `typ`, `alg` and `kid` protected headers,
            // and emits the JOSE (R || S) signature form for ES256.
            return JWT::encode($claims, $privateKey, $algorithm, $kid);
        } catch (Throwable) {
            // Previous exceptions also appear when an exception is formatted for logging.
            throw new RuntimeException('Unable to sign the client assertion');
        }
    }

    /**
     * Select the JWT algorithm for a supported native private key.
     *
     * PHP's key objects expose no signing-usage or algorithm restrictions, so
     * key type, RSA size and EC curve are the available checks.
     *
     * @throws InvalidArgumentException When the key is not a supported private signing key.
     */
    private static function algorithmFor(#[\SensitiveParameter] mixed $privateKey): string
    {
        if (!$privateKey instanceof OpenSSLAsymmetricKey) {
            throw new InvalidArgumentException(
                'Expected privateKey to be a native OpenSSL private key object, '
                . 'such as the result of openssl_pkey_get_private()'
            );
        }

        $details = openssl_pkey_get_details($privateKey);
        if (!is_array($details)) {
            throw new InvalidArgumentException('Expected privateKey to be a readable private key');
        }

        $type = $details['type'] ?? null;

        if ($type === OPENSSL_KEYTYPE_RSA) {
            if (!isset($details['rsa']['d'])) {
                throw new InvalidArgumentException(
                    'Expected privateKey to be a private key, but got a public key'
                );
            }
            if (($details['bits'] ?? 0) < self::MIN_RSA_BITS) {
                throw new InvalidArgumentException(sprintf(
                    'Expected an RSA private key of at least %d bits',
                    self::MIN_RSA_BITS
                ));
            }
            return 'RS256';
        }

        if ($type === OPENSSL_KEYTYPE_EC) {
            if (!isset($details['ec']['d'])) {
                throw new InvalidArgumentException(
                    'Expected privateKey to be a private key, but got a public key'
                );
            }
            $curve = strtolower((string)($details['ec']['curve_name'] ?? ''));
            if (!in_array($curve, self::P256_CURVE_NAMES, true)) {
                throw new InvalidArgumentException(
                    'Expected an EC private key on the P-256 curve'
                );
            }
            return 'ES256';
        }

        throw new InvalidArgumentException(
            'Expected privateKey to be an RSA or EC P-256 private key'
        );
    }

    private static function isNonBlankString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
