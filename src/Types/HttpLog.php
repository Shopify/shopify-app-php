<?php

declare(strict_types=1);

namespace Shopify\App\Types;

readonly class HttpLog
{
    public function __construct(
        public string $code,
        public string $detail,
        public array $req,
        public ResponseInfo $res,
    ) {
    }
}
