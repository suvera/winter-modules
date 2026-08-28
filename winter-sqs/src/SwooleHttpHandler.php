<?php
declare(strict_types=1);

namespace dev\winterframework\sqs;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
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
 */
class SwooleHttpHandler {
    public function __invoke(RequestInterface $request, array $options = []) {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $ssl = $scheme === 'https';
        $host = $uri->getHost();
        $port = $uri->getPort();
        if ($port === null || $port === 0) {
            $port = $ssl ? 443 : 80;
        }

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);

        $timeout = floatval($options['timeout'] ?? 10.0);
        $connectTimeout = floatval($options['connect_timeout'] ?? 5.0);
        $client->set([
            'timeout' => $timeout > 0 ? $timeout : 10.0,
            'connect_timeout' => $connectTimeout > 0 ? $connectTimeout : 5.0,
        ]);

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
                $errMsg = $client->errMsg ?: 'Swoole HTTP request failed';
                return Create::rejectionFor([
                    'exception' => new \RuntimeException($errMsg, intval($client->errCode)),
                    'connection_error' => true,
                ]);
            }

            $statusCode = $client->statusCode;

            $responseHeaders = [];
            foreach ($client->headers ?? [] as $name => $value) {
                $responseHeaders[$name] = is_array($value) ? $value : [$value];
            }

            return Create::promiseFor(new Response(
                $statusCode,
                $responseHeaders,
                $client->body ?? '',
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
}
