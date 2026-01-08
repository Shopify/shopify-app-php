<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * HTTP response information
 */
readonly class ResponseInfo
{
    public function __construct(
        public int $status,
        public string $body,
        public object|array $headers,
    ) {
    }
}
