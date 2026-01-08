<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Result with exchangeable ID token
 * Used by: verifyAdminUIExtReq, verifyPosUIExtReq, verifyAppHomeReq
 */
readonly class ResultWithExchangeableIdToken
{
    public function __construct(
        public bool $ok,
        public ?string $shop,
        public ?IdToken $idToken,
        public ?string $userId,
        public ?array $newIdTokenResponse,
        public LogWithReq $log,
        public ResponseInfo $response,
    ) {
    }
}
