<?php

declare(strict_types=1);

namespace Shopify\App;

use Shopify\App\Internal\Verify\Webhook;
use Shopify\App\Internal\Verify\FlowAction;
use Shopify\App\Internal\Verify\CheckoutUIExt;
use Shopify\App\Internal\Verify\PosUIExt;
use Shopify\App\Internal\Verify\CustomerAccountUIExt;
use Shopify\App\Internal\Verify\AdminUIExt;
use Shopify\App\Internal\Verify\AppHomeReq;
use Shopify\App\Internal\Verify\AppProxy;
use Shopify\App\Internal\Helpers\AppHomePatchIdToken;
use Shopify\App\Internal\Helpers\AppHomeParentRedirect;
use Shopify\App\Internal\Helpers\AppHomeRedirect;
use Shopify\App\Internal\Exchange\TokenExchange;
use Shopify\App\Internal\Exchange\RefreshToken;
use Shopify\App\Internal\Exchange\ClientCredentials;
use Shopify\App\Internal\GraphQL\AdminGraphQL;
use Shopify\App\Types\ResultForReq;
use Shopify\App\Types\ResultWithNonExchangeableIdToken;
use Shopify\App\Types\ResultWithExchangeableIdToken;
use Shopify\App\Types\ResultWithLoggedInCustomerId;
use Shopify\App\Types\AppHomePatchIdTokenResult;
use Shopify\App\Types\TokenExchangeResult;
use Shopify\App\Types\ClientCredentialsExchangeResult;
use Shopify\App\Types\GQLResult;
use Shopify\App\Types\IdToken;
use Shopify\App\Types\TokenExchangeAccessToken;
use Shopify\App\Types\AppConfig;

class ShopifyApp
{
    private array $config;

    public function __construct(
        string|AppConfig $clientId,
        ?string $clientSecret = null,
        ?string $oldClientSecret = null
    ) {
        if ($clientId instanceof AppConfig) {
            $actualClientId = $clientId->clientId;
            $actualClientSecret = $clientId->clientSecret;
            $actualOldClientSecret = $clientId->oldClientSecret;
        } else {
            $actualClientId = $clientId;
            $actualClientSecret = $clientSecret;
            $actualOldClientSecret = $oldClientSecret;
        }

        if (empty($actualClientId)) {
            throw new \InvalidArgumentException(
                'clientId is required in ShopifyApp configuration'
            );
        }

        if (empty($actualClientSecret)) {
            throw new \InvalidArgumentException(
                'clientSecret is required in ShopifyApp configuration'
            );
        }

        $this->config = [
            'clientId' => $actualClientId,
            'clientSecret' => $actualClientSecret,
            'oldClientSecret' => $actualOldClientSecret
        ];
    }

    public function verifyWebhookReq(array $req): ResultForReq
    {
        return Webhook::verify($req, $this->config);
    }

    public function verifyFlowActionReq(array $req): ResultForReq
    {
        return FlowAction::verify($req, $this->config);
    }

    public function verifyCheckoutUIExtReq(array $req): ResultWithNonExchangeableIdToken
    {
        return CheckoutUIExt::verify($req, $this->config);
    }

    public function verifyPosUIExtReq(array $req): ResultWithExchangeableIdToken
    {
        return PosUIExt::verify($req, $this->config);
    }

    public function verifyCustomerAccountUIExtReq(array $req): ResultWithNonExchangeableIdToken
    {
        return CustomerAccountUIExt::verify($req, $this->config);
    }

    public function verifyAdminUIExtReq(array $req): ResultWithExchangeableIdToken
    {
        return AdminUIExt::verify($req, $this->config);
    }

    public function verifyAppHomeReq(array $req, mixed $appHomePatchIdTokenPath = ''): ResultWithExchangeableIdToken
    {
        return AppHomeReq::verify($req, $this->config, $appHomePatchIdTokenPath);
    }

    public function verifyAppProxyReq(array $req): ResultWithLoggedInCustomerId
    {
        return AppProxy::verify($req, $this->config);
    }

    public function exchangeUsingTokenExchange(
        string $accessMode,
        IdToken|array|null $idToken,
        ?array $invalidTokenResponse = null,
        $httpClient = null
    ): TokenExchangeResult {
        return TokenExchange::exchange(
            accessMode: $accessMode,
            idToken: $idToken,
            invalidTokenResponse: $invalidTokenResponse,
            httpClient: $httpClient,
            appConfig: $this->config
        );
    }

    public function refreshTokenExchangedAccessToken(TokenExchangeAccessToken|array $accessToken, $httpClient = null): TokenExchangeResult
    {
        return RefreshToken::refresh($accessToken, $this->config, $httpClient);
    }

    public function exchangeUsingClientCredentials(
        string $shop,
        $httpClient = null
    ): ClientCredentialsExchangeResult {
        return ClientCredentials::exchange(
            shop: $shop,
            httpClient: $httpClient,
            appConfig: $this->config
        );
    }

    public function appHomePatchIdToken(array $request): AppHomePatchIdTokenResult
    {
        return AppHomePatchIdToken::render($request, $this->config);
    }

    public function appHomeParentRedirect(
        array $request,
        string $redirectUrl,
        string $shop,
        ?string $target = null
    ): ResultForReq {
        return AppHomeParentRedirect::redirect($request, $this->config, $redirectUrl, $shop, $target);
    }

    public function appHomeRedirect(
        array $request,
        string $redirectUrl,
        string $shop
    ): ResultForReq {
        return AppHomeRedirect::redirect($request, $this->config, $redirectUrl, $shop);
    }

    public function adminGraphQLRequest(
        string $query,
        string $shop,
        string $accessToken,
        string $apiVersion,
        ?array $invalidTokenResponse = null,
        ?array $variables = null,
        ?array $headers = null,
        int $maxRetries = 2,
        $httpClient = null
    ): GQLResult {
        return AdminGraphQL::request(
            $query,
            shop: $shop,
            accessToken: $accessToken,
            apiVersion: $apiVersion,
            invalidTokenResponse: $invalidTokenResponse,
            variables: $variables,
            headers: $headers,
            maxRetries: $maxRetries,
            appConfig: $this->config,
            httpClient: $httpClient,
        );
    }
}
