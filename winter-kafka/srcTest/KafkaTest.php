<?php
declare(strict_types=1);

/**
 * Unit part runs anywhere (needs ext-rdkafka):
 *   php winter-kafka/srcTest/KafkaTest.php
 *
 * Broker part runs when KAFKA_TEST_BROKERS is set, against a disposable broker:
 *   KAFKA_TEST_BROKERS=localhost:19092 php winter-kafka/srcTest/KafkaTest.php
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\kafka\consumer\AbstractConsumer;
use dev\winterframework\kafka\consumer\Consumer;
use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\consumer\ConsumerRecords;
use dev\winterframework\kafka\KafkaServiceImpl;
use dev\winterframework\kafka\KafkaWorkerProcess;
use dev\winterframework\kafka\producer\ProducerConfiguration;

final class CountingConsumer extends AbstractConsumer {
    public int $calls = 0;
    public ?Throwable $fail = null;
    public int $failTimes = PHP_INT_MAX;

    public static function of(?Throwable $fail = null, int $failTimes = PHP_INT_MAX): self {
        $c = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $c->fail = $fail;
        $c->failTimes = $failTimes;
        return $c;
    }

    public function consume(ConsumerRecords $records): void {
        $this->calls++;
        if ($this->fail && $this->calls <= $this->failTimes) {
            throw $this->fail;
        }
    }
}

function ctxStub(): ApplicationContext {
    return T::stub(ApplicationContext::class, ['hasBeanByClass' => fn() => false]);
}

function workerFor(ConsumerConfiguration $cfg): KafkaWorkerProcess {
    $w = (new ReflectionClass(KafkaWorkerProcess::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($w, 'consumer'))->setValue($w, $cfg);
    (new ReflectionProperty($w, 'workerId'))->setValue($w, 1);
    return $w;
}

function deliver(KafkaWorkerProcess $w, Consumer $c): bool {
    $m = new ReflectionMethod($w, 'consumerRecords');
    return $m->invoke($w, new ConsumerRecords(), $c);
}

echo "KafkaTest\n";

$retryCfg = new ConsumerConfiguration([
    'name' => 'g1', 'topic' => 't1', 'retries' => 3, 'retryWaitMs' => 1,
    'transientExceptions' => [RuntimeException::class],
], ctxStub());

T::test('a consumed batch is delivered once (was retries times)', function () use ($retryCfg) {
    $c = CountingConsumer::of();
    T::eq(true, deliver(workerFor($retryCfg), $c));
    T::eq(1, $c->calls);
});

T::test('non-transient failure is not retried', function () use ($retryCfg) {
    $c = CountingConsumer::of(new LogicException('bad'));
    T::eq(false, deliver(workerFor($retryCfg), $c));
    T::eq(1, $c->calls);
});

T::test('transient failure is retried until it succeeds', function () use ($retryCfg) {
    $c = CountingConsumer::of(new RuntimeException('flaky'), 2);
    T::eq(true, deliver(workerFor($retryCfg), $c));
    T::eq(3, $c->calls);
});

T::test('process id is unique per consumer', function () {
    $a = workerFor(new ConsumerConfiguration(['name' => 'orders', 'topic' => 't'], ctxStub()));
    $b = workerFor(new ConsumerConfiguration(['name' => 'payments', 'topic' => 't'], ctxStub()));
    T::true($a->getProcessId() !== $b->getProcessId());
});

T::test('consumers without topics get no worker process', function () {
    $svc = new KafkaServiceImpl();
    // An uninitialised WinterServer: any addProcess() call would fail.
    $ws = (new ReflectionClass(WinterServer::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($svc, 'wServer'))->setValue($svc, $ws);
    $svc->addConsumer(new ConsumerConfiguration(['name' => 'empty', 'workerClass' => 'X'], ctxStub()));
    $svc->beginConsume();
    T::true(true);
});

T::test('transactional.id is unique per host and process', function () {
    $p = new ProducerConfiguration(['name' => 'tx', 'topic' => 't', 'transactionEnabled' => true,
        'metadata.broker.list' => 'localhost:1'], ctxStub());
    $m = new ReflectionMethod($p, 'buildTransactionalId');
    T::eq('TRANSACTION-tx-' . gethostname() . '-p' . getmypid(), $m->invoke($p));
});

$brokers = getenv('KAFKA_TEST_BROKERS');
if ($brokers) {
    $topic = 'winter-test-' . bin2hex(random_bytes(4));

    T::test('broker: transactional producer sends repeatedly (initTransactions once)', function () use ($brokers, $topic) {
        $svc = new KafkaServiceImpl();
        $svc->addProducer(new ProducerConfiguration([
            'name' => 'txp', 'topic' => $topic, 'transactionEnabled' => true,
            'metadata.broker.list' => $brokers,
        ], ctxStub()));
        for ($i = 0; $i < 3; $i++) {
            T::eq(true, $svc->produce('txp', "m$i", "k$i"));
        }
    });

    T::test('broker: non-transactional producer', function () use ($brokers, $topic) {
        $svc = new KafkaServiceImpl();
        $svc->addProducer(new ProducerConfiguration([
            'name' => 'p', 'topic' => $topic, 'metadata.broker.list' => $brokers,
        ], ctxStub()));
        T::eq(true, $svc->produce('p', 'plain', 'k'));
    });

    T::test('broker: committed messages are readable (read_committed)', function () use ($brokers, $topic) {
        $conf = new RdKafka\Conf();
        $conf->set('metadata.broker.list', $brokers);
        $conf->set('group.id', 'winter-test-reader');
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('isolation.level', 'read_committed');
        $c = new RdKafka\KafkaConsumer($conf);
        $c->subscribe([$topic]);
        $got = [];
        $deadline = microtime(true) + 30;
        while (count($got) < 4 && microtime(true) < $deadline) {
            $msg = $c->consume(1000);
            if ($msg->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
                $got[] = $msg->payload;
            }
        }
        $c->close();
        sort($got);
        T::eq(['m0', 'm1', 'm2', 'plain'], $got);
    });
} else {
    echo "  (broker tests skipped: set KAFKA_TEST_BROKERS)\n";
}

T::done();
