<?php
declare(strict_types=1);
namespace dev\winterframework\sqs;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class SqsConnections extends ArrayList {

    public function offsetGet($offset): ?SqsConnection {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        /** @var SqsConnection $value */
        TypeAssert::typeOf($value, SqsConnection::class);

        $offset = $value->getName();
        parent::offsetSet($offset, $value);
    }

}
