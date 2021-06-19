<?php
declare(strict_types=1);

namespace dev\winterframework\dtce;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\dtce\task\server\TaskServer;
use dev\winterframework\dtce\task\server\TaskServerProcess;
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

        //$this->addBeanComponent($ctx, $ctxData, KafkaServiceImpl::class);
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);

        $port = $config['server.port'] ?? 7004;
        if (!is_int($port)) {
            $port = 7004;
        }
        $config['server.port'] = $port;
        $config['server.temp.store'] = $config['server.temp.store'] ?? 'memory';
        $config['server.temp.path'] = $config['server.temp.path'] ?? sys_get_temp_dir();

        $taskServer = new TaskServer(
            $ctx,
            $config
        );

        $factory = new TaskExecutionServiceFactory(
            $ctx,
            $config
        );

        /** @var WinterBeanProviderContext $beanFactory */
        $beanFactory = $ctxData->getBeanProvider();
        $beanFactory->registerInternalBean($factory, TaskExecutionServiceFactory::class);

        $ps = new TaskServerProcess(
            $ctx->beanByClass(WinterServer::class),
            $ctx,
            $taskServer
        );

        /** @var WinterServer $wServer */
        $wServer = $ctx->beanByClass(WinterServer::class);
        $wServer->getServer()->addProcess($ps);
    }

}