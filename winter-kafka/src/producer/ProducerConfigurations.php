<?php
declare(strict_types=1);
namespace dev\winterframework\kafka\producer;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class ProducerConfigurations extends ArrayList {

    public function offsetGet($offset): ?ProducerConfiguration {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        /** @var ProducerConfiguration $value */
        TypeAssert::typeOf($value, ProducerConfiguration::class);

        $offset = $value->getName();
        parent::offsetSet($offset, $value);
    }

}