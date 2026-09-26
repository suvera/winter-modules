<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\consumer;

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

    /**
     * Build records from a phpredis XREADGROUP result.
     * Shape: [stream => [id => fields]]; false/null/empty means no messages.
     */
    public static function fromStreamResult(
        string $stream,
        mixed $result,
        ?string $group = null,
        array $deliveryCounts = []
    ): self {
        $records = new self();

        if (!is_array($result)) {
            return $records;
        }

        $entries = $result[$stream] ?? $result;
        if (!is_array($entries)) {
            return $records;
        }

        foreach ($entries as $id => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $records[] = ConsumerRecord::fromStreamEntry(
                $stream,
                strval($id),
                $fields,
                $group,
                intval($deliveryCounts[strval($id)] ?? 1)
            );
        }

        return $records;
    }

    /**
     * Stream entry ids in this batch, for XACK.
     */
    public function getIds(): array {
        $ids = [];
        foreach ($this as $record) {
            /** @var ConsumerRecord $record */
            $ids[] = $record->getId();
        }

        return $ids;
    }

}
