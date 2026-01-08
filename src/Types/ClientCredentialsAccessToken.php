<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Access token from client credentials exchange
 * Does NOT include refreshToken fields
 */
readonly class ClientCredentialsAccessToken
{
    public function __construct(
        public string $accessMode,  // Always 'offline'
        public string $shop,
        public string $token,
        public ?string $expires,
        public string $scope,
        public ?array $user,  // Always null for client credentials
    ) {
    }
}
