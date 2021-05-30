<?php
namespace dev\winterframework\kafka\consumer;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class ConsumerConfigurations extends ArrayList {

    public function offsetGet($offset): ?ConsumerConfiguration {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        /** @var ConsumerConfiguration $value */
        TypeAssert::typeOf($value, ConsumerConfiguration::class);

        $offset = $value->getName();
        parent::offsetSet($offset, $value);
    }

}