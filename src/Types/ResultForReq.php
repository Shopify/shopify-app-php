<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Result for webhook and flow action verification (minimal result without token information)
 * Used by: verifyWebhookReq, verifyFlowActionReq
 */
readonly class ResultForReq
{
    public function __construct(
        public bool $ok,
        public ?string $shop,
        public LogWithReq $log,
        public ResponseInfo $response,
    ) {
    }
}
