<?php
declare(strict_types=1);

/**
 * php winter-dtce/srcTest/DtceTest.php
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\dtce\task\server\TaskServer;
use dev\winterframework\dtce\task\storage\TaskIOStorageDisk;
use dev\winterframework\dtce\task\storage\TaskQueueShared;
use dev\winterframework\dtce\task\TaskResult;
use dev\winterframework\dtce\task\worker\AbstractTaskWorker;
use dev\winterframework\dtce\task\worker\output\NullOutput;
use dev\winterframework\dtce\task\worker\TaskOutput;
use dev\winterframework\util\SerializationUtil;

final class NoopTaskWorker extends AbstractTaskWorker {
    public function work(mixed $input): TaskOutput {
        return new NullOutput();
    }
}

function taskServer(array $serverArgs): array {
    $ws = (new ReflectionClass(WinterServer::class))->newInstanceWithoutConstructor();
    $ws->setServerArgs($serverArgs);
    $ts = new TaskServer(T::stub(ApplicationContext::class), $ws, ['tasks' => [[
        'name' => 'resize',
        'worker.class' => NoopTaskWorker::class,
        'worker.total' => 2,
        'storage.handler' => TaskIOStorageDisk::class,
        'queue.handler' => TaskQueueShared::class,
    ]]]);
    $map = (new ReflectionProperty($ts, 'workerTaskMap'))->getValue($ts);
    return [$ws, array_keys($map)];
}

echo "DtceTest\n";

T::test('task worker ids follow the configured worker_num', function () {
    [$ws, $ids] = taskServer(['worker_num' => 3]);
    T::eq([3, 4], $ids);
    T::eq(2, $ws->getServerArg('task_worker_num'));
});

T::test('without worker_num, it is pinned to the CPU count (Swoole default)', function () {
    [$ws, $ids] = taskServer([]);
    $n = swoole_cpu_num();
    T::eq($n, $ws->getServerArg('worker_num'));
    T::eq([$n, $n + 1], $ids, 'task worker ids start after the HTTP workers');
});

T::test('stored task output honours allowedClasses', function () {
    $result = (new ReflectionClass(TaskResult::class))->newInstanceWithoutConstructor();
    $storage = T::stub(dev\winterframework\dtce\task\storage\TaskIOStorageHandler::class, [
        'getInputStream' => fn() => new dev\winterframework\io\stream\StringInputStream(serialize(new NullOutput())),
    ]);
    foreach (['dataId' => 'x', 'storage' => $storage] as $prop => $val) {
        (new ReflectionProperty($result, $prop))->setValue($result, $val);
    }
    SerializationUtil::setAllowedClasses([]);
    try {
        $out = $result->getResult();
        T::true(!($out instanceof NullOutput), 'disallowed class must not be rebuilt');
    } finally {
        SerializationUtil::setAllowedClasses(true);
    }
});

T::done();
