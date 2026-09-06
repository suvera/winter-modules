<?php
declare(strict_types=1);

namespace dev\winterframework\opensearch;

use OpenSearch\Client;
use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\exception\BeansException;
use dev\winterframework\exception\ModuleException;
use dev\winterframework\stereotype\Module;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\ModuleTrait;

#[Module]
class OpenSearchModule implements WinterModule {
    use ModuleTrait;

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);

        if (!is_array($config['opensearch'])) {
            return;
        }
        
        foreach ($config['opensearch'] as $osConfig) {
            TypeAssert::array($osConfig, 'Invalid opensearch-config');
            $this->buildOpenSearchTemplate($osConfig, $ctx, $ctxData);
        }
    }

    protected function buildOpenSearchTemplate(
        array $osConfig,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {
        /** @var WinterBeanProviderContext $beanFactory */
        $beanFactory = $ctxData->getBeanProvider();

        $this->checkConfig($osConfig, $ctx);

        $beanName = $osConfig['name'];
        
        if ($ctx->hasBeanByName($beanName)) {
            throw new BeansException("Bean already exist with name '" . $beanName
                . "' OpenSearch-Config,  conflicts with other bean");
        }

        unset($osConfig['name']);

        $osClient = OpenSearchUtil::buildClient($osConfig);

        $tpl = new OpenSearchTemplate($osClient);

        $beanFactory->registerInternalBean(
            $tpl,
            OpenSearchTemplate::class,
            !$ctx->hasBeanByClass(OpenSearchTemplate::class),
            $beanName,
            true
        );
    }

    protected function checkConfig(array &$osConfig, ApplicationContext $ctx): void {
        TypeAssert::arrayItemNotEmpty($osConfig, 'name', 'opensearch-config must have "name" ');
        TypeAssert::arrayItemNotEmpty($osConfig, 'hosts', 'opensearch-config must have "hosts" ');

        $this->setCallableConfig($osConfig, 'http_handler', $ctx);
        $this->setCallableConfig($osConfig, 'handler', $ctx);

        if (isset($osConfig['connection_params'])) {
            if (is_array($osConfig['connection_params']) && isset($osConfig['connection_params'][0])) {
                $osConfig['connection_params'] = $osConfig['connection_params'][0];
            } else {
                throw new ModuleException('opensearch-config has mis-configured "connection_params", must be array ');
            }
        }

        if (isset($osConfig['aws'])) {
            if (is_array($osConfig['aws']) && isset($osConfig['aws'][0])) {
                $osConfig['aws'] = $osConfig['aws'][0];
            } else {
                throw new ModuleException('opensearch-config has mis-configured "aws", must be array ');
            }

            if (isset($osConfig['aws']['credentials'])
                && is_array($osConfig['aws']['credentials'])
                && isset($osConfig['aws']['credentials'][0])
            ) {
                $osConfig['aws']['credentials'] = $osConfig['aws']['credentials'][0];
            }
        }
    }

    protected function setCallableConfig(
        array &$osConfig,
        string $key,
        ApplicationContext $ctx
    ): void {
        if (!isset($osConfig[$key])) {
            return;
        }
        $value = $osConfig[$key];
        if (is_array($value) && isset($value[0])) {
            $osConfig[$key] = $value[0];
        } else if (!is_bool($value)) {
            $osConfig[$key] = new $value($ctx);
        }
    }
}
