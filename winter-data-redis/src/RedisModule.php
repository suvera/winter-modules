<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\data\redis\consumer\ConsumerConfiguration;
use dev\winterframework\data\redis\phpredis\PhpRedisArrayTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisClusterTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisSentinelTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisTokenTemplate;
use dev\winterframework\exception\BeansException;
use dev\winterframework\exception\ModuleException;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\stereotype\Module;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\ModuleTrait;

#[Module]
class RedisModule implements WinterModule {
    use ModuleTrait;
    use Wlf4p;

    const __DEFAULT = '__default__';

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        if (!extension_loaded('redis')) {
            throw new ModuleException("RedisModule requires *redis* extension in PHP runtime");
        }

        $this->addBeanComponent($ctx, $ctxData, RedisQueueServiceImpl::class);
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);

        $this->buildTemplates($config, 'singles', PhpRedisTemplate::class, $ctx, $ctxData,
            fn(array $c) => new PhpRedisTemplate($c),
            fn(PhpRedisTemplate $t) => $t->ping()
        );
        $this->buildTemplates($config, 'arrays', PhpRedisArrayTemplate::class, $ctx, $ctxData,
            fn(array $c) => new PhpRedisArrayTemplate($c),
            fn(PhpRedisArrayTemplate $t) => $t->ping()
        );
        $this->buildTemplates($config, 'clusters', PhpRedisClusterTemplate::class, $ctx, $ctxData,
            fn(array $c) => new PhpRedisClusterTemplate($c),
            fn(PhpRedisClusterTemplate $t) => $t->echo('Hello, CLuster')
        );
        $this->buildTemplates($config, 'sentinels', PhpRedisSentinelTemplate::class, $ctx, $ctxData,
            fn(array $c) => new PhpRedisSentinelTemplate($c),
            fn(PhpRedisSentinelTemplate $t) => $t->ping()
        );
        $this->buildTemplates($config, 'tokens', PhpRedisTokenTemplate::class, $ctx, $ctxData,
            fn(array $c) => new PhpRedisTokenTemplate($c),
            fn(PhpRedisTokenTemplate $t) => $t->ping()
        );
        $this->buildQueueConsumers($config, $ctx);
        $this->startRedisQueue($ctx);
    }

    protected function startRedisQueue(ApplicationContext $ctx): void {
        /** @var RedisQueueServiceImpl $service */
        $service = $ctx->beanByClass(RedisQueueServiceImpl::class);

        $service->beginConsume();
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

    protected function buildQueueConsumers(array $config, ApplicationContext $ctx): void {
        $consumers = $config['redis.consumers'] ?? [];
        if (!is_array($consumers)) {
            return;
        }

        /** @var RedisQueueServiceImpl $service */
        $service = $ctx->beanByClass(RedisQueueServiceImpl::class);

        $consumerDefaults = $this->getDefaults($consumers);

        foreach ($consumers as $data) {
            TypeAssert::array($data, " Invalid 'redis.consumers' entry");
            if (($data['name'] ?? '') === self::__DEFAULT) {
                continue;
            }

            $consumerConfig = array_merge($consumerDefaults, $data);
            $service->addConsumer(new ConsumerConfiguration($consumerConfig, $ctx));
        }
    }

    /**
     * Register one bean per "phpredis.<type>" entry; the first entry also
     * becomes the by-class default.
     */
    protected function buildTemplates(
        array $config,
        string $type,
        string $beanClass,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData,
        callable $create,
        callable $probe
    ): void {
        $key = 'phpredis.' . $type;
        if (!isset($config[$key]) || !is_array($config[$key])) {
            return;
        }

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();
        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $ctx->beanByClass(IdleCheckRegistry::class);

        $i = 0;
        foreach ($config[$key] as $dataConfig) {
            TypeAssert::array($dataConfig, " Invalid Redis '$type' entry");
            $name = $dataConfig['name'] ?? '';
            TypeAssert::notEmpty('name', $name, "Redis '$type' missing name attribute");

            if ($ctx->hasBeanByName($name)) {
                throw new BeansException("Bean already exist with name '" . $name
                    . "' Redis '$type' name conflicts with other bean");
            }

            $tpl = $create($dataConfig);
            $probe($tpl);
            $beanProvider->registerInternalBean(
                $tpl,
                $beanClass,
                ($i == 0),
                $name,
                true
            );
            $idleCheck->register([$tpl, 'checkIdleConnection']);

            $i++;
        }
    }

}
