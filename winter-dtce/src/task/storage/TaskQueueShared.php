<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\io\queue\QueueSharedTemplate;
use dev\winterframework\io\queue\SharedQueue;
use dev\winterframework\type\Queue;

class TaskQueueShared extends TaskQueueAbstract {

    protected function buildTaskQueue(): Queue {

        $port = $this->ctx->getPropertyInt('winter.queue.port', 0);

        if ($port <= 0) {
            throw new DtceException('Shared Queue server is not setup to use Shared Queue');
        }
        return new SharedQueue(
            $this->ctx->beanByClass(QueueSharedTemplate::class),
            $this->taskName(),
            $this->taskDef['queue.capacity']
        );
    }

}