# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0]

- Add an optional `clientAssertion` argument to `exchangeUsingClientCredentials`. Pass an app-created JWT to authenticate one request with a client assertion; omit it, or pass `null`, to keep client-secret authentication. An assertion request makes a single attempt, sends no other authentication credential, and never falls back to the client secret.
- `exchangeUsingClientCredentials` now rejects redirects for both authentication methods, preventing credentials from being forwarded through 307/308 responses. This changes client-secret calls that relied on redirect following. Injected-client defaults and other operations are unchanged.
- Return the documented exchange error, instead of raising `TypeError`, when an unsuccessful client-credentials response body is not JSON, such as an HTML error page or an empty redirect body.
- Add `Shopify\App\ClientAssertion::create()`, a standalone signer for one client assertion. It takes an already-loaded RSA (`RS256`) or EC P-256 (`ES256`) private key, and needs no `ShopifyApp` instance, client secret or network access.
- Redact `client_assertion` in logged request bodies.
- Return the documented `network_error` result, instead of throwing Guzzle's `ConnectException`, when the connection times out, the DNS lookup fails, or the connection is refused. This applies to `exchangeUsingClientCredentials`, `exchangeUsingTokenExchange`, `refreshTokenExchangedAccessToken` and `adminGraphQLRequest`.

## [1.0.3]

- Treat an empty `oldClientSecret` as not set. Verify methods now use only `clientSecret` when `oldClientSecret` is an empty string.
- Validate the shop before building request URLs. `exchangeUsingClientCredentials`, `refreshTokenExchangedAccessToken` and `adminGraphQLRequest` return an error when `shop` is not a valid shop name (e.g., `shop-name`). `exchangeUsingTokenExchange` builds the request URL from the validated shop name.
- `appHomePatchIdToken` returns a 400 response when the `shop` query parameter is not a valid shop domain (e.g., `shop-name.myshopify.com`).

## [1.0.2]

- Harden App Home Redirect URL handling.

## [1.0.1]

- Redact log response for exchange and refresh methods.

## [1.0.0]

- **Breaking:** rename the verify result field `newIdTokenResponse` to `invalidTokenResponse`, matching the `exchangeUsingTokenExchange` and `adminGraphQLRequest` parameters. Update any code that reads this field:

  ```diff
  - $result->newIdTokenResponse
  + $result->invalidTokenResponse
  ```

- Widen the `guzzlehttp/guzzle` constraint to `^7.0 || ^8.0` so installs on projects that pull Guzzle 8 (such as Laravel 13) resolve without error.

## [0.1.5]

- Verify the dest property is not a malicious URL before making a token exchange request
- Reject App Proxy requests with multiple `shop` query parameters with a 401 response.
- Refreshing a non-expiring token now returns a no-refresh-needed result instead of an error
- Checkout UI and Customer Account UI Extension requests now return `shop` without the `https://` prefix
- Update the README for the package

## [0.1.4]

- Add optional `expiring` parameter to `exchangeUsingTokenExchange`. Defaults to `true`. Pass `false` to request a non-expiring token.  If `false`, `refreshToken` and `refreshTokenExpires` will be null in result.

## [0.1.3]

- Redact sensitive information in `log` and `httpLogs`

## [0.1.2]

- Upgrade `firebase/php-jwt` to `^7.0`

## [0.1.1]

- Update encoding of appHomeRedirectUrl.
- Verify webhooks now accepts multiple header formats.
- Fix bug where Array URL params would cause an exception in verify_app_home.

## [0.1.0]

Initial release
