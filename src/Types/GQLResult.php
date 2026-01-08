<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Result from GraphQL API requests
 * Used by: adminGraphQLRequest
 */
readonly class GQLResult
{
    public function __construct(
        public bool $ok,
        public ?string $shop,
        public ?array $data,
        public ?array $extensions,
        public Log $log,
        public array $httpLogs,
        public ResponseInfo $response,
    ) {
    }
}
