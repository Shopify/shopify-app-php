<?php

declare(strict_types=1);

namespace Shopify\App\Types;

readonly class AppConfig
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public ?string $oldClientSecret = null,
    ) {
    }
}
