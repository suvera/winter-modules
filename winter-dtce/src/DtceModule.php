<?php
declare(strict_types=1);

namespace dev\winterframework\dtce;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\dtce\task\server\TaskServer;
use dev\winterframework\dtce\task\service\TaskExecutionServiceFactory;
use dev\winterframework\exception\ModuleException;
use dev\winterframework\stereotype\Module;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\ModuleTrait;

#[Module]
class DtceModule implements WinterModule {
    use Wlf4p;
    use ModuleTrait;

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        if (!extension_loaded('swoole')) {
            throw new ModuleException("KafkaModule requires *swoole* extension in PHP runtime");
        }

        //$this->addBeanComponent($ctx, $ctxData, SomeClass::class);
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);
        /** @var WinterServer $wServer */
        $wServer = $ctx->beanByClass(WinterServer::class);
        
        $taskServer = new TaskServer(
            $ctx,
            $wServer,
            $config
        );

        $factory = new TaskExecutionServiceFactory(
            $ctx,
            $config,
            $taskServer
        );

        /** @var WinterBeanProviderContext $beanFactory */
        $beanFactory = $ctxData->getBeanProvider();
        $beanFactory->registerInternalBean($factory, TaskExecutionServiceFactory::class);
        $beanFactory->registerInternalBean($taskServer, TaskServer::class);
    }

}