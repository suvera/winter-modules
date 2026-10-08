<?php
declare(strict_types=1);

/**
 * Needs aws/aws-sdk-php (composer install, or EXTRA_AUTOLOAD):
 *   php winter-s3/srcTest/S3Test.php
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use Aws\Credentials\Credentials;
use dev\winterframework\s3\S3Util;
use dev\winterframework\s3\SwooleHttpHandler;
use GuzzleHttp\Promise\Create;

if (!class_exists(Aws\S3\S3Client::class)) {
    echo "S3Test skipped (aws/aws-sdk-php not autoloadable; set EXTRA_AUTOLOAD)\n";
    exit(0);
}

echo "S3Test\n";

T::test('module sources do not depend on winter-sqs', function () {
    foreach (glob(__DIR__ . '/../src/*.php') as $file) {
        T::true(!str_contains(file_get_contents($file), 'winterframework\\sqs'), basename($file));
    }
});

T::test('dotted config keys are reassembled', function () {
    [$creds, $rest] = S3Util::extractDottedValues(
        ['region' => 'x', 'credentials.key' => 'k', 'credentials.secret' => 's'],
        'credentials'
    );
    T::eq(['key' => 'k', 'secret' => 's'], $creds);
    T::eq(['region' => 'x'], $rest);
});

T::test('client config holding a closure builds (was serialize() exception)', function () {
    $client = S3Util::buildClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => fn() => Create::promiseFor(new Credentials('k', 's')),
    ]);
    T::true($client instanceof Aws\S3\S3Client);
});

T::test('S3 clients get the S3 module\'s own Swoole handler', function () {
    $cfg = SwooleHttpHandler::applyToClientConfig(['credentials' => ['key' => 'k', 'secret' => 's']]);
    T::true($cfg['http_handler'] instanceof SwooleHttpHandler);
});

T::done();
