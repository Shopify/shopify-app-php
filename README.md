# Shopify App PHP Package

PHP package for building Shopify applications.

## Installation

```bash
composer require shopify/shopify-app-php
```

## Requirements

- PHP 7.4 or higher
- firebase/php-jwt for JWT token handling
- guzzlehttp/guzzle for HTTP requests

## Features

Request Verification:

- `verifyAdminUIExtReq`: Requests from Admin UI extensions
- `verifyAppHomeReq`: Requests for embedded app home that use App Bridge
- `verifyAppProxyReq`: Requests from storefronts via App Proxy
- `verifyCheckoutUIExtReq`: Requests from checkout UI extensions
- `verifyCustomerAccountUIExtReq`: Requests from Customer account UI extensions
- `verifyFlowActionReq`: Requests from Flow action extensions (coming soon)
- `verifyPosUIExtReq`: Requests from POS UI extensions
- `verifyWebhookReq`: Webhook requests

Exchange:

- `exchangeUsingTokenExchange`: Use Token Exchange to exchange an ID token for an access token
- `exchangeUsingClientCredentials`: Get access tokens via client credentials
- `refreshTokenExchangedAccessToken`: Refresh an access token that was created using Token Exchange

GraphQL:

- `adminGraphQLRequest`: Make Admin API GraphQL requests with automatic retry handling

Helpers:

- `appHomePatchIdToken`: Render the patch ID token page for embedded apps
- `appHomeRedirect`: Handle redirects within embedded app home request (coming soon)

## Principles

1. **Built-in best practices:** This package encodes best practices for building Shopify apps as primitives. Use them correctly and you'll build secure, performant apps on the green-path. This means some features are not supported (e.g: REST since [Shopify is all-in on GraphQL](https://www.shopify.com/partners/blog/all-in-on-graphql)).
2. **What most apps need most of the time:** This package does not intend to focus on some less common features of the Shopify app platform (e.g: Non Embedded apps).
3. **Framework agnostic:** Whether you're using Laravel, Symfony, CodeIgniter, or raw PHP, these packages won't force architectural decisions on you. We provide primitives. You compose them however you wish. We've prototyped extensively to make sure that composition can lead to idiomatic patterns.
4. **Language agnostic:** Whilst this is a PHP package, its API is shared with a Python package. This creates some interesting constraints, and sacrifices some idioms. But... the big benefit is that fixes in one community will benefit the other. As the Python package evolves, so will the PHP package (and vice-versa).

## Setup steps

This section will focus on steps that are universal to any web framework. We'll provide examples for Laravel, Symfony and CodeIgniter. But these examples are fairly universal and can be translated to other approaches.

### Install the Shopify CLI

This installs Shopify CLI globally on your system, so you can run shopify commands from any directory.

```
npm install -g @shopify/cli@latest
```

Please see [this guide](https://shopify.dev/docs/api/shopify-cli#installation) for using other JavaScript package managers

### Initialize your web framework

- [Laravel quickstart](https://laravel.com/docs/installation)
- [Symfony quickstart](https://symfony.com/doc/current/setup.html)
- [CodeIgniter quickstart](https://codeigniter.com/user_guide/installation/index.html)

### Setup the Shopify CLI

Inside the directory where you initialized your framework create a `shopify.app.toml` (This will be overwritten when you run `shopify app init --reset`):

```toml
client_id = ""
name = ""
application_url = ""
embedded = true

[access_scopes]
scopes = "write_products"

[webhooks]
api_version = "2025-01"
```

Make sure there is at-least a minimal `package.json`:

```json
{
  "name": "my-php-app"
}
```

Create a `shopify.web.toml`:

```toml
name = "My PHP App"
roles = ["frontend", "backend"]
webhooks_path = "/webhooks/app/uninstalled"

[commands]
dev = "[COMMAND]"
```

Replace `[COMMAND]` with the command to run your app in development mode. For example:

- Laravel: `php artisan serve`
- Symfony: `symfony server:start --port=${PORT:-8000} --allow-http`
- CodeIgniter: `php spark serve --port=${PORT:-8080}`

Note: The Shopify CLI provides `PORT` and `SERVER_PORT` environment variables. Laravel automatically uses the `SERVER_PORT` environment variable.

### Run your app

With these setup steps complete you should be able to run

```bash
shopify app dev --reset
```

Only use the `--reset` flag the first time.

## Using the package

### Initialization

`SHOPIFY_API_KEY` and `SHOPIFY_API_SECRET` are provided by the Shopify CLI.

```php
<?php

use Shopify\App\ShopifyApp;

$shopify = new ShopifyApp(
    clientId: getenv('SHOPIFY_API_KEY'),
    clientSecret: getenv('SHOPIFY_API_SECRET'),
);
```

For secret rotation, `oldClientSecret` is an optional parameter. Since the CLI does not provide this env var, you will need to provide it manually. Read more about [secret rotation](https://shopify.dev/docs/apps/build/authentication-authorization/client-secrets/rotate-revoke-client-credentials).

### Converting a Request

So that the package can support multiple frameworks, your app must convert your frameworks concept of a Request to the package's concept.

Laravel Example:

```php
// Laravel passes the request to controllers and middleware
function requestToShopifyReq($request)
{
    return [
        'method' => $request->method(),
        'headers' => $request->headers->all(),
        'url' => $request->fullUrl(),
        'body' => $request->getContent(),
    ];
}
```

Symfony Example:

```php
use Symfony\Component\HttpFoundation\Request;

function requestToShopifyReq(Request $request)
{
    return [
        'method' => $request->getMethod(),
        'headers' => $request->headers->all(),
        'url' => $request->getUri(),
        'body' => $request->getContent(),
    ];
}
```

CodeIgniter Example:

```php
function requestToShopifyReq($request)
{
    return [
        'method' => $request->getMethod(),
        'headers' => $request->headers(),
        'url' => current_url() . '?' . http_build_query($_GET),
        'body' => $request->getBody(),
    ];
}
```

### Converting a Shopify response

Your app must convert the packages concept of a Response to the frameworks concept. The Result provided by the package's function also includes a `log` property with these properties:

- `code`: A short string describing the situation
- `detail`: Copy describing the state of the request and what you should do next.
- `req`: The Req that was passed to the function.

We recommend logging this information to help you debug.

Laravel example:

```php
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

function shopifyResultToResponse($result)
{
    $log = $result['log'];
    Log::info("{$log['code']} - {$log['detail']}");

    $resp = $result['response'];
    return response($resp['body'], $resp['status'])
        ->withHeaders($resp['headers']);
}
```

Symfony example:

```php
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

function shopifyResultToResponse($result, LoggerInterface $logger)
{
    $log = $result['log'];
    $logger->info("{$log['code']} - {$log['detail']}");

    $resp = $result['response'];
    return new Response(
        $resp['body'],
        $resp['status'],
        $resp['headers']
    );
}
```

CodeIgniter Example:

```php
use CodeIgniter\HTTP\Response;

function shopifyResultToResponse($result)
{
    $log = $result['log'];
    log_message('info', "{$log['code']} - {$log['detail']}");

    $resp = $result['response'];
    return service('response')
        ->setStatusCode($resp['status'])
        ->setBody($resp['body'])
        ->setHeaders($resp['headers']);
}
```

### Verifying request result

Verifying a request returns a `Result`. A `Result` is similar across all verify functions, with some differences.

Common properties (all verify functions):

| Property   | Description                                                                                            | Nullable |
| ---------- | ------------------------------------------------------------------------------------------------------ | -------- |
| `ok`       | Boolean indicating if the request passed verification. Respond with the Response if `false`            | No       |
| `shop`     | The shop sub domain (e.g: `test-shop`, for `test-shop.myshopify.com`). `null` when verification fails. | Yes      |
| `log`      | Object containing `code`, `detail`, and `req` for debugging and monitoring.                            | No       |
| `response` | Pre-built HTTP response with `status`, `body`, and `headers`. Return this when `ok` is `false`.        | No       |

Properties for Exchangeable ID Token Requests (`verifyAppHomeReq`, `verifyAdminUIExtReq`, `verifyPosUIExtReq`):

| Property             | Description                                                                         | Nullable |
| -------------------- | ----------------------------------------------------------------------------------- | -------- |
| `userId`             | The merchant user ID. `null` if `ok` is `false`.                                    | Yes      |
| `idToken`            | Object containing `exchangeable` (Boolean), `token` (string), and `claims` (array). | Yes      |
| `newIdTokenResponse` | Pre-built response for invalid token retry flow.                                    | Yes      |

Properties for App Proxy Requests (`verifyAppProxyReq`):

| Property             | Description                                                                                           | Nullable |
| -------------------- | ----------------------------------------------------------------------------------------------------- | -------- |
| `loggedInCustomerId` | The customer ID if logged in. `null` if not logged in. This is a customer ID, not a merchant user ID. | Yes      |

### Verifying Requests with exchangeable ID Tokens

Some requests provide exchangeable ID tokens:

1. App home
2. Admin UI Extensions
3. POS UI Extensions

ID tokens from these requests can be exchanged for access tokens, which can be used to access the Admin GraphQL API. These verification methods provide a user id (merchant id) so you can look up an online access token in your database.

#### App Home

First we verify the request:

```php
use Shopify\App\ShopifyApp;

function appHome($request)
{
    $shopify = getShopifyApp(); // Your method to get the ShopifyApp instance
    $req = requestToShopifyReq($request);

    $result = $shopify->verifyAppHomeReq(
        $req,
        appHomePatchIdTokenPath: '/auth/patch-id-token',
    );

    // The request should not be trusted
    if (!$result['ok']) {
        return shopifyResultToResponse($result);
    }
```

Then we check if there is an access token in the database. If there is one we check if it needs to be refreshed.

```php
    // Your database logic here
    $accessToken = getAccessToken($result['shop'], 'offline');

    if ($accessToken) {
        $refreshResult = $shopify->refreshTokenExchangedAccessToken($accessToken);

        if (!$refreshResult['ok']) {
            return shopifyResultToResponse($refreshResult);
        }

        if (isset($refreshResult['accessToken'])) {
            // Package returned a refreshed token — save it
            saveAccessToken($refreshResult['accessToken']);
        }
    }
```

You will need to write the database code to get and save access tokens. The package always returns and accepts access tokens in the same shape:

| Key                   | Type          | Description                                         |
| --------------------- | ------------- | --------------------------------------------------- |
| `shop`                | string        | Shop domain (e.g., "test-shop.myshopify.com")       |
| `accessMode`          | string        | Access mode: "online" or "offline"                  |
| `token`               | string        | The access token                                    |
| `scope`               | string        | Granted scopes                                      |
| `refreshToken`        | string        | Token used to refresh the access token              |
| `expires`             | string        | ISO 8601 datetime when access token expires         |
| `refreshTokenExpires` | string        | ISO 8601 datetime when refresh token expires        |
| `userId`              | string        | A unique identifier for the user                    |
| `user`                | array or null | User details (online mode only, `null` for offline) |

When `accessMode` is "online", the `user` array contains:

| Key             | Type   | Description                                      |
| --------------- | ------ | ------------------------------------------------ |
| `id`            | int    | A unique identifier for the user                 |
| `firstName`     | string | User's first name                                |
| `lastName`      | string | User's last name                                 |
| `email`         | string | User's email address                             |
| `emailVerified` | bool   | Whether the email is verified                    |
| `accountOwner`  | bool   | Whether the user is the account owner            |
| `locale`        | string | User's locale (e.g., "en")                       |
| `collaborator`  | bool   | Whether the user is a collaborator               |
| `scope`         | string | User-specific scopes (may differ from app scope) |

If there is no access token in the database, use token exchange to get one:

```php
    if (!$accessToken) {
        $exchangeResult = $shopify->exchangeUsingTokenExchange(
            accessMode: 'offline',
            idToken: $result['idToken'],
            invalidTokenResponse: $result['newIdTokenResponse'],
        );

        if (!$exchangeResult['ok']) {
            return shopifyResultToResponse($exchangeResult);
        }

        // Save the new token
        saveAccessToken($exchangeResult['accessToken']);
    }
```

Note:

- `exchangeUsingTokenExchange` receives `$result["newIdTokenResponse"]` from the verify function. This allows Shopify to automatically retry this request if the id token has become stale.
- If using online access tokens, use the `userId` provided by the `result`.
- If your app has need to access the admin API outside of requests from App Home, Admin UI Extensions or POS UI Extensions you should also exchange and save an offline token.

App home requests require [special Response headers](https://shopify.dev/docs/apps/build/security/set-up-iframe-protection). The `result` provides a response that contains these headers. Copy them to your response:

```php
// Copy headers from result to your response
$headers = $result['response']['headers'] ?? [];
foreach ($headers as $header => $value) {
    $response->headers->set($header, $value);
}
```

App requests should also contain [App Bridge](https://shopify.dev/docs/api/app-bridge) and [Polaris Web Components](https://shopify.dev/docs/api/app-home/using-polaris-components) script tags so they remain secure and can look like Shopify:

```html
<script
  src="https://cdn.shopify.com/shopifycloud/app-bridge.js"
  data-api-key="{{ $clientId }}"
></script>
<script src="https://cdn.shopify.com/shopifycloud/polaris.js"></script>
```

Replace `{{ $clientId }}` with your the `SHOPIFY_API_KEY` provided by the Shopify CLI.

Add a special route for handling some edge cases. Adding this route ensures the merchant experience is resilient:

```php
function patchIdToken($request)
{
    $shopify = getShopifyApp();
    $req = requestToShopifyReq($request);
    $result = $shopify->appHomePatchIdToken($req);

    return shopifyResultToResponse($result);
}
```

This route should match the path configured here:

```php
    $result = $shopify->verifyAppHomeReq(
        $req,
        appHomePatchIdTokenPath: '/auth/patch-id-token',
    );
```

Adding this route ensures the merchant experience is resilient to edge cases.

#### Admin UI Extensions

Admin UI Extension are very similar to App Home. You only need change the verify method:

```diff
-   $result = $shopify->verifyAppHomeReq(
-       $req,
-       appHomePatchIdTokenPath: '/auth/patch-id-token',
-   );
+   $result = $shopify->verifyAdminUIExtReq($req);
```

Admin UI extensions do not need the app home patch id token route. They do not need special headers or Polaris and App Bridge

#### POS UI Extension

POS UI Extension are very similar to App Home. You only need change the verify method:

```diff
-   $result = $shopify->verifyAppHomeReq(
-       $req,
-       appHomePatchIdTokenPath: '/auth/patch-id-token',
-   );
+   $result = $shopify->verifyPosUIExtReq($req);
```

POS UI extensions do not need the app home patch id token route. They do not need special headers or Polaris and App Bridge

### GraphQL Requests

The package provides a method for making Admin GraphQL requests. Note, there may be a better more performant ways to access data using Shopify's infrastructure rather than your own:

- App Home has [Direct API](https://shopify.dev/docs/api/app-home#direct-api-access).
- Admin UI Extensions have [the Query API](https://shopify.dev/docs/api/admin-extensions/latest/api/target-apis/standard-api#standardapi-propertydetail-query)
- POS UI Extensions have [Direct API](https://shopify.dev/docs/api/pos-ui-extensions/latest#direct-api-access)
- Customer Account UI Extensions can query [the Customer Account API](https://shopify.dev/docs/api/customer-account-ui-extensions/latest/apis/customer-account-api), the [Storefront API](https://shopify.dev/docs/api/customer-account-ui-extensions/latest/apis/storefront-api) and the [Order Status API](https://shopify.dev/docs/api/customer-account-ui-extensions/latest/apis/order-status-api/addresses).
- Checkout UI Extensions can query the [Storefront API](https://shopify.dev/docs/api/checkout-ui-extensions/latest/apis/storefront-api) directly.

If you do wish to access the Admin GraphQL API on your server, here is how:

#### When responding to a request from Shopify

Here is how to make a GraphQL request in the context of a request from Shopify. Important notes about this example:

1. This example will use an app home request, but it applies to multiple verify methods
2. This example assumes the request is idempotent
3. This examples assumes, that in the event of a failure, you just want Shopify to retry the request.

More details on points 2 & 3 after the code example.

```php
function appHomeHandler($request)
{
    $shopify = getShopifyApp();
    $req = requestToShopifyReq($request);

    $result = $shopify->verifyAppHomeReq($req);
    if (!$result['ok']) {
        return shopifyResultToResponse($result);
    }

    // Your database logic here
    $accessToken = getAccessToken($result['shop'], 'offline');

    $graphqlResult = $shopify->adminGraphQLRequest(
        '
        {
            shop {
                id
            }
        }
        ',
        shop: $result['shop'],
        accessToken: $accessToken,
        apiVersion: '2025-01',
        // Passing `$result['newIdTokenResponse']` from the verify function
        // tells `adminGraphQLRequest` in what context the GraphQL request is being made.
        // This becomes important if the GraphQL request fails and you wish for Shopify to retry the request.
        invalidTokenResponse: $result['newIdTokenResponse'],
    );

    // The GraphQL failed
    if (!$graphqlResult['ok']) {

        // The access_token is invalid
        // In this example we take the simplest possible approach
        // But depending on your logic, you may want a more complex approach
        // Options are detailed below
        if ($graphqlResult['log']['code'] === 'unauthorized') {
            deleteAccessToken($result['shop'], 'offline');
        }

        return shopifyResultToResponse($graphqlResult);
    }

    $shopId = $graphqlResult['data']['shop']['id'];
}
```

You will get an `unauthorized` log code if:

1. The app was uninstalled (unrecoverable)
2. Your app requested additional scopes, but the users has not yet approved them and you are making a graphQL operation that requires the additional scopes.
3. Your access token has been revoked

If 1 happens, the merchant needs to manually reinstall the app. If 2 or 3 happens there are different approaches you can take:

| Option                                   | Steps                                                                                                  | Use when                                                                                 |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------- |
| 1. Delete & retry (shown above)          | Delete token → return retry response                                                                   | Request is idempotent. OK for Shopify to auto-retry                                      |
| 2. Exchange & update with retry fallback | Token exchange → update token → retry GraphQL → (on fail) delete token → return retry response         | Request is not idempotent. You can revert prior operations. OK for Shopify to auto-retry |
| 3. Exchange with no fallback             | Token exchange → update token → retry GraphQL → (on fail) delete token → return non-retry 401 response | Request is not idempotent. It is not OK for Shopify to auto-retry                        |

#### In a background job

When making GraphQL requests in a background job (e.g., processing a webhook, scheduled task) pass `null` for `invalidTokenResponse`. if the access token is invalid, the request will simply fail.

```php
function processJob($shop)
{
    $shopify = getShopifyApp();
    // Your database logic here
    $accessToken = getAccessToken($shop, 'offline');

    $graphqlResult = $shopify->adminGraphQLRequest(
        '
        {
            shop {
                id
            }
        }
        ',
        shop: $shop,
        accessToken: $accessToken['token'],
        apiVersion: '2025-01',
        invalidTokenResponse: null,
    );

    if (!$graphqlResult['ok']) {
        return;
    }

    $shopId = $graphqlResult['data']['shop']['id'];
}
```

#### Customizing GraphQL Requests

`adminGraphQLRequest` has the following options to customize the GraphQL Request:

- `shop`: Shop domain (e.g., "test-shop").
- `accessToken`: Valid access token for the shop.
- `apiVersion`: API version (e.g., "2025-01")
- `variables`: Optional array of GraphQL variables to pass with your query
- `headers`: Optional array of additional HTTP headers to include in the request
- `maxRetries`: Optional custom retry count for rate-limited or transient errors (default: 2)
- `invalidTokenResponse`: From verification result. If provided, enables retry response when token is invalid (Admin UI Extension or App Home with idempotent operation). If `null`, only fail response is available (requests without ID tokens, background jobs, requires user input before retry)

#### The GraphQL Result

`adminGraphQLRequest` provides the following properties on the result:

- `ok`: Boolean indicating if the request was successful.
- `shop`: The shop domain, or `null` if the request failed.
- `log`: Contains `code` and `detail` describing the result state.
- `response`: The HTTP response with `status`, `body`, and `headers`.
- `httpLogs`: List of HTTP request/response logs for debugging and monitoring.
- `data`: The GraphQL response data, or `null` if the request failed.
- `extensions`: The GraphQL extensions (e.g., cost information), or `null` if not present.

### Verifying requests without exchangeable id tokens

The following requests do not provide the required information for token exchange:

- Webhooks
- App Proxy
- Customer Account UI Extension
- Checkout UI Extension

Webhook and App Proxy requests do not provide an id token. Customer Account and Checkout UI Extensions provide an id token, but it is not exchangeable. None of these requests provide a merchant user ID.

If you require access to the Shopify Admin GraphQL API during these requests you must load an offline access token that was exchanged from an App Home, Admin UI or POS UI Extension request.

#### Webhooks

```php
function webhookHandler($request)
{
    $shopify = getShopifyApp();
    $req = requestToShopifyReq($request);

    $result = $shopify->verifyWebhookReq($req);
    if (!$result['ok']) {
        return shopifyResultToResponse($result);
    }

    // Your database logic here
    $accessToken = getAccessToken($result['shop'], 'offline');
}
```

#### App Proxy

App proxy is very similar to webhooks:

```diff
-    $result = $shopify->verifyWebhookReq($req);
+    $result = $shopify->verifyAppProxyReq($req);
+    $loggedInCustomerId = $result['loggedInCustomerId'];
```

If the customer is not logged in, the `loggedInCustomerId` will be `null`. Do not confuse this with a `userId` stored with an online token which are merchant IDs, not customer IDs.

#### Customer Account UI Extension

Customer Account UI Extensions are almost identical to webhooks:

```diff
-    $result = $shopify->verifyWebhookReq($req);
+    $result = $shopify->verifyCustomerAccountUIExtReq($req);
```

#### Checkout UI Extension

Checkout UI Extensions are almost identical to webhooks:

```diff
-    $result = $shopify->verifyWebhookReq($req);
+    $result = $shopify->verifyCheckoutUIExtReq($req);
```

#### Flow actions (coming soon)

Flow Action requests will be almost identical to webhooks:

```diff
-    $result = $shopify->verifyWebhookReq($req);
+    $result = $shopify->verifyFlowActionReq($req);
```

### Getting access tokens with Client Credentials

[Client credentials exchange](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/client-credentials-grant) allows you to obtain an access token using only your app's client ID and client secret, without requiring an ID token. This is designed for trusted, server-to-server integrations (for example, internal automation or back-office services).

```php
function getOrRefreshAccessToken($shop)
{
    $shopify = getShopifyApp();

    // Check if we have a valid token
    $existingToken = getAccessToken($shop);
    if ($existingToken && !isExpired($existingToken['expires'])) {
        return $existingToken;
    }

    // Get a new token using client credentials
    $result = $shopify->exchangeUsingClientCredentials(shop: $shop);

    if (!$result['ok']) {
        // Log the error
        error_log("{$result['log']['code']} - {$result['log']['detail']}");
        return null;
    }

    // Save the new token
    saveAccessToken($result['accessToken']);
    return $result['accessToken'];
}
```

The `accessToken` object contains:

| Property  | Description                                            |
| --------- | ------------------------------------------------------ |
| `shop`    | The shop domain                                        |
| `token`   | The access token string                                |
| `scope`   | The granted scopes                                     |
| `expires` | ISO 8601 datetime when the token expires (24 hours)    |

Note: Client credentials tokens expire after 24 hours and do not include a refresh token. When the token expires, request a new one using `exchangeUsingClientCredentials` with the same credentials.

## Feedback?

This package is an experimental approach and we'd love to heard your feedback. Please open an issue or post in the [community forums](https://community.shopify.dev/c/shopify-cli-libraries/14)

## Created a template?

We've confirmed that AI can scaffold a template using this README. If you create a template and you'd like to open source it, we'd love to hear from you. Perhaps it can benefit other PHP developers.
