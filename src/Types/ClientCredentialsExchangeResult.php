<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Result from client credentials exchange
 * Used by: exchangeUsingClientCredentials
 */
readonly class ClientCredentialsExchangeResult
{
    public function __construct(
        public bool $ok,
        public ?string $shop,
        public ?ClientCredentialsAccessToken $accessToken,
        public Log $log,
        public array $httpLogs,
        public ResponseInfo $response,
    ) {
    }
}
