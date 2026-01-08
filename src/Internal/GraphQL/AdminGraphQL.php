<?php

declare(strict_types=1);

namespace Shopify\App\Internal\GraphQL;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shopify\App\Types\GQLResult;
use Shopify\App\Types\Log;
use Shopify\App\Types\ResponseInfo;

/**
 * @internal This class is not part of the public API and may change without notice.
 *           Use ShopifyApp::adminGraphQLRequest() instead.
 */
class AdminGraphQL
{
    public static function request(
        string $query,
        string $shop,
        string $accessToken,
        string $apiVersion,
        ?array $invalidTokenResponse = null,
        ?array $variables = null,
        ?array $headers = null,
        int $maxRetries = 2,
        ?array $appConfig = null,
        $httpClient = null,
    ): GQLResult {
        // Use parameters directly (already extracted from named args)
        if ($headers === null) {
            $headers = [];
        }

        // Validate required parameters
        if (empty($shop)) {
            return self::createErrorResponse(
                'missing_shop',
                'Shop domain is required',
                400
            );
        }

        if (empty($accessToken)) {
            return self::createErrorResponse(
                'missing_access_token',
                'Access token is required',
                400
            );
        }

        if (empty($apiVersion)) {
            return self::createErrorResponse(
                'missing_api_version',
                'API version is required',
                400
            );
        }

        if (empty($query)) {
            return self::createErrorResponse(
                'missing_query',
                'GraphQL query is required',
                400
            );
        }

        // Store original shop for return value
        $originalShop = $shop;

        $endpoint = "https://{$shop}.myshopify.com/admin/api/{$apiVersion}/graphql.json";

        $requestHeaders = array_merge([
            'Content-Type' => 'application/json',
            'X-Shopify-Access-Token' => $accessToken,
            'User-Agent' => \Shopify\App\Internal\Utils\UserAgent::get(),
        ], $headers);

        $requestBody = ['query' => $query];
        if (!empty($variables)) {
            $requestBody['variables'] = $variables;
        }

        // Use injected client or create default one
        $client = $httpClient !== null ? $httpClient : new Client();

        // Execute request with retry logic
        $attempt = 0;
        $logs = [];

        while ($attempt <= $maxRetries) {
            try {
                $response = $client->post($endpoint, [
                    'headers' => $requestHeaders,
                    'json' => $requestBody,
                    'http_errors' => false,
                ]);

                $statusCode = $response->getStatusCode();
                $responseBody = (string) $response->getBody();
                $responseHeaders = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $responseHeaders[$name] = implode(', ', $values);
                }

                $req = [
                    'url' => $endpoint,
                    'method' => 'POST',
                    'headers' => $requestHeaders,
                    'body' => json_encode($requestBody),
                ];

                $res = [
                    'status' => $statusCode,
                    'body' => $responseBody,
                    'headers' => empty($responseHeaders) ? (object)[] : $responseHeaders,
                ];

                // Handle 200 success (but check for GraphQL errors)
                if ($statusCode === 200) {
                    $responseData = json_decode($responseBody, true);

                // Check for GraphQL errors
                    if (isset($responseData['errors']) && !empty($responseData['errors'])) {
                        $logs[] = [
                        'code' => 'graphql_errors',
                        'detail' => 'GraphQL request returned errors',
                        'req' => $req,
                        'res' => $res,
                        ];
                        return new GQLResult(
                            ok: false,
                            shop: null,
                            data: null,
                            extensions: null,
                            log: new Log(
                                code: 'graphql_errors',
                                detail: 'GraphQL request returned errors'
                            ),
                            httpLogs: $logs,
                            response: new ResponseInfo(
                                status: $res['status'],
                                body: $res['body'],
                                headers: $res['headers']
                            )
                        );
                    }

                // Success
                    $logs[] = [
                    'code' => 'success',
                    'detail' => 'GraphQL request successful. Proceed with business logic.',
                    'req' => $req,
                    'res' => $res,
                    ];
                    return new GQLResult(
                        ok: true,
                        shop: $originalShop,
                        data: $responseData['data'] ?? null,
                        extensions: $responseData['extensions'] ?? null,
                        log: new Log(
                            code: 'success',
                            detail: 'GraphQL request successful. Proceed with business logic.'
                        ),
                        httpLogs: $logs,
                        response: new ResponseInfo(
                            status: $res['status'],
                            body: $res['body'],
                            headers: $res['headers']
                        )
                    );
                }

                // Handle 401 unauthorized
                if ($statusCode === 401) {
                    return self::handle401Response($invalidTokenResponse, $req, $res);
                }

                // Handle 429 rate limit
                if ($statusCode === 429 && $attempt < $maxRetries) {
                    $retryAfter = $responseHeaders['Retry-After'] ?? '1';
                    $logs[] = [
                        'code' => 'rate_limited_retry',
                        'detail' => "Rate limited. Retrying after {$retryAfter} seconds (attempt " . ($attempt + 1) . " of " . ($maxRetries + 1) . ").",
                        'req' => $req,
                        'res' => $res,
                    ];
                    sleep((int) $retryAfter);
                    $attempt++;
                    continue;
                }

                if ($statusCode === 429 && $attempt === $maxRetries) {
                    $logs[] = [
                        'code' => 'rate_limited',
                        'detail' => 'Max retries reached after rate limiting. Return 429 Too Many Requests.',
                        'req' => $req,
                        'res' => $res,
                    ];
                    return new GQLResult(
                        ok: false,
                        shop: null,
                        data: null,
                        extensions: null,
                        log: new Log(
                            code: 'rate_limited',
                            detail: 'Max retries reached after rate limiting. Return 429 Too Many Requests.'
                        ),
                        httpLogs: $logs,
                        response: new ResponseInfo(
                            status: 429,
                            body: json_encode(['error' => 'Too many requests']),
                            headers: ['Content-Type' => 'application/json']
                        )
                    );
                }

                // Handle 5xx errors with retry
                if (in_array($statusCode, [502, 503, 504]) && $attempt < $maxRetries) {
                    // Exponential backoff with jitter
                    $baseDelay = 1;
                    $delay = $baseDelay * pow(2, $attempt) + (rand(0, 100) / 1000);
                    $logs[] = [
                        'code' => "http_error_{$statusCode}_retry",
                        'detail' => "HTTP {$statusCode} error. Retrying with exponential backoff (attempt " . ($attempt + 1) . " of " . ($maxRetries + 1) . ").",
                        'req' => $req,
                        'res' => $res,
                    ];
                    usleep((int)($delay * 1000000));
                    $attempt++;
                    continue;
                }

                // Max retries reached for 5xx
                if (in_array($statusCode, [502, 503, 504]) && $attempt === $maxRetries) {
                    $logs[] = [
                        'code' => "http_error_{$statusCode}",
                        'detail' => 'Max retries reached for transient error. Return ' . $statusCode . ' ' . self::getStatusText($statusCode) . '.',
                        'req' => $req,
                        'res' => $res,
                    ];
                    return new GQLResult(
                        ok: false,
                        shop: null,
                        data: null,
                        extensions: null,
                        log: new Log(
                            code: "http_error_{$statusCode}",
                            detail: 'Max retries reached for transient error. Return ' . $statusCode . ' ' . self::getStatusText($statusCode) . '.'
                        ),
                        httpLogs: $logs,
                        response: new ResponseInfo(
                            status: $statusCode,
                            body: '',
                            headers: (object)[]
                        )
                    );
                }

                // Handle non-retryable errors (400, 403, etc.)
                if ($statusCode === 400) {
                    return new GQLResult(
                        ok: false,
                        shop: null,
                        data: null,
                        extensions: null,
                        log: new Log(
                            code: 'http_error_400',
                            detail: 'GraphQL query syntax is invalid. Do not retry.'
                        ),
                        httpLogs: [
                            [
                                'code' => 'http_error_400',
                                'detail' => 'GraphQL query syntax is invalid. Do not retry.',
                                'req' => $req,
                                'res' => $res,
                            ]
                        ],
                        response: new ResponseInfo(
                            status: $res['status'],
                            body: $res['body'],
                            headers: $res['headers']
                        )
                    );
                }

                if ($statusCode === 403) {
                    return new GQLResult(
                        ok: false,
                        shop: null,
                        data: null,
                        extensions: null,
                        log: new Log(
                            code: 'http_error_403',
                            detail: 'Access token lacks required permissions. Do not retry.'
                        ),
                        httpLogs: [
                            [
                                'code' => 'http_error_403',
                                'detail' => 'Access token lacks required permissions. Do not retry.',
                                'req' => $req,
                                'res' => $res,
                            ]
                        ],
                        response: new ResponseInfo(
                            status: $res['status'],
                            body: $res['body'],
                            headers: $res['headers']
                        )
                    );
                }

                // Other HTTP errors
                return new GQLResult(
                    ok: false,
                    shop: null,
                    data: null,
                    extensions: null,
                    log: new Log(
                        code: "http_error_{$statusCode}",
                        detail: "HTTP error {$statusCode}"
                    ),
                    httpLogs: [
                        [
                            'code' => "http_error_{$statusCode}",
                            'detail' => "HTTP error {$statusCode}",
                            'req' => $req,
                            'res' => $res,
                        ]
                    ],
                    response: new ResponseInfo(
                        status: $res['status'],
                        body: $res['body'],
                        headers: $res['headers']
                    )
                );
            } catch (RequestException $e) {
                // Network/connection errors - return immediately without retry
                return new GQLResult(
                    ok: false,
                    shop: null,
                    data: null,
                    extensions: null,
                    log: new Log(
                        code: 'network_error',
                        detail: 'Network error occurred during GraphQL request'
                    ),
                    httpLogs: [
                        [
                            'code' => 'network_error',
                            'detail' => 'Network error occurred during GraphQL request',
                            'req' => [
                                'url' => $endpoint,
                                'method' => 'POST',
                                'headers' => $requestHeaders,
                                'body' => json_encode($requestBody),
                            ],
                            'res' => [
                                'status' => 0,
                                'body' => '',
                                'headers' => (object)[],
                            ],
                        ]
                    ],
                    response: new ResponseInfo(
                        status: 0,
                        body: '',
                        headers: (object)[]
                    )
                );
            }
        }
    }

    private static function handle401Response(?array $invalidTokenResponse, array $req, array $res): GQLResult
    {
        // If invalidTokenResponse provided, use it; otherwise return plain 401
        $response = $invalidTokenResponse ?? [
            'status' => 401,
            'body' => '',
            'headers' => (object)[]
        ];

        return new GQLResult(
            ok: false,
            shop: null,
            data: null,
            extensions: null,
            log: new Log(
                code: 'unauthorized',
                detail: 'Access token is invalid or has been revoked.'
            ),
            httpLogs: [
                [
                    'code' => 'unauthorized',
                    'detail' => 'Access token is invalid or has been revoked.',
                    'req' => $req,
                    'res' => $res,
                ]
            ],
            response: new ResponseInfo(
                status: $response['status'],
                body: $response['body'],
                headers: $response['headers']
            )
        );
    }

    private static function createErrorResponse(string $code, string $detail, int $status): GQLResult
    {
        return new GQLResult(
            ok: false,
            shop: null,
            data: null,
            extensions: null,
            log: new Log(
                code: $code,
                detail: $detail
            ),
            httpLogs: [],
            response: new ResponseInfo(
                status: $status,
                body: '',
                headers: (object)[]
            )
        );
    }

    private static function getStatusText(int $statusCode): string
    {
        $statusTexts = [
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];

        return $statusTexts[$statusCode] ?? 'Error';
    }
}
