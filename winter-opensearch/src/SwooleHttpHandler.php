<?php
declare(strict_types=1);

namespace Winter\OpenSearch;

use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Exception\ConnectException;
use GuzzleHttp\Ring\Exception\RingException;
use GuzzleHttp\Ring\Future\CompletedFutureArray;
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
 */
class SwooleHttpHandler {
    public function __invoke(array $request) {
        $client = null;
        try {
            $uri = Core::url($request);
            $parts = parse_url($uri);

            $scheme = strtolower($parts['scheme'] ?? ($request['scheme'] ?? 'http'));
            $ssl = $scheme === 'https';
            $host = $parts['host'] ?? '';
            $port = $parts['port'] ?? ($ssl ? 443 : 80);

            $path = $parts['path'] ?? '/';
            if ($path === '') {
                $path = '/';
            }
            if (!empty($parts['query'])) {
                $path .= '?' . $parts['query'];
            }

            $client = new \Swoole\Coroutine\Http\Client($host, (int) $port, $ssl);

            $timeout = floatval($request['client']['timeout'] ?? 10.0);
            $connectTimeout = floatval($request['client']['connect_timeout'] ?? 5.0);
            $client->set([
                'timeout' => $timeout > 0 ? $timeout : 10.0,
                'connect_timeout' => $connectTimeout > 0 ? $connectTimeout : 5.0,
            ]);

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
                'transfer_stats' => [],
            ]);
        } catch (Throwable $e) {
            return new CompletedFutureArray([
                'status' => null,
                'headers' => [],
                'body' => null,
                'error' => new RingException($e->getMessage()),
            ]);
        } finally {
            if ($client !== null) {
                $client->close();
            }
        }
    }
}
