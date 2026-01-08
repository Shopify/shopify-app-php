<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Decoded ID token information
 */
readonly class IdToken
{
    public function __construct(
        public bool $exchangeable,
        public string $token,
        public array $claims,
    ) {
    }
}
