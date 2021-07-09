<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\util\log\Wlf4p;

abstract class AbstractConsumer implements Consumer {
    use Wlf4p;

    public function __construct(
        protected ApplicationContext $ctx,
        protected ConsumerConfiguration $config
    ) {
    }

    public function getGroupName(): string {
        return $this->config->getName();
    }

    public function getConfiguration(): ConsumerConfiguration {
        return $this->config;
    }
}