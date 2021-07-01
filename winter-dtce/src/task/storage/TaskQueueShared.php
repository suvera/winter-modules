<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\io\queue\QueueConfig;
use dev\winterframework\io\queue\SharedQueue;
use dev\winterframework\type\Queue;

class TaskQueueShared extends TaskQueueAbstract {

    protected function buildTaskQueue(): Queue {

        $port = $this->ctx->getPropertyInt('winter.queue.port', 0);
        $address = $this->ctx->getPropertyStr('winter.queue.address', '');
        if ($port <= 0) {
            throw new DtceException('Shared Queue server is not setup to use Shared Queue');
        }
        $config = new QueueConfig(
            $port,
            $address ?: null,
            null
        );

        return new SharedQueue($config, $this->taskName(), $this->taskDef['queue.capacity']);
    }

}