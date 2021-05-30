<?php
namespace dev\winterframework\data\redis;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\data\redis\core\RedisTemplate;
use dev\winterframework\stereotype\Module;
use dev\winterframework\util\ModuleTrait;

#[Module]
class RedisModule implements WinterModule {
    use ModuleTrait;

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $this->addBeanComponent($ctx, $ctxData, RedisTemplate::class);
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        // TODO: Implement begin() method.
    }


}