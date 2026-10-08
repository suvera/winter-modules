<?php
declare(strict_types=1);

namespace dev\winterframework\s3;

use Aws\Credentials\CredentialProvider;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Swoole\Coroutine;
use Throwable;

/**
 * AWS SDK "http_handler" implementation backed by Swoole's coroutine HTTP client.
 *
 * The AWS SDK (via WrappedHttpHandler) expects a callable with the signature:
 *     function(RequestInterface $request, array $options): PromiseInterface
 *
 * The promise must resolve to a PSR-7 Response on success, or reject with an
 * error array containing at least an "exception" key (and optionally
 * "connection_error" => true).
 *
 * Using Swoole\Coroutine\Http\Client avoids Guzzle's default cURL handler,
 * which fails under Swoole's curl hook (SWOOLE_HOOK_ALL) because
 * CURLOPT_PROTOCOLS_STR is not supported by swoole_curl_setopt().
 *
 * Outside a coroutine (module boot, CLI tools, tests) the SDK's default
 * cURL handler is used instead: the coroutine client cannot run there, and
 * cURL is not hooked outside coroutines.
 *
 * Honoured request options (the SDK's "http" config): timeout and
 * connect_timeout (0 = no limit, as with Guzzle), verify (bool or CA bundle
 * path; peer verification is ON by default), proxy, sink and delay. Request
 * and response bodies are buffered in memory.
 */
class SwooleHttpHandler {
    /** @var callable|null */
    private $fallback = null;

    /**
     * Apply Swoole HTTP handling to an AWS SDK client configuration.
     *
     * Sets `http_handler` (signed API requests) and, when the app relies on
     * the default credential chain (no explicit `credentials`), builds a
     * default credential provider whose IMDS/ECS HTTP fetches use the same
     * Swoole handler via the `client` option understood by
     * InstanceProfileProvider and EcsCredentialProvider.
     *
     * Without the `client` wiring, credential fetching falls back to
     * Guzzle/cURL, which fails under Swoole's cURL hook (SWOOLE_HOOK_ALL)
     * with "Unable to set cURL option CURLOPT_PROTOCOLS_STR (10318)".
     */
    public static function applyToClientConfig(array $config): array {
        if (!extension_loaded('swoole')) {
            return $config;
        }

        $httpHandler = $config['http_handler'] ?? new self();
        $config['http_handler'] = $httpHandler;

        if (!isset($config['credentials'])) {
            $config['credentials'] = CredentialProvider::defaultProvider(
                array_merge($config, ['client' => $httpHandler])
            );
        }

        return $config;
    }

    public static function inCoroutine(): bool {
        return extension_loaded('swoole') && Coroutine::getCid() > 0;
    }

    public function __invoke(RequestInterface $request, array $options = []) {
        if (!self::inCoroutine()) {
            $this->fallback ??= \Aws\default_http_handler();
            return ($this->fallback)($request, $options);
        }

        if (!empty($options['delay'])) {
            Coroutine::sleep(intval($options['delay']) / 1000);
        }

        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $ssl = $scheme === 'https';
        $host = $uri->getHost();
        $port = $uri->getPort();
        if ($port === null || $port === 0) {
            $port = $ssl ? 443 : 80;
        }

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);
        $client->set(self::clientSettings($host, $ssl, $scheme, $options));

        // Build headers, skipping hop-by-hop headers Swoole manages itself.
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        unset(
            $headers['Content-Length'],
            $headers['Transfer-Encoding'],
            $headers['Connection'],
            $headers['Expect']
        );
        $client->setHeaders($headers);

        $client->setMethod($request->getMethod());

        $body = (string) $request->getBody();
        if ($body !== '') {
            $client->setData($body);
        }

        $path = $uri->getPath();
        if ($path === '') {
            $path = '/';
        }
        if ($uri->getQuery() !== '') {
            $path .= '?' . $uri->getQuery();
        }

        try {
            $ok = $client->execute($path);
            if (!$ok) {
                // Include method + URI so failures identify the target
                // (e.g. IMDS vs the S3 endpoint) in SDK error messages.
                $errMsg = ($client->errMsg ?: 'Swoole HTTP request failed')
                    . ' (' . $request->getMethod() . ' ' . (string) $uri . ')';
                return Create::rejectionFor([
                    'exception' => new \RuntimeException($errMsg, intval($client->errCode)),
                    'connection_error' => true,
                ]);
            }

            $responseHeaders = [];
            foreach ($client->headers ?? [] as $name => $value) {
                $responseHeaders[$name] = is_array($value) ? $value : [$value];
            }

            return Create::promiseFor(new Response(
                $client->statusCode,
                $responseHeaders,
                self::responseBody($client->body ?? '', $options['sink'] ?? null),
                '1.1'
            ));
        } catch (Throwable $e) {
            return Create::rejectionFor([
                'exception' => $e,
                'connection_error' => true,
            ]);
        } finally {
            $client->close();
        }
    }

    /**
     * Swoole client settings for the SDK request options.
     */
    public static function clientSettings(string $host, bool $ssl, string $scheme, array $options): array {
        // Guzzle semantics: 0 (the SDK default) means no limit -> Swoole -1.
        // A fixed fallback would cut off large or slow S3 transfers.
        $timeout = floatval($options['timeout'] ?? 0);
        $connectTimeout = floatval($options['connect_timeout'] ?? 0);
        $settings = [
            'timeout' => $timeout > 0 ? $timeout : -1,
            'connect_timeout' => $connectTimeout > 0 ? $connectTimeout : -1,
        ];

        if ($ssl) {
            $settings += self::sslSettings($host, $options['verify'] ?? true);
        }

        $proxy = $options['proxy'] ?? null;
        if (is_array($proxy)) {
            $proxy = $proxy[$scheme] ?? null;
        }
        if (is_string($proxy) && $proxy !== '') {
            $settings += self::proxySettings($proxy);
        }

        return $settings;
    }

    private static function sslSettings(string $host, mixed $verify): array {
        if ($verify === false) {
            return ['ssl_verify_peer' => false];
        }

        $settings = [
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_host_name' => $host,
        ];

        $caFile = is_string($verify) && $verify !== '' ? $verify : self::defaultCaFile();
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

    /**
     * The response body, written to the "sink" option (file path, resource
     * or stream) when one is given, e.g. S3 getObject with SaveAs.
     */
    private static function responseBody(string $body, mixed $sink): StreamInterface|string {
        if ($sink === null) {
            return $body;
        }

        $stream = is_string($sink) ? Utils::streamFor(Utils::tryFopen($sink, 'w+')) : Utils::streamFor($sink);
        $stream->write($body);
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream;
    }
}
