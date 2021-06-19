<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\util;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisArrayTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisClusterTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisTokenTemplate;

class RedisUtil {

    public static function getRedisBean(
        ApplicationContext $ctx,
        ?string $beanName = null
    ): PhpRedisAbstractTemplate {
        if ($beanName) {
            return $ctx->beanByName($beanName);
        } else {
            if ($ctx->hasBeanByClass(PhpRedisClusterTemplate::class)) {
                return $ctx->beanByClass(PhpRedisClusterTemplate::class);
            } else if ($ctx->hasBeanByClass(PhpRedisArrayTemplate::class)) {
                return $ctx->beanByClass(PhpRedisArrayTemplate::class);
            } else if ($ctx->hasBeanByClass(PhpRedisTokenTemplate::class)) {
                return $ctx->beanByClass(PhpRedisTokenTemplate::class);
            } else {
                return $ctx->beanByClass(PhpRedisTemplate::class);
            }
        }
    }
}