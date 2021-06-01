<?php
declare(strict_types=1);

namespace dev\winterframework\kafka;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\exception\FileNotFoundException;
use dev\winterframework\exception\ModuleException;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\exception\KafkaException;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\stereotype\Module;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\ModuleTrait;
use dev\winterframework\util\PropertyLoader;

#[Module]
class KafkaModule implements WinterModule {
    use Wlf4p;
    use ModuleTrait;

    const __DEFAULT = '__default__';

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        if (!extension_loaded('rdkafka')) {
            throw new ModuleException("KafkaModule requires *rdkafka* extension in PHP runtime");
        }

        $this->addBeanComponent($ctx, $ctxData, KafkaServiceImpl::class);
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $moduleConfig = $moduleDef->getConfig();

        $confFile = $moduleConfig['configFile'] ?? null;

        if (!$confFile) {
            return;
        }

        self::logInfo("Loading Kafka config from file '$confFile'");

        if ($confFile[0] != '/') {
            $configFiles = DirectoryScanner::scanFileInDirectories($ctxData->getBootConfig()->configDirectory, $confFile);
        } else {
            $configFiles = [$confFile];
        }

        if (empty($configFiles)) {
            self::logError('Could not find Kafka config file ' . json_encode($confFile));
            throw new FileNotFoundException('Could not find Kafka Config file');
        }

        $data = [];
        foreach ($configFiles as $configFile) {
            $conf = PropertyLoader::loadProperties($configFile);
            $data = array_merge($data, $conf);
        }

        $this->buildConsumers($data, $ctx);
        $this->buildProducers($data, $ctx);
        $this->startKafka($data, $ctx);
    }

    /** @noinspection PhpUnusedParameterInspection */
    protected function startKafka(array $config, ApplicationContext $ctx): void {
        /** @var KafkaServiceImpl $kafkaService */
        $kafkaService = $ctx->beanByClass(KafkaServiceImpl::class);

        $kafkaService->beginConsume();
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

    protected function buildConsumers(array $config, ApplicationContext $ctx): void {
        if (!isset($config['consumers']) || !is_array($config['consumers'])) {
            return;
        }

        /** @var KafkaServiceImpl $kafkaService */
        $kafkaService = $ctx->beanByClass(KafkaServiceImpl::class);

        $consumerDefaults = $this->getDefaults($config['consumers']);
        if (isset($config['metadata.broker.list']) && $config['metadata.broker.list']) {
            $consumerDefaults['metadata.broker.list'] = $config['metadata.broker.list'];
        } else if (isset($config['bootstrap.servers']) && $config['bootstrap.servers']) {
            $consumerDefaults['metadata.broker.list'] = $config['bootstrap.servers'];
        } else {
            throw new KafkaException('Either "bootstrap.servers" or "metadata.broker.list" must be set '
                . 'in your kafka config ');
        }

        foreach ($config['consumers'] as $data) {
            if ($data['name'] == self::__DEFAULT) {
                continue;
            }

            $consumerConfig = array_merge($consumerDefaults, $data);
            $kafkaService->addConsumer(new ConsumerConfiguration($consumerConfig));
        }
    }

    protected function buildProducers(array $config, ApplicationContext $ctx): void {
        if (!isset($config['producers']) || !is_array($config['producers'])) {
            return;
        }

        /** @var KafkaServiceImpl $kafkaService */
        $kafkaService = $ctx->beanByClass(KafkaServiceImpl::class);

        $producerDefaults = $this->getDefaults($config['producers']);
        if (isset($config['metadata.broker.list']) && $config['metadata.broker.list']) {
            $producerDefaults['metadata.broker.list'] = $config['metadata.broker.list'];
        } else if (isset($config['bootstrap.servers']) && $config['bootstrap.servers']) {
            $producerDefaults['metadata.broker.list'] = $config['bootstrap.servers'];
        } else {
            throw new KafkaException('Either "bootstrap.servers" or "metadata.broker.list" must be set '
                . 'in your kafka config ');
        }

        foreach ($config['producers'] as $data) {
            if ($data['name'] == self::__DEFAULT) {
                continue;
            }

            $producerConfig = array_merge($producerDefaults, $data);
            $kafkaService->addProducer(new ProducerConfiguration($producerConfig));
        }
    }

}