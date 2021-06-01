<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

/**
 * @class ConsumerRecord[]
 */
class ConsumerRecords extends ArrayList {

    public function offsetGet($offset): ?ConsumerRecord {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::typeOf($value, ConsumerRecord::class);
        parent::offsetSet($offset, $value);
    }

}