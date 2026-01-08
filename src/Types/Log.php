<?php

declare(strict_types=1);

namespace Shopify\App\Types;

readonly class Log
{
    public function __construct(
        public string $code,
        public string $detail,
    ) {
    }
}
