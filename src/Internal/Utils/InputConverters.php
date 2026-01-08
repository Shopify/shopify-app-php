<?php

declare(strict_types=1);

namespace Shopify\App\Internal\Utils;

use Shopify\App\Types\IdToken;
use Shopify\App\Types\TokenExchangeAccessToken;
use Shopify\App\Types\ClientCredentialsAccessToken;
use Shopify\App\Types\ResponseInfo;
use Shopify\App\Types\RequestInput;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp class instead.
 */
class InputConverters
{
    public static function toIdToken(IdToken|array|null $input): ?IdToken
    {
        if ($input === null) {
            return null;
        }
        if ($input instanceof IdToken) {
            return $input;
        }

        // Safely extract and convert types
        $exchangeable = $input['exchangeable'] ?? false;
        $token = $input['token'] ?? '';
        $claims = $input['claims'] ?? [];

        // Ensure types are correct
        if (!is_bool($exchangeable)) {
            $exchangeable = (bool)$exchangeable;
        }
        if (!is_string($token)) {
            $token = is_scalar($token) ? (string)$token : '';
        }
        if (!is_array($claims)) {
            $claims = [];
        }

        return new IdToken(
            exchangeable: $exchangeable,
            token: $token,
            claims: $claims
        );
    }

    public static function toTokenExchangeAccessToken(TokenExchangeAccessToken|array|null $input): ?TokenExchangeAccessToken
    {
        if ($input === null) {
            return null;
        }
        if ($input instanceof TokenExchangeAccessToken) {
            return $input;
        }

        return new TokenExchangeAccessToken(
            accessMode: $input['accessMode'],
            shop: $input['shop'],
            token: $input['token'],
            expires: $input['expires'] ?? null,
            scope: $input['scope'],
            refreshToken: $input['refreshToken'],
            refreshTokenExpires: $input['refreshTokenExpires'] ?? null,
            user: $input['user'] ?? null,
        );
    }

    public static function toClientCredentialsAccessToken(ClientCredentialsAccessToken|array|null $input): ?ClientCredentialsAccessToken
    {
        if ($input === null) {
            return null;
        }
        if ($input instanceof ClientCredentialsAccessToken) {
            return $input;
        }

        return new ClientCredentialsAccessToken(
            accessMode: $input['accessMode'],
            shop: $input['shop'],
            token: $input['token'],
            expires: $input['expires'] ?? null,
            scope: $input['scope'],
            user: null,
        );
    }

    public static function toResponseInfo(ResponseInfo|array|null $input): ?ResponseInfo
    {
        if ($input === null) {
            return null;
        }
        if ($input instanceof ResponseInfo) {
            return $input;
        }

        return new ResponseInfo(
            status: $input['status'] ?? 0,
            body: $input['body'] ?? '',
            headers: $input['headers'] ?? []
        );
    }

    public static function toRequestInput(RequestInput|array $input): RequestInput
    {
        if ($input instanceof RequestInput) {
            return $input;
        }

        return new RequestInput(
            method: $input['method'],
            headers: $input['headers'],
            url: $input['url'],
            body: $input['body']
        );
    }
}
