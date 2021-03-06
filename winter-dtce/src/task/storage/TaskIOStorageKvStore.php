<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\io\kv\KvTemplate;
use dev\winterframework\io\stream\InputStream;
use dev\winterframework\io\stream\StringInputStream;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class TaskIOStorageKvStore implements TaskIOStorageHandler {
    use Wlf4p;

    const PREFIX = 'winter-dtce-io-';

    protected KvTemplate $client;
    protected string $domain;
    private int $ttl;
    protected int $retries;

    public function __construct(
        protected ApplicationContext $ctx,
        protected array $taskDef
    ) {
        $port = $this->ctx->getPropertyInt('winter.kv.port', 0);
        if ($port <= 0) {
            throw new DtceException('Shared KV server is not setup to use KV Store');
        }

        $this->client = $this->ctx->beanByClass(KvTemplate::class);
        $this->ttl = $this->taskDef['storage.kv.ttl'] ?? self::GC_TIME;
        $this->retries = 5;

        $this->domain = self::PREFIX . $this->taskDef['name'];
    }

    public function getInputStream(int|string $dataId): InputStream {
        $i = $this->retries;
        $value = '';
        while ($i > 0) {
            try {
                $value = $this->client->get($this->domain, '' . $dataId);

                if (is_null($value)) {
                    throw new DtceException('Could not find data for dataId ' . $dataId . ' in KV store');
                }
            } catch (Throwable $e) {
                self::logException($e);
                usleep(200000);
            }
            $i--;
        }

        return new StringInputStream($value);
    }

    public function put(int|string $dataId, string $data): void {
        $i = $this->retries;
        while ($i > 0) {
            try {
                $this->client->put($this->domain, $dataId, '' . $data, $this->ttl);
            } catch (Throwable $e) {
                self::logException($e);
                usleep(200000);
            }
            $i--;
        }
    }

    public function delete(int|string $dataId): void {
        $i = $this->retries;
        while ($i > 0) {
            try {
                $this->client->del($this->domain, '' . $dataId);
            } catch (Throwable $e) {
                self::logException($e);
                usleep(200000);
            }
            $i--;
        }
    }

}