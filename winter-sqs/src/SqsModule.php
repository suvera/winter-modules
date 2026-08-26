<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\sqs;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterModule;
use dev\winterframework\core\context\support\Module;
use dev\winterframework\exception\WinterException;
use dev\winterframework\sqs\consumer\ConsumerConfiguration;
use dev\winterframework\sqs\consumer\ConsumerConfigurations;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\ModuleTrait;

#[Module]
class SqsModule implements WinterModule {
    use Wlf4p;
    use ModuleTrait;

    const __DEFAULT = '__default__';

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $this->addBeanComponent($ctx, $ctxData, SqsServiceImpl::class);
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);

        $this->buildConnections($config, $ctx);
        $this->buildConsumers($config, $ctx);
        $this->startSqs($config, $ctx);
    }

    protected function startSqs(array $config, ApplicationContext $ctx): void {
        /** @var SqsServiceImpl $service */
        $service = $ctx->beanByClass(SqsServiceImpl::class);

        $service->beginConsume();
    }

    protected function getDefaults(array $list): array {
        $defaults = [];
        foreach ($list as $data) {
            if ($data['name'] == self::__DEFAULT) {
                unset($data['name']);
                $defaults = array_merge($defaults, $data);
            }
        }

        return $defaults;
    }

    protected function buildConnections(array $config, ApplicationContext $ctx): void {
        if (!isset($config['sqs']['connections']) || !is_array($config['sqs']['connections'])) {
            return;
        }

        /** @var SqsServiceImpl $service */
        $service = $ctx->beanByClass(SqsServiceImpl::class);

        $connectionDefaults = $this->getDefaults($config['sqs']['connections']);

        foreach ($config['sqs']['connections'] as $data) {
            if ($data['name'] == self::__DEFAULT) {
                continue;
            }

            $connectionConfig = array_merge($connectionDefaults, $data);
            $service->addConnection(new SqsConnection($connectionConfig, $ctx));
        }
    }

    protected function buildConsumers(array $config, ApplicationContext $ctx): void {
        if (!isset($config['sqs']['consumers']) || !is_array($config['sqs']['consumers'])) {
            return;
        }

        /** @var SqsServiceImpl $service */
        $service = $ctx->beanByClass(SqsServiceImpl::class);

        $consumerDefaults = $this->getDefaults($config['sqs']['consumers']);

        foreach ($config['sqs']['consumers'] as $data) {
            if ($data['name'] == self::__DEFAULT) {
                continue;
            }

            $consumerConfig = array_merge($consumerDefaults, $data);
            $service->addConsumer(new ConsumerConfiguration($consumerConfig, $ctx));
        }
    }

}