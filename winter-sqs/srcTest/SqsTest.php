<?php
declare(strict_types=1);

/**
 * Needs aws/aws-sdk-php (composer install, or EXTRA_AUTOLOAD pointing at a
 * vendor/autoload.php that has it):
 *
 *   php winter-sqs/srcTest/SqsTest.php
 *
 * The HTTP handler tests talk to a local Swoole HTTPS server with a
 * throw-away self-signed CA; no AWS traffic.
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\sqs\consumer\AbstractConsumer;
use dev\winterframework\sqs\consumer\ConsumerConfiguration;
use dev\winterframework\sqs\consumer\ConsumerRecords;
use dev\winterframework\sqs\SqsWorkerProcess;
use dev\winterframework\sqs\SwooleHttpHandler;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

if (!class_exists(GuzzleHttp\Psr7\Request::class) || !function_exists('Aws\default_http_handler')) {
    echo "SqsTest skipped (aws/aws-sdk-php not autoloadable; set EXTRA_AUTOLOAD)\n";
    exit(0);
}

final class FlakyConsumer extends AbstractConsumer {
    public int $calls = 0;
    public bool $fail = false;

    public function consume(ConsumerRecords $records): void {
        $this->calls++;
        if ($this->fail) {
            throw new LogicException('boom');
        }
    }
}

final class RecordingWorker extends SqsWorkerProcess {
    public int $deleted = 0;

    protected function deleteRecords(ConsumerRecords $records): void {
        $this->deleted++;
    }
}

function sqsCtx(): ApplicationContext {
    return T::stub(ApplicationContext::class);
}

function sqsWorker(string $name): RecordingWorker {
    $cfg = new ConsumerConfiguration(['name' => $name, 'retries' => 2, 'retryWaitMs' => 1], sqsCtx());
    $w = (new ReflectionClass(RecordingWorker::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(SqsWorkerProcess::class, 'consumer'))->setValue($w, $cfg);
    (new ReflectionProperty(SqsWorkerProcess::class, 'workerId'))->setValue($w, 1);
    return $w;
}

function batch(RecordingWorker $w, FlakyConsumer $c): void {
    (new ReflectionMethod(SqsWorkerProcess::class, 'handleBatch'))->invoke($w, new ConsumerRecords(), $c);
}

/** Self-signed CA + "localhost" server cert in a temp dir. */
function makeCerts(): array {
    $dir = sys_get_temp_dir() . '/winter-sqs-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $cfg = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $caKey = openssl_pkey_new($cfg);
    $caCsr = openssl_csr_new(['commonName' => 'winter test CA'], $caKey, $cfg);
    $caCert = openssl_csr_sign($caCsr, null, $caKey, 1, $cfg + ['x509_extensions' => 'v3_ca']);
    $key = openssl_pkey_new($cfg);
    $extFile = "$dir/ext.cnf";
    file_put_contents($extFile, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=DNS:localhost\n");
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $cfg + ['config' => $extFile]);
    $cert = openssl_csr_sign($csr, $caCert, $caKey, 1, $cfg + ['config' => $extFile, 'x509_extensions' => 'ext'], 2);
    openssl_x509_export_to_file($caCert, "$dir/ca.pem");
    openssl_x509_export_to_file($cert, "$dir/cert.pem");
    openssl_pkey_export_to_file($key, "$dir/key.pem");
    return ['dir' => $dir, 'ca' => "$dir/ca.pem", 'cert' => "$dir/cert.pem", 'key' => "$dir/key.pem"];
}

/** Run $fn(port) in a coroutine next to a local HTTPS server answering "hello". */
function withHttpsServer(array $certs, callable $fn): void {
    Co\run(function () use ($certs, $fn) {
        $server = new Swoole\Coroutine\Http\Server('127.0.0.1', 0, true);
        $server->set(['ssl_cert_file' => $certs['cert'], 'ssl_key_file' => $certs['key']]);
        $server->handle('/', function ($req, $resp) {
            $resp->end('hello');
        });
        go(fn() => $server->start());
        try {
            $fn($server->port);
        } finally {
            $server->shutdown();
        }
    });
}

function send(string $url, array $options): array {
    $handler = new SwooleHttpHandler();
    try {
        /** @var ResponseInterface $resp */
        $resp = $handler(new Request('GET', $url), $options)->wait();
        return ['ok', $resp->getStatusCode(), (string)$resp->getBody()];
    } catch (Throwable $e) {
        return ['error', $e];
    }
}

echo "SqsTest\n";

T::test('consumed batch is deleted', function () {
    $w = sqsWorker('q');
    $c = (new ReflectionClass(FlakyConsumer::class))->newInstanceWithoutConstructor();
    batch($w, $c);
    T::eq(1, $c->calls);
    T::eq(1, $w->deleted);
});

T::test('failed batch is NOT deleted (stays for redelivery / DLQ)', function () {
    $w = sqsWorker('q');
    $c = (new ReflectionClass(FlakyConsumer::class))->newInstanceWithoutConstructor();
    $c->fail = true;
    batch($w, $c);
    T::eq(1, $c->calls, 'non-transient error is not retried');
    T::eq(0, $w->deleted);
});

T::test('process id is unique per consumer', function () {
    T::true(sqsWorker('a')->getProcessId() !== sqsWorker('b')->getProcessId());
});

T::test('timeouts: 0 means no limit, explicit values kept', function () {
    $s = SwooleHttpHandler::clientSettings('h', false, 'http', []);
    T::eq(-1, $s['timeout']);
    $s = SwooleHttpHandler::clientSettings('h', false, 'http', ['timeout' => 25, 'connect_timeout' => 3]);
    T::eq(25.0, $s['timeout']);
    T::eq(3.0, $s['connect_timeout']);
});

T::test('proxy option maps to Swoole proxy settings', function () {
    $s = SwooleHttpHandler::clientSettings('h', true, 'https',
        ['proxy' => ['https' => 'http://u%40x:p@proxy.local:3128']]);
    T::eq('proxy.local', $s['http_proxy_host']);
    T::eq(3128, $s['http_proxy_port']);
    T::eq('u@x', $s['http_proxy_user']);
});

T::test('outside a coroutine the default (cURL) handler is used', function () {
    $handler = new SwooleHttpHandler();
    $used = false;
    (new ReflectionProperty($handler, 'fallback'))->setValue($handler, function () use (&$used) {
        $used = true;
        return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(204));
    });
    T::eq(204, $handler(new Request('GET', 'https://example.invalid/'), [])->wait()->getStatusCode());
    T::true($used);
});

$certs = makeCerts();

T::test('TLS: untrusted certificate is rejected by default', function () use ($certs) {
    withHttpsServer($certs, function (int $port) {
        $r = send("https://localhost:$port/", []);
        T::eq('error', $r[0], 'self-signed server must not be trusted');
    });
});

T::test('TLS: trusted via "verify" CA bundle path', function () use ($certs) {
    withHttpsServer($certs, function (int $port) use ($certs) {
        $r = send("https://localhost:$port/", ['verify' => $certs['ca']]);
        T::eq(['ok', 200, 'hello'], $r);
    });
});

T::test('TLS: verify=false still works when explicitly asked', function () use ($certs) {
    withHttpsServer($certs, function (int $port) {
        T::eq(['ok', 200, 'hello'], send("https://localhost:$port/", ['verify' => false]));
    });
});

T::test('sink option receives the body', function () use ($certs) {
    $file = $certs['dir'] . '/sink.txt';
    withHttpsServer($certs, function (int $port) use ($certs, $file) {
        $r = send("https://localhost:$port/", ['verify' => $certs['ca'], 'sink' => $file]);
        T::eq('ok', $r[0]);
    });
    T::eq('hello', file_get_contents($file));
});

array_map('unlink', glob($certs['dir'] . '/*'));
rmdir($certs['dir']);

T::done();
