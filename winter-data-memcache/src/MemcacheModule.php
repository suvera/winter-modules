<?php

namespace dev\winterframework\data\memcache;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\data\memcache\mc\MemcacheTemplate;
use dev\winterframework\data\memcache\mc\MemcacheTemplateImpl;
use dev\winterframework\data\memcache\mcd\MemcachedTemplate;
use dev\winterframework\data\memcache\mcd\MemcachedTemplateImpl;
use dev\winterframework\exception\BeansException;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\Module;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\ModuleTrait;

#[Module]
class MemcacheModule implements WinterModule {
    use ModuleTrait;
    use Wlf4p;

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        ReflectionUtil::assertPhpAnyExtension(['memcached', 'memcache']);
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);

        $this->buildMemcacheAdapter($config, $ctx, $ctxData);
        $this->buildMemcachedAdapter($config, $ctx, $ctxData);
    }

    protected function buildMemcacheAdapter(
        array $config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {
        if (!isset($config['memcache']) || !is_array($config['memcache'])) {
            return;
        }

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();
        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $ctx->beanByClass(IdleCheckRegistry::class);

        $i = 0;
        foreach ($config['memcache'] as $dataConfig) {
            TypeAssert::notEmpty('name', $dataConfig['name'], "Memcache configuration missing name attribute");

            if ($ctx->hasBeanByName($dataConfig['name'])) {
                throw new BeansException("Bean already exist with name '" . $dataConfig['name']
                    . "' Memcache 'configuration' name conflicts with other bean");
            }

            $tpl = new MemcacheTemplateImpl($dataConfig, true);
            $beanProvider->registerInternalBean(
                $tpl,
                MemcacheTemplate::class,
                ($i == 0),
                $dataConfig['name'],
                true
            );
            $idleCheck->register([$tpl, 'checkIdleConnection']);
            $i++;
        }
    }

    protected function buildMemcachedAdapter(
        array $config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {
        if (!isset($config['memcached']) || !is_array($config['memcached'])) {
            return;
        }

        /** @var WinterBeanProviderContext $beanProvider */
        $beanProvider = $ctxData->getBeanProvider();
        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $ctx->beanByClass(IdleCheckRegistry::class);

        $i = 0;
        foreach ($config['memcached'] as $dataConfig) {
            TypeAssert::notEmpty('name', $dataConfig['name'], "Memcached configuration missing name attribute");

            if ($ctx->hasBeanByName($dataConfig['name'])) {
                throw new BeansException("Bean already exist with name '" . $dataConfig['name']
                    . "' Memcached 'configuration' name conflicts with other bean");
            }

            $tpl = new MemcachedTemplateImpl($dataConfig, true);
            $beanProvider->registerInternalBean(
                $tpl,
                MemcachedTemplate::class,
                ($i == 0),
                $dataConfig['name'],
                true
            );
            $idleCheck->register([$tpl, 'checkIdleConnection']);
            $i++;
        }
    }

}