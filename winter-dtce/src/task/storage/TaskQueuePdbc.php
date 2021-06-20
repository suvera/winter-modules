<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\enity\TaskQueueEntity;
use dev\winterframework\dtce\task\enity\TaskQueueTable;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\pdbc\util\PdbcQueue;
use dev\winterframework\pdbc\util\PdbcQueueTable;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\stereotype\ppa\Table;
use dev\winterframework\type\Queue;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\DateUtil;
use Ramsey\Uuid\Uuid;
use Throwable;

class TaskQueuePdbc extends TaskQueueAbstract implements Queue {
    protected PdbcTemplate $pdbc;
    protected PdbcQueueTable $pdbcTable;
    protected TaskQueueEntity $entityDef;
    protected PdbcQueue $pdbcQueue;

    protected function buildTaskQueue(): Queue {
        if (isset($this->taskDef['queue.pdbc.bean']) && $this->taskDef['queue.pdbc.bean']) {
            $beanName = $this->taskDef['queue.pdbc.bean'];
            $this->pdbc = $this->ctx->hasBeanByName($beanName)
                ? $this->ctx->beanByName($beanName) : $this->ctx->beanByClass($beanName);
        } else {
            $this->pdbc = $this->ctx->beanByClass(PdbcTemplate::class);
        }

        $entityClass = $this->taskDef['queue.pdbc.entity'] ?? TaskQueueTable::class;

        TypeAssert::objectOfIsA($entityClass, TaskQueueEntity::class,
            'DTCE Task Queue Entity must implement TaskQueueEntity');

        $ref = RefKlass::getInstance($entityClass);

        $attrs = $ref->getAttributes(Table::class);
        if (!$attrs) {
            throw new DtceException('DTCE Task Queue Entity is not annotated with #[Table] ' . $entityClass);
        }
        $attr = $attrs[0];

        /** @var Table $table */
        try {
            $table = $attr->newInstance();
        } catch (Throwable $e) {
            throw new DtceException('DTCE Task Queue Entity is annotated with #[Table] with invalid params '
                . $entityClass . ' ' . $e->getMessage(), 0, $e);
        }

        $this->entityDef = new $entityClass();

        $this->pdbcTable = new PdbcQueueTable(
            $table->getName(),
            $entityClass,
            $this->entityDef->getIdColumn(),
            $this->entityDef->getProcessorIdColumn(),
            $this->entityDef->getOrderByColumn()
        );

        $this->pdbcQueue = new PdbcQueue($this->pdbc, $this->pdbcTable);

        return $this;
    }

    public function add(mixed $item, int $timeoutMs = 0): bool {
        $entityClass = $this->pdbcTable->getEntity();

        /** @var TaskQueueEntity $row */
        $row = new $entityClass();

        $row->setId(Uuid::uuid4()->toString());
        $row->setData($item);
        $row->setTaskName($this->taskDef['name']);
        $row->setUpdatedOn(DateUtil::getCurrentDateTime());

        return $this->pdbcQueue->add($row);
    }

    public function poll(int $timeoutMs = 0): ?object {
        return $this->pdbcQueue->poll($timeoutMs);
    }

}