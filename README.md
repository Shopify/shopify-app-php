# Shopify App PHP Package

PHP package for building Shopify applications.

This package encodes the secure, correct way to build a Shopify app. Most of what you need is already handled for you. The guidance below is written so you use the built-in path and do not reimplement (less safely) things the package already does. If you are ever tempted to parse, verify, or refresh something yourself, check the [Common misuses to avoid](#common-misuses-to-avoid) section first.

> **Upgrading from [`shopify/shopify-api`](https://github.com/Shopify/shopify-api-php)?** This is a new, framework-agnostic package with a different API, designed for incremental adoption. Adopt it gradually, one route at a time. Breaking changes between releases are listed in the [CHANGELOG](CHANGELOG.md).

## Contents

- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Requirements](#requirements)
- [Features](#features)
- [Principles](#principles)
- [Start here (the green path)](#start-here-the-green-path)
- [Setup steps](#setup-steps)
- [Using the package](#using-the-package)
  - [Initialization](#initialization)
  - [Converting a request](#converting-a-request)
  - [Converting a Shopify response](#converting-a-shopify-response)
  - [The verify result](#the-verify-result)
  - [Getting the shop](#getting-the-shop)
  - [Verifying requests with exchangeable ID tokens](#verifying-requests-with-exchangeable-id-tokens)
  - [Navigating inside and outside the App Home iframe](#navigating-inside-and-outside-the-app-home-iframe)
  - [GraphQL requests](#graphql-requests)
  - [Verifying requests without exchangeable ID tokens](#verifying-requests-without-exchangeable-id-tokens)
  - [Getting access tokens with client credentials](#getting-access-tokens-with-client-credentials)
- [Common misuses to avoid](#common-misuses-to-avoid)
- [Contributing, issues, feedback and feature requests](#contributing-issues-feedback-and-feature-requests)

## Prerequisites

Before you start, make sure you have:

- A [Shopify Partner account](https://partners.shopify.com) and a development store
- The [Shopify CLI](https://shopify.dev/docs/api/shopify-cli#installation) installed
- PHP 8.2+ and [Composer](https://getcomposer.org)
- A web framework project (Laravel, Symfony, CodeIgniter, or plain PHP)

## Installation

```bash
composer require shopify/shopify-app-php
```

## Requirements

- PHP 8.2 or higher
- firebase/php-jwt for JWT token handling
- guzzlehttp/guzzle for HTTP requests

## Features

Each function encodes a piece of the secure green path. Use the listed function rather than building your own version.

Request Verification:

- `verifyAdminUIExtReq`: Requests from Admin UI extensions
- `verifyAppHomeReq`: Requests for embedded app home that use App Bridge
- `verifyAppProxyReq`: Requests from storefronts via App Proxy
- `verifyCheckoutUIExtReq`: Requests from checkout UI extensions
- `verifyCustomerAccountUIExtReq`: Requests from Customer account UI extensions
- `verifyFlowActionReq`: Requests from Flow action extensions
- `verifyPosUIExtReq`: Requests from POS UI extensions
- `verifyWebhookReq`: Webhook requests

Exchange:

- `exchangeUsingTokenExchange`: Use Token Exchange to exchange an ID token for an access token
- `exchangeUsingClientCredentials`: Get access tokens via client credentials
- `refreshTokenExchangedAccessToken`: Refresh an access token that was created using Token Exchange. Checks if a token refresh can and should happen.

GraphQL:

- `adminGraphQLRequest`: Make Admin API GraphQL requests with automatic retry handling

Helpers:

- `appHomePatchIdToken`: Securely renders the HTML to refresh a stale ID token. Use this instead of your own logic.
- `appHomeParentRedirect`: Asks the parent (Shopify admin) to redirect to a new URL, breaking out of the iframe
- `appHomeRedirect`: Redirects to a relative URL within the app home iframe

## Principles

1. **Built-in best practices:** This package encodes best practices for building Shopify apps as primitives. Use them correctly and you'll build secure, performant apps on the green-path.
2. **What most apps need most of the time:** This package does not intend to focus on some less common features of the Shopify app platform (e.g: Non Embedded apps).
3. **Framework agnostic:** Whether you're using Laravel, Symfony, CodeIgniter, or raw PHP, these packages won't force architectural decisions on you. We provide primitives. You compose them however you wish. We've prototyped extensively to make sure that composition can lead to idiomatic patterns.
4. **Language agnostic:** Whilst this is a PHP package, its API is shared with a Python package. This creates some interesting constraints, and sacrifices some idioms. But... the big benefit is that fixes in one community will benefit the other. As the Python package evolves, so will the PHP package (and vice-versa).

## Start here (the green path)

Most apps follow the same path on every request from Shopify:

1. Convert your framework's request into the package's request shape.
2. Verify the request with the matching `verify...` function.
3. If `ok` is `false`, return the provided `response`.
4. Use `$result->shop` to look up or store the shop's access token.
5. Exchange or refresh the token when needed.
6. Make Admin GraphQL calls, passing the retry response the package gave you.

Every step below has a built-in function. Use it. The recurring rule in this README: if the package already gives you a value or a response, use that value or response. Do not parse, craft, or re-verify it yourself.

## Setup steps

This section will focus on steps that are universal to any web framework. We'll provide examples for Laravel, Symfony and CodeIgniter. But these examples are fairly universal and can be translated to other approaches.

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

Create a `shopify.web.toml`. The Shopify CLI needs this file to know how to serve your app during development. Set `roles` and the `dev` command so the CLI can serve and proxy your app.

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

For secret rotation, `oldClientSecret` is an optional parameter. Since the CLI does not provide this env var, you will need to provide it manually. Requests signed with either the current or the old secret are accepted while you roll the secret out, so you avoid downtime during rotation. Read more about [secret rotation](https://shopify.dev/docs/apps/build/authentication-authorization/client-secrets/rotate-revoke-client-credentials).

```php
$shopify = new ShopifyApp(
    clientId: getenv('SHOPIFY_API_KEY'),
    clientSecret: getenv('SHOPIFY_API_SECRET'),
    oldClientSecret: getenv('SHOPIFY_OLD_API_SECRET'),
);
```

**Do** set `oldClientSecret` while rotating a secret, then remove it once the rotation is complete.

**Don't** roll a secret without it. If you swap the secret in one step, in-flight requests signed with the previous secret will fail verification.

### Converting a request

So that the package can support multiple frameworks, your app must convert your framework's concept of a Request to the package's concept.

**Do** pass the raw request through unchanged: the method, all headers, the full URL including query string, and the unmodified body.

**Don't** filter headers, drop query parameters, or re-encode the body. Verification depends on the exact bytes Shopify sent. Altering them causes valid requests to fail verification.

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

Your app must convert the package's concept of a Response to the framework's concept. The Result provided by the package's function also includes a `log` property with these properties:

- `code`: A short string describing the situation
- `detail`: Copy describing the state of the request and what you should do next.
- `req`: The Req that was passed to the function.

We recommend logging this information to help you debug.

**Do** return the package's `response` verbatim, including its `status`, `body`, and `headers`.

**Don't** build your own response for failures or drop the headers. The package's response carries the correct status and the security headers Shopify requires (see [Getting the shop](#getting-the-shop) and the App Home section for why this matters).

Laravel example:

```php
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

function shopifyResultToResponse($result)
{
    $log = $result->log;
    Log::info("{$log->code} - {$log->detail}");

    $resp = $result->response;
    return response($resp->body, $resp->status)
        ->withHeaders((array) $resp->headers);
}
```

Symfony example:

```php
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

function shopifyResultToResponse($result, LoggerInterface $logger)
{
    $log = $result->log;
    $logger->info("{$log->code} - {$log->detail}");

    $resp = $result->response;
    return new Response(
        $resp->body,
        $resp->status,
        (array) $resp->headers
    );
}
```

CodeIgniter Example:

```php
use CodeIgniter\HTTP\Response;

function shopifyResultToResponse($result)
{
    $log = $result->log;
    log_message('info', "{$log->code} - {$log->detail}");

    $resp = $result->response;
    return service('response')
        ->setStatusCode($resp->status)
        ->setBody($resp->body)
        ->setHeaders((array) $resp->headers);
}
```

### The verify result

Verifying a request returns a `Result`. A `Result` is similar across all verify functions, with some differences.

Common properties (all verify functions):

| Property | Description | Nullable |
| --- | --- | --- |
| `ok` | Boolean indicating if the request passed verification. Respond with the Response if `false` | No |
| `shop` | The shop sub domain (e.g: `test-shop`, for `test-shop.myshopify.com`). `null` when verification fails. Already parsed correctly for the request type and verified. Always use this value. See [Getting the shop](#getting-the-shop). | Yes |
| `log` | Object containing `code`, `detail`, and `req` for debugging and monitoring. | No |
| `response` | Pre-built HTTP response with `status`, `body`, and `headers`. Return this when `ok` is `false`. Carries required security headers. Return it as-is. | No |

Properties for Exchangeable ID Token Requests (`verifyAppHomeReq`, `verifyAdminUIExtReq`, `verifyPosUIExtReq`):

| Property | Description | Nullable |
| --- | --- | --- |
| `userId` | The merchant user ID. `null` if `ok` is `false`. | Yes |
| `idToken` | Object containing `exchangeable` (Boolean), `token` (string), and `claims` (array). | Yes |
| `invalidTokenResponse` | Pre-built response for the invalid token retry flow. Pass it to `exchangeUsingTokenExchange` and `adminGraphQLRequest` so Shopify can retry requests with a fresh token. | Yes |

Properties for App Proxy Requests (`verifyAppProxyReq`):

| Property | Description | Nullable |
| --- | --- | --- |
| `loggedInCustomerId` | The customer ID if logged in. `null` if not logged in. This is a customer ID, not a merchant user ID. | Yes |

### Getting the shop

**Do** use `shop` from the verify result. We securely parse it from the request, avoiding known attack vectors.

**Don't** read the shop from the request yourself (for example by decoding the ID token). That is fragile and skips that protection.

```php
$shop = $result->shop; // e.g. "test-shop"
```

### Verifying requests with exchangeable ID tokens

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
    if (!$result->ok) {
        return shopifyResultToResponse($result);
    }
```

`appHomePatchIdTokenPath` is required on every `verifyAppHomeReq` call. It points at your [token-refresh route](#add-the-token-refresh-route). When a page request arrives without a token, or with a stale one, verify redirects the browser there to get a fresh token, so it needs to know the path. Verify checks it on every call, so pass it even on routes that only ever receive `fetch` requests. An empty or missing value returns a configuration error (HTTP 500).

Then we check if there is an access token in the database. If there is one we check if it needs to be refreshed.

```php
    // Your database logic here
    $accessToken = getAccessToken($result->shop, 'offline');

    if ($accessToken) {
        $refreshResult = $shopify->refreshTokenExchangedAccessToken($accessToken);

        if (!$refreshResult->ok) {
            return shopifyResultToResponse($refreshResult);
        }

        if (isset($refreshResult->accessToken)) {
            // Package returned a refreshed token, save it
            saveAccessToken($refreshResult->accessToken);
        }
    }
```

**Do** call `refreshTokenExchangedAccessToken` and save the token if one is returned.

**Don't** add your own checks before calling it. It already verifies whether a refresh token exists and whether it has expired, and returns a fresh token only when one is needed. Wrapping it with your own pre-checks just duplicates that logic and risks getting it wrong.

You will need to write the database code to get and save access tokens. The package always returns and accepts access tokens in the same shape:

| Key | Type | Description |
| --- | --- | --- |
| `shop` | string | Shop sub domain (e.g., "test-shop") |
| `accessMode` | string | Access mode: "online" or "offline" |
| `token` | string | The access token |
| `scope` | string | Granted scopes |
| `refreshToken` | string or null | Token used to refresh the access token. `null` for non-expiring tokens. |
| `expires` | string or null | ISO 8601 datetime when access token expires. `null` for non-expiring tokens. |
| `refreshTokenExpires` | string or null | ISO 8601 datetime when refresh token expires. `null` for non-expiring tokens. |
| `user` | array or null | User details (online mode only, `null` for offline) |

For the merchant user id, use `$result->userId` from the verify result. Online access tokens also include a `user` object with an `id`; offline tokens have no `user`.

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
            idToken: $result->idToken,
            invalidTokenResponse: $result->invalidTokenResponse,
        );

        if (!$exchangeResult->ok) {
            return shopifyResultToResponse($exchangeResult);
        }

        // Save the new token
        saveAccessToken($exchangeResult->accessToken);
    }
```

Note:

- `exchangeUsingTokenExchange` receives `$result->invalidTokenResponse` from the verify function. Passing it lets Shopify automatically retry the request if the id token has become stale. Whether you pass it depends on the request type (see the GraphQL section).
- Pass `expiring: false` to request a non-expiring token (no `refreshToken` or `refreshTokenExpires`). Defaults to `true`.
- If using online access tokens, use the `userId` provided by the verify `result`, not the access token.
- If your app has need to access the admin API outside of requests from App Home, Admin UI Extensions or POS UI Extensions you should also exchange and save an offline token.

##### Return the required App Home response headers

App home requests require [special Response headers](https://shopify.dev/docs/apps/build/security/set-up-iframe-protection) (for example, the `Content-Security-Policy` `frame-ancestors` directive). These headers are what allow your app to load securely inside the Shopify admin iframe.

**Do** copy the headers from the verify result onto your App Home response:

```php
// Copy headers from result to your response
$headers = $result->response->headers ?? [];
foreach ($headers as $header => $value) {
    $response->headers->set($header, $value);
}
```

When `ok` is `false`, return the provided `response` as-is. When `ok` is `true`, copy `response.headers` onto your framework's response before rendering App Home.

App requests should also contain [App Bridge](https://shopify.dev/docs/api/app-bridge) and [Polaris Web Components](https://shopify.dev/docs/api/app-home/using-polaris-components) script tags so they remain secure and can look like Shopify:

```html
<script
  src="https://cdn.shopify.com/shopifycloud/app-bridge.js"
  data-api-key="{{ $clientId }}"
></script>
<script src="https://cdn.shopify.com/shopifycloud/polaris.js"></script>
```

Replace `{{ $clientId }}` with the `SHOPIFY_API_KEY` provided by the Shopify CLI.

##### Add the token-refresh route

Add a route that serves the token-refresh (patch ID token) page. This is what makes ordinary link and full-page navigation inside the App Home iframe work: when a navigation reaches your server without a session token, App Bridge uses this route to obtain a fresh one and retry the original request. Skipping it means in-app navigations that arrive without a token cannot recover.

**Do** use `appHomePatchIdToken` for this route:

```php
function patchIdToken($request)
{
    $shopify = getShopifyApp();
    $req = requestToShopifyReq($request);
    $result = $shopify->appHomePatchIdToken($req);

    return shopifyResultToResponse($result);
}
```

**Don't** build your own token-refresh page. By using `appHomePatchIdToken`, your app stays secure against known attack vulnerabilities.

This route should match the path configured in `verifyAppHomeReq`:

```php
    $result = $shopify->verifyAppHomeReq(
        $req,
        appHomePatchIdTokenPath: '/auth/patch-id-token',
    );
```

#### Admin UI Extensions

Admin UI Extension are very similar to App Home. You only need change the verify method:

```php
$result = $shopify->verifyAdminUIExtReq($req);
```

Admin UI extensions do not need the app home patch id token route. They do not need special headers or Polaris and App Bridge.

#### POS UI Extension

POS UI Extension are very similar to App Home. You only need change the verify method:

```php
$result = $shopify->verifyPosUIExtReq($req);
```

POS UI extensions do not need the app home patch id token route. They do not need special headers or Polaris and App Bridge.

### Navigating inside and outside the App Home iframe

App Home renders inside a cross-origin iframe. Because of this, ordinary links and redirects need care: the iframe cannot rely on cookies, and every authenticated request needs a session token. Use the helpers below rather than building redirects by hand.

#### Redirecting outside the App Home iframe

Use `appHomeParentRedirect` when you need to redirect the merchant to an external URL, breaking out of the app iframe:

```php
function someHandler($request)
{
    $shopify = getShopifyApp();
    $req = requestToShopifyReq($request);

    $result = $shopify->verifyAppHomeReq($req, appHomePatchIdTokenPath: '/auth/patch-id-token');
    if (!$result->ok) {
        return shopifyResultToResponse($result);
    }

    // Redirect to an external URL
    $redirectResult = $shopify->appHomeParentRedirect(
        $req,
        redirectUrl: 'https://example.com',
        shop: $result->shop,
    );

    return shopifyResultToResponse($redirectResult);
}
```

For navigating to admin pages, we recommend using [Admin Intents](https://shopify.dev/docs/apps/build/admin/admin-intents) as this provides the best merchant experience. However, if this is not possible, you can redirect to Shopify admin pages using the `shop` value from the verify result (e.g., `"https://admin.shopify.com/store/{$result->shop}/products"`).

#### Redirecting within the App Home iframe

Use `appHomeRedirect` when you need to redirect to another route within your app, staying inside the app iframe:

```php
function someHandler($request)
{
    $shopify = getShopifyApp();
    $req = requestToShopifyReq($request);

    $result = $shopify->verifyAppHomeReq($req, appHomePatchIdTokenPath: '/auth/patch-id-token');
    if (!$result->ok) {
        return shopifyResultToResponse($result);
    }

    // Redirect to another route within the app
    $redirectResult = $shopify->appHomeRedirect(
        $req,
        redirectUrl: '/dashboard',
        shop: $result->shop,
    );

    return shopifyResultToResponse($redirectResult);
}
```

Note: The redirect URL must be a relative path starting with `/`. URL parameters from the original request are automatically merged into the redirect URL.

#### Authenticated navigation between your own pages

App Bridge attaches the session token to `fetch` and `XMLHttpRequest`, and it intercepts link clicks on `a`, `s-link`, `s-button`, and `s-clickable` elements. It does not intercept same-origin navigations that target the current frame, so an ordinary link or full-page navigation to one of your own pages reaches your server without a session token, and the destination cannot verify the request.

You do not need a single-page app to handle this. As long as you wire the [token-refresh route](#add-the-token-refresh-route) and pass `appHomePatchIdTokenPath` to every `verifyAppHomeReq` call, the package recovers automatically: when a navigation arrives without a token, verify returns a response that redirects to the patch ID token page, App Bridge re-requests the same URL with a fresh token, and the retried request verifies. Standard multi-page apps work this way.

Do not pass the ID token as a URL parameter to work around this. The token expires after about a minute, so it goes stale on any later navigation, and tokens in URLs leak into logs, referrers, and browser history.

For more detail, see the [App Home documentation](https://shopify.dev/docs/api/app-home).

### GraphQL requests

The package provides a method for making Admin GraphQL requests. Note, there may be a better more performant way to access data using Shopify's infrastructure rather than your own:

- App Home has [Direct API](https://shopify.dev/docs/api/app-home#direct-api-access).
- Admin UI Extensions have [the Query API](https://shopify.dev/docs/api/admin-extensions/latest/api/target-apis/standard-api#standardapi-propertydetail-query)
- POS UI Extensions have [Direct API](https://shopify.dev/docs/api/pos-ui-extensions/latest#direct-api-access)
- Customer Account UI Extensions can query [the Customer Account API](https://shopify.dev/docs/api/customer-account-ui-extensions/latest/apis/customer-account-api), the [Storefront API](https://shopify.dev/docs/api/customer-account-ui-extensions/latest/apis/storefront-api) and the [Order Status API](https://shopify.dev/docs/api/customer-account-ui-extensions/latest/apis/order-status-api/addresses).
- Checkout UI Extensions can query the [Storefront API](https://shopify.dev/docs/api/checkout-ui-extensions/latest/apis/storefront-api) directly.

**Do** prefer the surface-specific data APIs above when you can. **Don't** route data through your own server with `adminGraphQLRequest` when a faster first-party option exists for that surface.

If you do wish to access the Admin GraphQL API on your server, here is how:

#### When responding to a request from Shopify

Here is how to make a GraphQL request in the context of a request from Shopify. Important notes about this example:

1. This example will use an app home request, but it applies to multiple verify methods
2. This example assumes the request is idempotent
3. This example assumes, that in the event of a failure, you just want Shopify to retry the request.

More details on points 2 & 3 after the code example.

```php
function appHomeHandler($request)
{
    $shopify = getShopifyApp();
    $req = requestToShopifyReq($request);

    $result = $shopify->verifyAppHomeReq($req, appHomePatchIdTokenPath: '/auth/patch-id-token');
    if (!$result->ok) {
        return shopifyResultToResponse($result);
    }

    // Your database logic here
    $accessToken = getAccessToken($result->shop, 'offline');

    // If there is no stored token (for example after a delete & retry, where the token
    // was just deleted), exchange one before using it. Otherwise `$accessToken` is null
    // and the request below crashes.
    if (!$accessToken) {
        $exchangeResult = $shopify->exchangeUsingTokenExchange(
            accessMode: 'offline',
            idToken: $result->idToken,
            invalidTokenResponse: $result->invalidTokenResponse,
        );
        if (!$exchangeResult->ok) {
            return shopifyResultToResponse($exchangeResult);
        }
        saveAccessToken($exchangeResult->accessToken);
        $accessToken = $exchangeResult->accessToken;
    }

    $graphqlResult = $shopify->adminGraphQLRequest(
        '
        {
            shop {
                id
            }
        }
        ',
        shop: $result->shop,
        accessToken: $accessToken->token,
        apiVersion: '2025-01',
        // Passing `$result->invalidTokenResponse` from the verify function
        // tells `adminGraphQLRequest` in what context the GraphQL request is being made.
        // This becomes important if the GraphQL request fails and you wish for Shopify to retry the request.
        invalidTokenResponse: $result->invalidTokenResponse,
    );

    // The GraphQL failed
    if (!$graphqlResult->ok) {

        // The access_token is invalid
        // In this example we take the simplest possible approach
        // But depending on your logic, you may want a more complex approach
        // Options are detailed below
        if ($graphqlResult->log->code === 'unauthorized') {
            deleteAccessToken($result->shop, 'offline');
        }

        return shopifyResultToResponse($graphqlResult);
    }

    $shopId = $graphqlResult->data['shop']['id'];
}
```

**Do** branch on the `log` `code` the package returns, as shown above.

**Don't** invent your own error codes or parse error messages. Branch on the `log` `code` the package returns.

Some failures are unrecoverable (for example the app was uninstalled), in which case the merchant must reinstall. If the token is valid but the merchant has not approved a required scope, they must approve it (do not retry). If the token is revoked or invalid, you can recover; the options are below.

For a revoked or invalid token, there are different approaches you can take:

| Option | Steps | Use when |
| --- | --- | --- |
| 1. Delete & retry (shown above) | Delete token → return retry response | Request is idempotent. OK for Shopify to auto-retry |
| 2. Exchange & update with retry fallback | Token exchange → update token → retry GraphQL → (on fail) delete token → return retry response | Request is not idempotent. You can revert prior operations. OK for Shopify to auto-retry |
| 3. Exchange with no fallback | Token exchange → update token → retry GraphQL → (on fail) delete token → return non-retry 401 response | Request is not idempotent. It is not OK for Shopify to auto-retry |

**Note on "Delete & retry":** after you delete the token and return the retry response, App Bridge retries the _same_ request with a fresh session token. That retried request no longer has a stored access token, so the route it lands on must be able to obtain one again (exchange `idToken` from the verify result). If the route only reads a stored token, the retry will fail. The App Home flow above already handles this: it exchanges a token when none is stored.

#### In a background job

When making GraphQL requests in a background job (e.g., processing a webhook, scheduled task) pass `null` for `invalidTokenResponse`. If the access token is invalid, the request will simply fail.

**Do** pass `null` here. In a background job there is no live request for Shopify to retry, so there is nothing for a retry response to attach to.

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
        accessToken: $accessToken->token,
        apiVersion: '2025-01',
        invalidTokenResponse: null,
    );

    if (!$graphqlResult->ok) {
        return;
    }

    $shopId = $graphqlResult->data['shop']['id'];
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

### Verifying requests without exchangeable ID tokens

The following requests do not provide the required information for token exchange:

- Webhooks
- App Proxy
- Customer Account UI Extension
- Checkout UI Extension

Webhook and App Proxy requests do not provide an id token. Customer Account and Checkout UI Extensions provide an id token, but it is not exchangeable. None of these requests provide a merchant user ID.

If you require access to the Shopify Admin GraphQL API during these requests you must load an offline access token that was exchanged from an App Home, Admin UI or POS UI Extension request.

In every case below, use `$result->shop` to look up the stored token. Do not parse the shop yourself (see [Getting the shop](#getting-the-shop)).

#### Webhooks

```php
function webhookHandler($request)
{
    $shopify = getShopifyApp();
    $req = requestToShopifyReq($request);

    $result = $shopify->verifyWebhookReq($req);
    if (!$result->ok) {
        return shopifyResultToResponse($result);
    }

    // Your database logic here
    $accessToken = getAccessToken($result->shop, 'offline');
}
```

#### App Proxy

App proxy is very similar to webhooks:

```php
$result = $shopify->verifyAppProxyReq($req);
$loggedInCustomerId = $result->loggedInCustomerId;
```

If the customer is not logged in, the `loggedInCustomerId` will be `null`. Do not confuse this with a `userId` stored with an online token, which are merchant IDs, not customer IDs.

#### Customer Account UI Extension

Customer Account UI Extensions are almost identical to webhooks:

```php
$result = $shopify->verifyCustomerAccountUIExtReq($req);
```

#### Checkout UI Extension

Checkout UI Extensions are almost identical to webhooks:

```php
$result = $shopify->verifyCheckoutUIExtReq($req);
```

#### Flow actions

Flow Action requests are almost identical to webhooks:

```php
$result = $shopify->verifyFlowActionReq($req);
```

### Getting access tokens with client credentials

[Client credentials exchange](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/client-credentials-grant) allows you to obtain an access token using only your app's client ID and client secret, without requiring an ID token. This is designed for trusted, server-to-server integrations (for example, internal automation or back-office services).

**Do** use this for trusted server-to-server work where there is no merchant request.

**Don't** use it to authenticate a request coming from App Home or an extension. Those requests carry an ID token, and you should verify them and exchange that token instead.

```php
function getOrRefreshAccessToken($shop)
{
    $shopify = getShopifyApp();

    // Check if we have a valid token
    $existingToken = getAccessToken($shop);
    if ($existingToken && !isExpired($existingToken->expires)) {
        return $existingToken;
    }

    // Get a new token using client credentials
    $result = $shopify->exchangeUsingClientCredentials(shop: $shop);

    if (!$result->ok) {
        // Log the error
        error_log("{$result->log->code} - {$result->log->detail}");
        return null;
    }

    // Save the new token
    saveAccessToken($result->accessToken);
    return $result->accessToken;
}
```

The `accessToken` object contains:

| Property     | Description                                         |
| ------------ | --------------------------------------------------- |
| `shop`       | The shop sub domain (e.g., "test-shop")             |
| `accessMode` | Always "offline"                                    |
| `token`      | The access token string                             |
| `scope`      | The granted scopes                                  |
| `expires`    | ISO 8601 datetime when the token expires (24 hours) |
| `user`       | Always `null` for client credentials                |

Note: Client credentials tokens expire after 24 hours and do not include a refresh token. When the token expires, request a new one using `exchangeUsingClientCredentials` with the same credentials.

## Common misuses to avoid

The package already handles these for you. Rebuilding them yourself is slower, and in the security-sensitive cases it is genuinely risky. Use the built-in path.

| Don't | Do instead | Why |
| --- | --- | --- |
| Parse the shop from the ID token or request | Use `$result->shop` | It is already parsed correctly per request type and verified against spoofing. The shop decides which store's token is used. |
| Build your own token-refresh page | Use `appHomePatchIdToken` | It returns a complete, safe response. Custom pages that render request values are a common injection risk. |
| Render App Home without the response headers | Return `$result->response->headers` | These are the required iframe-protection headers (for example CSP `frame-ancestors`). |
| Craft your own response on a failed verify | Return `$result->response` as-is | It has the correct status and the security headers already set. |
| Alter headers, query string, or body before verifying | Pass the raw request through unchanged | Verification depends on the exact bytes Shopify sent. |
| Add the ID token to the URL, or reach for a single-page app, to keep navigation authenticated | Wire the token-refresh route and pass `appHomePatchIdTokenPath` on every verify | Same-origin link and full-page navigations arrive without a session token. The route lets App Bridge re-request with a fresh one, so multi-page apps work without tokens in URLs. |

## Contributing, issues, feedback and feature requests

This package does not accept contributions, but we'd love to hear your feedback.

To report a bug, request a feature, or share feedback, post in the [Shopify dev community forums](https://community.shopify.dev/new-topic?title=[Feedback%20for%20php%20package]&category=shopify-cli-libraries&tags=php-library&domain=PHP%20Library). Please don’t open pull requests or GitHub issues here; They will be closed automatically.

We triage and discuss work in the forums. Please see [CONTRIBUTING.md](https://github.com/Shopify/shopify-app-php?tab=contributing-ov-file) for details.

## Created a template?

We've confirmed that AI can scaffold a template using this README. If you create a template and you'd like to open source it, we'd love to hear from you. Perhaps it can benefit other PHP developers.
