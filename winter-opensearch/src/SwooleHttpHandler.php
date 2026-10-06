<?php
declare(strict_types=1);

namespace dev\winterframework\opensearch;

use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Exception\ConnectException;
use GuzzleHttp\Ring\Exception\RingException;
use GuzzleHttp\Ring\Future\CompletedFutureArray;
use OpenSearch\ClientBuilder;
use Swoole\Coroutine;
use Throwable;

/**
 * OpenSearch PHP client uses ezimuel/ringphp for its transport layer (NOT
 * Guzzle/PSR-7 directly). A RingPHP handler is a callable with the signature:
 *
 *     function(array $request): FutureArrayInterface
 *
 * where $request is a plain array (http_method, scheme, uri, headers, body,
 * client) and the return value must be a FutureArrayInterface resolving to a
 * response array (status, headers, body [stream resource], effective_url,
 * transfer_stats) or containing an "error" key on failure.
 *
 * The default RingPHP handler uses cURL directly, which fails under Swoole's
 * coroutine cURL hook (SWOOLE_HOOK_ALL) because CURLOPT_PROTOCOLS_STR
 * (10318) is not supported by swoole_curl_setopt(). This handler replaces
 * cURL with Swoole\Coroutine\Http\Client so requests stay non-blocking
 * inside Swoole coroutines.
 *
 * Outside a coroutine (module boot, CLI migrations, tests) the stock cURL
 * handler is used: the coroutine client cannot run there.
 *
 * Honoured client options: timeout / connect_timeout (0 = no limit, as with
 * cURL), verify (ClientBuilder::setSSLVerification: bool or CA bundle path,
 * peer verification ON by default; CURLOPT_CAINFO also accepted) and proxy.
 */
class SwooleHttpHandler {
    /** @var callable|null */
    private $fallback = null;

    public static function inCoroutine(): bool {
        return extension_loaded('swoole') && Coroutine::getCid() > 0;
    }

    public function __invoke(array $request) {
        if (!self::inCoroutine()) {
            $this->fallback ??= ClientBuilder::defaultHandler();
            return ($this->fallback)($request);
        }

        $start = microtime(true);
        $client = null;
        try {
            $uri = Core::url($request);
            $parts = parse_url($uri);

            $scheme = strtolower($parts['scheme'] ?? ($request['scheme'] ?? 'http'));
            $ssl = $scheme === 'https';
            $host = $parts['host'] ?? '';
            // opensearch-php keeps the connection port out of the URL: the
            // Host header carries it only when port_in_header is enabled,
            // but Connection ALWAYS sets CURLOPT_PORT from the configured
            // host (default 9200). Without this, non-default ports silently
            // fall back to 443/80 and every request fails ("No alive nodes").
            $curlOpts = $request['client']['curl'] ?? [];
            $portKey = defined('CURLOPT_PORT') ? CURLOPT_PORT : 3;
            $curlPort = (is_array($curlOpts) && isset($curlOpts[$portKey]))
                ? (int) $curlOpts[$portKey]
                : null;
            $port = $parts['port'] ?? $curlPort ?? ($ssl ? 443 : 80);

            $path = $parts['path'] ?? '/';
            if ($path === '') {
                $path = '/';
            }
            if (!empty($parts['query'])) {
                $path .= '?' . $parts['query'];
            }

            $client = new \Swoole\Coroutine\Http\Client($host, (int) $port, $ssl);

            $client->set(self::clientSettings($host, $ssl, $request['client'] ?? []));

            $headers = [];
            foreach (($request['headers'] ?? []) as $name => $values) {
                $headers[$name] = implode(', ', (array) $values);
            }
            unset(
                $headers['Content-Length'],
                $headers['Transfer-Encoding'],
                $headers['Connection'],
                $headers['Expect']
            );
            // opensearch-php passes basic-auth credentials as cURL options
            // (CURLOPT_HTTPAUTH / CURLOPT_USERPWD), not as a header. The stock
            // cURL handler applies them; translate them here into a preemptive
            // Authorization header so secured clusters don't answer 401.
            $hasAuthHeader = false;
            foreach ($headers as $headerName => $_) {
                if (strcasecmp((string) $headerName, 'Authorization') === 0) {
                    $hasAuthHeader = true;
                    break;
                }
            }
            if (!$hasAuthHeader) {
                $curlOpts = $request['client']['curl'] ?? [];
                $userPwdKey = defined('CURLOPT_USERPWD') ? CURLOPT_USERPWD : 10005;
                $userPwd = is_array($curlOpts) ? ($curlOpts[$userPwdKey] ?? null) : null;
                if (is_string($userPwd) && $userPwd !== '') {
                    $headers['Authorization'] = 'Basic ' . base64_encode($userPwd);
                }
            }
            $client->setHeaders($headers);

            $client->setMethod($request['http_method'] ?? 'GET');

            $body = Core::body($request);
            if ($body !== null && $body !== '') {
                $client->setData((string) $body);
            }

            $ok = $client->execute($path);

            if (!$ok) {
                $errMsg = $client->errMsg ?: 'Swoole HTTP request failed';
                return new CompletedFutureArray([
                    'status' => null,
                    'headers' => [],
                    'body' => null,
                    'effective_url' => $uri,
                    'transfer_stats' => [
                        'url' => $uri,
                        'primary_port' => $port,
                        'total_time' => microtime(true) - $start,
                    ],
                    'curl' => ['errno' => 7, 'error' => $errMsg],
                    'error' => new ConnectException($errMsg),
                ]);
            }

            $responseHeaders = [];
            foreach ($client->headers ?? [] as $name => $value) {
                $responseHeaders[$name] = is_array($value) ? $value : [$value];
            }

            // RingPHP/OpenSearch's Connection::wrapHandler() calls
            // stream_get_contents() on the body, so it must be a stream resource.
            $bodyStream = fopen('php://temp', 'w+b');
            fwrite($bodyStream, (string) ($client->body ?? ''));
            rewind($bodyStream);

            return new CompletedFutureArray([
                'status' => $client->statusCode,
                'headers' => $responseHeaders,
                'body' => $bodyStream,
                'effective_url' => $uri,
                'transfer_stats' => [
                    'url' => $uri,
                    'primary_port' => $port,
                    'total_time' => microtime(true) - $start,
                ],
            ]);
        } catch (Throwable $e) {
            return new CompletedFutureArray([
                'status' => null,
                'headers' => [],
                'body' => null,
                'effective_url' => $uri ?? '',
                'transfer_stats' => [
                    'url' => $uri ?? '',
                    'primary_port' => $port ?? 0,
                    'total_time' => microtime(true) - $start,
                ],
                'curl' => ['errno' => 7, 'error' => $e->getMessage()],
                'error' => new RingException($e->getMessage()),
            ]);
        } finally {
            if ($client !== null) {
                $client->close();
            }
        }
    }

    /**
     * Swoole client settings for the RingPHP "client" options.
     */
    public static function clientSettings(string $host, bool $ssl, array $clientOpts): array {
        // cURL semantics: 0 / absent means no limit -> Swoole -1.
        $timeout = floatval($clientOpts['timeout'] ?? 0);
        $connectTimeout = floatval($clientOpts['connect_timeout'] ?? 0);
        $settings = [
            'timeout' => $timeout > 0 ? $timeout : -1,
            'connect_timeout' => $connectTimeout > 0 ? $connectTimeout : -1,
        ];

        if ($ssl) {
            $curl = is_array($clientOpts['curl'] ?? null) ? $clientOpts['curl'] : [];
            $verify = $clientOpts['verify'] ?? null;
            if ($verify === null) {
                $caKey = defined('CURLOPT_CAINFO') ? CURLOPT_CAINFO : 10065;
                $peerKey = defined('CURLOPT_SSL_VERIFYPEER') ? CURLOPT_SSL_VERIFYPEER : 64;
                $verify = isset($curl[$peerKey]) && !$curl[$peerKey] ? false : ($curl[$caKey] ?? true);
            }
            $settings += self::sslSettings($host, $verify);
        }

        $proxy = $clientOpts['proxy'] ?? null;
        if (is_string($proxy) && $proxy !== '') {
            $settings += self::proxySettings($proxy);
        }

        return $settings;
    }

    private static function sslSettings(string $host, mixed $verify): array {
        if ($verify === false || $verify === 0 || $verify === '0') {
            return ['ssl_verify_peer' => false];
        }

        $settings = [
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_host_name' => $host,
        ];

        $caFile = is_string($verify) && $verify !== '' && $verify !== '1' ? $verify : self::defaultCaFile();
        if ($caFile !== null) {
            $settings['ssl_cafile'] = $caFile;
        }

        return $settings;
    }

    private static function defaultCaFile(): ?string {
        $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
        foreach ([getenv('SSL_CERT_FILE') ?: null, $locations['default_cert_file'] ?? null,
                     '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $file) {
            if (is_string($file) && is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    private static function proxySettings(string $proxy): array {
        $parts = parse_url(str_contains($proxy, '://') ? $proxy : 'http://' . $proxy);
        if (!is_array($parts) || empty($parts['host'])) {
            return [];
        }

        $settings = [
            'http_proxy_host' => $parts['host'],
            'http_proxy_port' => intval($parts['port'] ?? 80),
        ];
        if (isset($parts['user'])) {
            $settings['http_proxy_user'] = rawurldecode($parts['user']);
            $settings['http_proxy_password'] = rawurldecode($parts['pass'] ?? '');
        }

        return $settings;
    }
}
