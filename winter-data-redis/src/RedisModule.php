<?php

namespace dev\winterframework\data\redis;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
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

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        if (!extension_loaded('redis')) {
            throw new ModuleException("RedisModule requires *redis* extension in PHP runtime");
        }
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);

        $this->buildRedisSingles($config, $ctx, $ctxData);
        $this->buildRedisArrays($config, $ctx, $ctxData);
        $this->buildRedisClusters($config, $ctx, $ctxData);
        $this->buildRedisSentinel($config, $ctx, $ctxData);
        $this->buildRedisTokenBased($config, $ctx, $ctxData);
    }

    protected function buildRedisSingles(
        array $config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {

        if (!isset($config['phpredis.singles']) || !is_array($config['phpredis.singles'])) {
            return;
        }

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();
        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $ctx->beanByClass(IdleCheckRegistry::class);

        $i = 0;
        foreach ($config['phpredis.singles'] as $dataConfig) {
            TypeAssert::notEmpty('name', $dataConfig['name'], "Redis 'singles' missing name attribute");

            if ($ctx->hasBeanByName($dataConfig['name'])) {
                throw new BeansException("Bean already exist with name '" . $dataConfig['name']
                    . "' Redis 'singles' name conflicts with other bean");
            }

            $tpl = new PhpRedisTemplate($dataConfig);
            $tpl->ping();
            $beanProvider->registerInternalBean(
                $tpl,
                PhpRedisTemplate::class,
                ($i == 0),
                $dataConfig['name'],
                true
            );
            $idleCheck->register([$tpl, 'checkIdleConnection']);

            $i++;
        }

    }

    protected function buildRedisArrays(
        array $config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {
        if (!isset($config['phpredis.arrays']) || !is_array($config['phpredis.arrays'])) {
            return;
        }

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();
        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $ctx->beanByClass(IdleCheckRegistry::class);

        $i = 0;
        foreach ($config['phpredis.arrays'] as $dataConfig) {
            TypeAssert::notEmpty('name', $dataConfig['name'], "Redis 'arrays' missing name attribute");

            if ($ctx->hasBeanByName($dataConfig['name'])) {
                throw new BeansException("Bean already exist with name '" . $dataConfig['name']
                    . "' Redis 'arrays' name conflicts with other bean");
            }

            $tpl = new PhpRedisArrayTemplate($dataConfig);
            $tpl->ping();
            $beanProvider->registerInternalBean(
                $tpl,
                PhpRedisArrayTemplate::class,
                ($i == 0),
                $dataConfig['name'],
                true
            );
            $idleCheck->register([$tpl, 'checkIdleConnection']);

            $i++;
        }
    }

    protected function buildRedisClusters(
        array $config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {
        if (!isset($config['phpredis.clusters']) || !is_array($config['phpredis.clusters'])) {
            return;
        }

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();
        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $ctx->beanByClass(IdleCheckRegistry::class);

        $i = 0;
        foreach ($config['phpredis.clusters'] as $dataConfig) {
            TypeAssert::notEmpty('name', $dataConfig['name'], "Redis 'clusters' missing name attribute");

            if ($ctx->hasBeanByName($dataConfig['name'])) {
                throw new BeansException("Bean already exist with name '" . $dataConfig['name']
                    . "' Redis 'clusters' name conflicts with other bean");
            }

            $tpl = new PhpRedisClusterTemplate($dataConfig);
            $tpl->echo('Hello, CLuster');
            $beanProvider->registerInternalBean(
                $tpl,
                PhpRedisClusterTemplate::class,
                ($i == 0),
                $dataConfig['name'],
                true
            );
            $idleCheck->register([$tpl, 'checkIdleConnection']);

            $i++;
        }
    }

    protected function buildRedisSentinel(
        array $config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {

        if (!isset($config['phpredis.sentinels']) || !is_array($config['phpredis.sentinels'])) {
            return;
        }

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();

        $i = 0;
        foreach ($config['phpredis.sentinels'] as $dataConfig) {
            TypeAssert::notEmpty('name', $dataConfig['name'], "Redis 'sentinels' missing name attribute");

            if ($ctx->hasBeanByName($dataConfig['name'])) {
                throw new BeansException("Bean already exist with name '" . $dataConfig['name']
                    . "' Redis 'sentinels' name conflicts with other bean");
            }

            $tpl = new PhpRedisSentinelTemplate($dataConfig);
            $tpl->ping();
            $beanProvider->registerInternalBean(
                $tpl,
                PhpRedisSentinelTemplate::class,
                ($i == 0),
                $dataConfig['name'],
                true
            );

            $i++;
        }
    }

    protected function buildRedisTokenBased(
        array $config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {

        if (!isset($config['phpredis.tokens']) || !is_array($config['phpredis.tokens'])) {
            return;
        }

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();
        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $ctx->beanByClass(IdleCheckRegistry::class);

        $i = 0;
        foreach ($config['phpredis.tokens'] as $dataConfig) {
            TypeAssert::notEmpty('name', $dataConfig['name'], "Redis 'tokens' missing name attribute");

            if ($ctx->hasBeanByName($dataConfig['name'])) {
                throw new BeansException("Bean already exist with name '" . $dataConfig['name']
                    . "' Redis 'tokens' name conflicts with other bean");
            }

            $tpl = new PhpRedisTokenTemplate($dataConfig);
            $tpl->ping();
            $beanProvider->registerInternalBean(
                $tpl,
                PhpRedisTokenTemplate::class,
                ($i == 0),
                $dataConfig['name'],
                true
            );
            $idleCheck->register([$tpl, 'checkIdleConnection']);

            $i++;
        }
    }

}