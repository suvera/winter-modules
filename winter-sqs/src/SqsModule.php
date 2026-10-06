<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\sqs;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\exception\BeansException;
use dev\winterframework\sqs\consumer\ConsumerConfiguration;
use dev\winterframework\stereotype\Module;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\ModuleTrait;

#[Module]
class SqsModule implements WinterModule {
    use Wlf4p;
    use ModuleTrait;

    const __DEFAULT = '__default__';

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        self::logDebug('SqsModule::init() called');
        $this->addBeanComponent($ctx, $ctxData, SqsServiceImpl::class);
        self::logDebug('SqsModule::init() completed');
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        self::logDebug('SqsModule::begin() called');
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);

        self::logDebug('SqsModule config loaded, keys: ' . json_encode(array_keys($config)));
        if (isset($config['sqs'])) {
            self::logDebug('SqsModule config[sqs] keys: ' . json_encode(array_keys($config['sqs'])));
            if (isset($config['sqs']['connections'])) {
                self::logDebug('SqsModule connections count: ' . count($config['sqs']['connections']));
            }
            if (isset($config['sqs']['consumers'])) {
                self::logDebug('SqsModule consumers count: ' . count($config['sqs']['consumers']));
            }
        }

        $this->buildConnections($config, $ctx, $ctxData);
        $this->buildConsumers($config, $ctx);
        $this->startSqs($config, $ctx);
        self::logDebug('SqsModule::begin() completed');
    }

    protected function startSqs(array $config, ApplicationContext $ctx): void {
        self::logDebug('SqsModule::startSqs() called');
        /** @var SqsServiceImpl $service */
        $service = $ctx->beanByClass(SqsServiceImpl::class);

        $service->beginConsume();
        self::logDebug('SqsModule::startSqs() completed');
    }

    protected function getDefaults(array $list): array {
        $defaults = [];
        foreach ($list as $data) {
            if (is_array($data) && ($data['name'] ?? '') === self::__DEFAULT) {
                unset($data['name']);
                $defaults = array_merge($defaults, $data);
            }
        }

        return $defaults;
    }

    protected function buildConnections(
        array $config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {
        $connections = $config['sqs.connections'] ?? [];
        if (!is_array($connections)) {
            return;
        }

        self::logDebug('buildConnections: found ' . count($connections) . ' connection(s)');

        /** @var SqsServiceImpl $service */
        $service = $ctx->beanByClass(SqsServiceImpl::class);
        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();

        $connectionDefaults = $this->getDefaults($connections);

        foreach ($connections as $data) {
            TypeAssert::array($data, 'Invalid sqs.connections entry');
            if (($data['name'] ?? '') === self::__DEFAULT) {
                continue;
            }

            $connectionConfig = array_merge($connectionDefaults, $data);
            $connection = new SqsConnection($connectionConfig, $ctx);

            $beanName = $connection->getName() . '_connection';
            if ($ctx->hasBeanByName($beanName)) {
                throw new BeansException("Bean already exist with name '" . $beanName
                    . "' SQS connection name conflicts with other bean");
            }

            $service->addConnection($connection);
            $beanProvider->registerInternalBean(
                $connection,
                SqsConnection::class,
                !$ctx->hasBeanByClass(SqsConnection::class),
                $beanName,
                true
            );
        }
    }

    protected function buildConsumers(array $config, ApplicationContext $ctx): void {
        $consumers = $config['sqs.consumers'] ?? [];
        if (!is_array($consumers)) {
            return;
        }

        self::logDebug('buildConsumers: found ' . count($consumers) . ' consumer(s)');

        /** @var SqsServiceImpl $service */
        $service = $ctx->beanByClass(SqsServiceImpl::class);

        $consumerDefaults = $this->getDefaults($consumers);

        foreach ($consumers as $data) {
            TypeAssert::array($data, 'Invalid sqs.consumers entry');
            if (($data['name'] ?? '') === self::__DEFAULT) {
                continue;
            }

            $consumerConfig = array_merge($consumerDefaults, $data);
            $service->addConsumer(new ConsumerConfiguration($consumerConfig, $ctx));
        }
    }

}