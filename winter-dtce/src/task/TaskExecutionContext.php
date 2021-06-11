<?php
declare(strict_types=1);


namespace dev\winterframework\dtce\task;

use ArrayObject;

class TaskExecutionContext extends ArrayObject {

    protected string $id;

    public function __construct(array $data = []) {
        $this->id = uniqid('dtce_task', true);
        parent::__construct($data);
    }

    /**
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

}