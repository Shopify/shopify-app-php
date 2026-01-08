<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Access token information from token exchange
 */
readonly class AccessToken
{
    public function __construct(
        public string $accessMode,
        public string $shop,
        public string $token,
        public ?string $expires,
        public string $scope,
        public string $refreshToken,
        public ?string $refreshTokenExpires,
        public ?array $user,
    ) {
    }
}
