<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\consumer;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\util\log\Wlf4p;

abstract class AbstractConsumer implements Consumer {
    use Wlf4p;

    public function __construct(
        protected ApplicationContext $ctx,
        protected ConsumerConfiguration $config
    ) {
    }

    public function getQueueName(): string {
        return $this->config->getStream();
    }

    public function getConfiguration(): ConsumerConfiguration {
        return $this->config;
    }

}
