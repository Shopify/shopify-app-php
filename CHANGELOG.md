# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
