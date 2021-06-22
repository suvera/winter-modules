<?php
declare(strict_types=1);

namespace dev\winterframework\s3;

use Aws\S3\S3Client;
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
class S3Module implements WinterModule {
    use ModuleTrait;

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);


        if (!is_array($config['s3'])) {
            return;
        }
        foreach ($config['s3'] as $s3Config) {
            TypeAssert::array($s3Config, 'Invalid s3-config');
            $this->buildS3Template($s3Config, $ctx, $ctxData);
        }
    }

    protected function buildS3Template(
        array $s3Config,
        ApplicationContext $ctx,
        ApplicationContextData $ctxData
    ): void {
        /** @var WinterBeanProviderContext $beanFactory */
        $beanFactory = $ctxData->getBeanProvider();

        $this->checkConfig($s3Config, $ctx);

        if ($ctx->hasBeanByName($s3Config['name'])) {
            throw new BeansException("Bean already exist with name '" . $s3Config['name']
                . "' S3-Config,  conflicts with other bean");
        }

        unset($s3Config['name']);

        $s3Client = new S3Client($s3Config);

        $tpl = new S3Template($s3Client);

        $beanFactory->registerInternalBean(
            $tpl,
            S3Template::class,
            !$ctx->hasBeanByClass(S3Template::class),
            $s3Config['name'],
            true
        );
    }

    protected function checkConfig(array &$s3Config, ApplicationContext $ctx): void {
        TypeAssert::arrayItemNotEmpty($s3Config, 'name', 's3-config must have "name" ');
        TypeAssert::arrayItemNotEmpty($s3Config, 'region', 's3-config must have "region" ');
        TypeAssert::arrayItemNotEmpty($s3Config, 'version', 's3-config must have "version" ');

        $this->setCallableConfig($s3Config, 'credentials', $ctx);
        $this->setCallableConfig($s3Config, 'endpoint_provider', $ctx);
        $this->setCallableConfig($s3Config, 'endpoint_discovery', $ctx);
        $this->setCallableConfig($s3Config, 'use_arn_region', $ctx);

        $this->setCallableConfig($s3Config, 'csm', $ctx);
        $this->setCallableConfig($s3Config, 'signature_provider', $ctx);
        $this->setCallableConfig($s3Config, 'api_provider', $ctx);

        $this->setCallableConfig($s3Config, 'http_handler', $ctx);
        $this->setCallableConfig($s3Config, 'handler', $ctx);

        if (isset($s3Config['http'])) {
            if (is_array($s3Config['http']) && isset($s3Config['http'][0])) {
                $s3Config['http'] = $s3Config['http'][0];
            } else {
                throw new ModuleException('s3-config has mis-configured "http", must be array ');
            }
        }
    }

    protected function setCallableConfig(
        array &$s3Config,
        string $key,
        ApplicationContext $ctx
    ): void {

        if (!isset($s3Config[$key])) {
            return;
        }
        $value = $s3Config[$key];
        if (is_array($value) && isset($value[0])) {
            $s3Config[$key] = $value[0];
        } else if (!is_bool($value)) {
            $s3Config[$key] = new $value($ctx);
        }
    }
}