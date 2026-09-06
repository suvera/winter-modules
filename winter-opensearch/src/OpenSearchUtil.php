<?php
declare(strict_types=1);

namespace dev\winterframework\opensearch;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Ring\Core;
use OpenSearch\ClientBuilder;

class OpenSearchUtil {

    private static array $clients = [];

    public static function buildClient(array $config): \OpenSearch\Client {
        $key = md5(serialize($config));

        if (isset(self::$clients[$key])) {
            return self::$clients[$key];
        }

        $clientBuilder = ClientBuilder::create();

        if (isset($config['hosts']) && is_array($config['hosts'])) {
            $clientBuilder->setHosts($config['hosts']);
        }

        $handler = $config['http_handler']
            ?? $config['handler']
            ?? (extension_loaded('swoole') ? new SwooleHttpHandler() : null);

        // Wrap the handler with AWS SigV4 signing when "aws" config is present.
        // This is required for Amazon OpenSearch Service (managed OpenSearch on AWS),
        // which requires every request to be signed with AWS credentials instead of
        // (or in addition to) basic auth.
        if (isset($config['aws']) && is_array($config['aws'])) {
            $handler = self::wrapWithAwsSigning($handler, $config['aws']);
        }

        if ($handler !== null) {
            $clientBuilder->setHandler($handler);
        }

        if (isset($config['username']) && isset($config['password'])) {
            $clientBuilder->setBasicAuthentication($config['username'], $config['password']);
        }

        if (isset($config['connection_params']) && is_array($config['connection_params'])) {
            $clientBuilder->setConnectionParams($config['connection_params']);
        }

        if (isset($config['retries'])) {
            $clientBuilder->setRetries((int) $config['retries']);
        }

        if (isset($config['ssl_verification'])) {
            $clientBuilder->setSSLVerification($config['ssl_verification']);
        }

        if (isset($config['sniff_on_start'])) {
            $clientBuilder->setSniffOnStart((bool) $config['sniff_on_start']);
        }

        $client = $clientBuilder->build();

        self::$clients[$key] = $client;

        return $client;
    }

    /**
     * Wrap a RingPHP handler so every outgoing request is signed with
     * AWS Signature Version 4 before being dispatched to the underlying
     * handler (cURL, or SwooleHttpHandler when running under Swoole).
     *
     * $awsConfig keys:
     *   - region (required): e.g. "us-east-1"
     *   - service (optional): defaults to "es" (Amazon OpenSearch Service).
     *                          Use "aoss" for Amazon OpenSearch Serverless.
     *   - credentials (optional): array [key, secret, token] or
     *                              Aws\Credentials\CredentialsInterface.
     *                              Falls back to the default AWS credential
     *                              provider chain (env vars, IAM role, etc.)
     *                              when omitted.
     */
    public static function wrapWithAwsSigning(?callable $handler, array $awsConfig): callable {
        if ($handler === null) {
            $handler = ClientBuilder::defaultHandler();
        }

        $region = $awsConfig['region'] ?? null;
        if (empty($region)) {
            throw new \InvalidArgumentException('opensearch-config "aws" section requires "region"');
        }
        $service = $awsConfig['service'] ?? 'es';

        $credentials = self::resolveAwsCredentials($awsConfig['credentials'] ?? null);

        $signer = new SignatureV4($service, $region);

        return function (array $request) use ($handler, $signer, $credentials) {
            $psrRequest = self::toPsrRequest($request);

            $creds = $credentials instanceof CredentialsInterface
                ? $credentials
                : $credentials();

            $signedRequest = $signer->signRequest($psrRequest, $creds);

            // Merge signed headers (Authorization, X-Amz-Date, X-Amz-Security-Token)
            // back into the RingPHP request array.
            foreach ($signedRequest->getHeaders() as $name => $values) {
                $request['headers'][$name] = $values;
            }

            return $handler($request);
        };
    }

    private static function resolveAwsCredentials(mixed $credentials): CredentialsInterface|callable {
        if ($credentials instanceof CredentialsInterface) {
            return $credentials;
        }

        if (is_array($credentials) && isset($credentials['key'], $credentials['secret'])) {
            return new Credentials(
                $credentials['key'],
                $credentials['secret'],
                $credentials['token'] ?? null
            );
        }

        // Falls back to env vars / shared config / IAM instance profile / IRSA, etc.
        return CredentialProvider::defaultProvider();
    }

    private static function toPsrRequest(array $request): Psr7Request {
        $uri = Core::url($request);
        $headers = [];
        foreach (($request['headers'] ?? []) as $name => $values) {
            $headers[$name] = (array) $values;
        }

        return new Psr7Request(
            $request['http_method'] ?? 'GET',
            $uri,
            $headers,
            Core::body($request)
        );
    }

    public static function stringify(mixed $message): string {
        if (is_string($message)) {
            return $message;
        }

        if (is_array($message) || is_object($message)) {
            return json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return print_r($message, true);
    }
}
