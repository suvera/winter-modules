<?php
declare(strict_types=1);

/**
 * Needs opensearch-project/opensearch-php (composer install, or EXTRA_AUTOLOAD):
 *   php winter-opensearch/srcTest/OpenSearchTest.php
 *
 * Talks to a local Swoole HTTPS server with a throw-away self-signed CA.
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use dev\winterframework\opensearch\OpenSearchUtil;
use dev\winterframework\opensearch\SwooleHttpHandler;
use GuzzleHttp\Ring\Future\CompletedFutureArray;

if (!class_exists(OpenSearch\ClientBuilder::class)) {
    echo "OpenSearchTest skipped (opensearch-php not autoloadable; set EXTRA_AUTOLOAD)\n";
    exit(0);
}

function osCerts(): array {
    $dir = sys_get_temp_dir() . '/winter-os-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $cfg = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $caKey = openssl_pkey_new($cfg);
    $caCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'winter test CA'], $caKey, $cfg),
        null, $caKey, 1, $cfg + ['x509_extensions' => 'v3_ca']);
    $key = openssl_pkey_new($cfg);
    file_put_contents("$dir/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=DNS:localhost\n");
    $c = $cfg + ['config' => "$dir/ext.cnf"];
    $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $key, $c),
        $caCert, $caKey, 1, $c + ['x509_extensions' => 'ext'], 2);
    openssl_x509_export_to_file($caCert, "$dir/ca.pem");
    openssl_x509_export_to_file($cert, "$dir/cert.pem");
    openssl_pkey_export_to_file($key, "$dir/key.pem");
    return ['dir' => $dir, 'ca' => "$dir/ca.pem", 'cert' => "$dir/cert.pem", 'key' => "$dir/key.pem"];
}

function withOsServer(array $certs, callable $fn): void {
    Co\run(function () use ($certs, $fn) {
        $server = new Swoole\Coroutine\Http\Server('127.0.0.1', 0, true);
        $server->set(['ssl_cert_file' => $certs['cert'], 'ssl_key_file' => $certs['key']]);
        $server->handle('/', function ($req, $resp) {
            $resp->header('Content-Type', 'application/json');
            $resp->end('{"version":{"number":"2.11.0","distribution":"opensearch"}}');
        });
        go(fn() => $server->start());
        try {
            $fn($server->port);
        } finally {
            $server->shutdown();
        }
    });
}

function osClient(int $port, mixed $verify): OpenSearch\Client {
    $b = OpenSearch\ClientBuilder::create()
        ->setHosts(["https://localhost:$port"])
        ->setHandler(new SwooleHttpHandler())
        ->setRetries(0);
    if ($verify !== null) {
        $b->setSSLVerification($verify);
    }
    return $b->build();
}

echo "OpenSearchTest\n";

T::test('timeouts: 0/absent means no limit', function () {
    T::eq(-1, SwooleHttpHandler::clientSettings('h', false, [])['timeout']);
    T::eq(7.0, SwooleHttpHandler::clientSettings('h', false, ['timeout' => 7])['timeout']);
});

T::test('outside a coroutine the cURL handler is used', function () {
    $h = new SwooleHttpHandler();
    $used = false;
    (new ReflectionProperty($h, 'fallback'))->setValue($h, function () use (&$used) {
        $used = true;
        return new CompletedFutureArray(['status' => 204, 'headers' => [], 'body' => null]);
    });
    $h(['http_method' => 'GET', 'scheme' => 'http', 'uri' => '/', 'headers' => ['Host' => ['x']]]);
    T::true($used);
});

T::test('top-level timeout/proxy config reaches connection params', function () {
    $client = OpenSearchUtil::buildClient(['hosts' => ['http://localhost:9200'], 'timeout' => 3,
        'http_handler' => new SwooleHttpHandler()]);
    $conn = $client->transport->getConnection();
    $params = (new ReflectionProperty($conn, 'connectionParams'))->getValue($conn);
    T::eq(3, $params['client']['timeout'] ?? null);
});

$certs = osCerts();

T::test('TLS: untrusted certificate rejected by default', function () use ($certs) {
    withOsServer($certs, function (int $port) {
        T::throws(Throwable::class, fn() => osClient($port, null)->info());
    });
});

T::test('TLS: setSSLVerification(CA path) is honoured', function () use ($certs) {
    withOsServer($certs, function (int $port) use ($certs) {
        T::eq('2.11.0', osClient($port, $certs['ca'])->info()['version']['number']);
    });
});

T::test('TLS: setSSLVerification(false) is honoured', function () use ($certs) {
    withOsServer($certs, function (int $port) {
        T::eq('2.11.0', osClient($port, false)->info()['version']['number']);
    });
});

array_map('unlink', glob($certs['dir'] . '/*'));
rmdir($certs['dir']);

T::done();
