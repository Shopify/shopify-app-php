<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Result from App Home Patch ID Token page render
 * Basic result without idToken field
 */
readonly class AppHomePatchIdTokenResult
{
    public function __construct(
        public bool $ok,
        public ?string $shop,
        public LogWithReq $log,
        public ResponseInfo $response,
    ) {
    }
}
