<?php

declare(strict_types=1);

namespace Shopify\App\Types;

readonly class RequestInput
{
    public function __construct(
        public string $method,
        public array $headers,
        public string $url,
        public string $body,
    ) {
    }
}
