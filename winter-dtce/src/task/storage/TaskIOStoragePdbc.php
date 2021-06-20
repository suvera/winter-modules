<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\enity\TaskIoStorageEntity;
use dev\winterframework\dtce\task\enity\TaskIoTable;
use dev\winterframework\io\stream\InputStream;
use dev\winterframework\io\stream\StringInputStream;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\stereotype\ppa\Table;
use dev\winterframework\type\TypeAssert;
use Throwable;

class TaskIOStoragePdbc implements TaskIOStorageHandler {
    protected PdbcTemplate $pdbc;
    protected Table $table;
    protected string $entityClass;
    protected TaskIoStorageEntity $entityDef;

    public function __construct(
        protected ApplicationContext $ctx,
        protected array $taskDef
    ) {
        if (isset($this->taskDef['storage.pdbc.bean']) && $this->taskDef['storage.pdbc.bean']) {
            $beanName = $this->taskDef['storage.pdbc.bean'];
            $this->pdbc = $this->ctx->hasBeanByName($beanName)
                ? $this->ctx->beanByName($beanName) : $this->ctx->beanByClass($beanName);
        } else {
            $this->pdbc = $this->ctx->beanByClass(PdbcTemplate::class);
        }

        $entityClass = $this->taskDef['storage.pdbc.entity'] ?? TaskIoTable::class;

        TypeAssert::objectOfIsA($entityClass, TaskIoStorageEntity::class,
            'DTCE Task Storage Entity must implement TaskIoStorageEntity');

        $ref = RefKlass::getInstance($entityClass);

        $attrs = $ref->getAttributes(Table::class);
        if (!$attrs) {
            throw new DtceException('DTCE Task Storage Entity is not annotated with #[Table] ' . $entityClass);
        }
        $attr = $attrs[0];

        /** @var Table $table */
        try {
            $table = $attr->newInstance();
        } catch (Throwable $e) {
            throw new DtceException('DTCE Task Storage Entity is annotated with #[Table] with invalid params '
                . $entityClass . ' ' . $e->getMessage(), 0, $e);
        }
        $this->table = $table;
        $this->entityClass = $entityClass;
        $this->entityDef = new $entityClass();
    }

    public function getInputStream(int|string $dataId): InputStream {
        try {
            /** @var TaskIoStorageEntity $value */
            $value = $this->pdbc->queryForObject(
                'select * from ' . $this->table->getName() . ' where ' . $this->entityDef->getIdColumn() . ' = :id ',
                ['id' => $dataId],
                $this->entityClass
            );
        } catch (Throwable $e) {
            throw new DtceException('Could not find data for dataId ' . $dataId . ' in task store '
                . $e->getMessage(), 0, $e);
        }

        return new StringInputStream($value->getData());
    }

    public function put(int|string $dataId, string $data): void {
        $entityClass = $this->entityClass;

        /** @var TaskIoStorageEntity $row */
        $row = new $entityClass();
        $row->setId($dataId);
        $row->setTaskName($this->taskDef['name']);
        $row->setData($data);

        $this->pdbc->updateObjects($row);
    }

}