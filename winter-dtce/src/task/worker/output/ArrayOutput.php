<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker\output;

use dev\winterframework\dtce\task\worker\TaskOutput;

class ArrayOutput implements TaskOutput {
    protected array $value = [];

    public function append(mixed $item): void {
        $this->value[] = $item;
    }

    public function set(array $items): void {
        $this->value = $items;
    }

    public function get(): array {
        return $this->value;
    }


}