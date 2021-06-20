<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker\output;

use dev\winterframework\dtce\task\worker\TaskOutput;

class NumericOutput implements TaskOutput {

    public function __construct(
        protected int|float $value
    ) {
    }

    public function set(int|float $value): void {
        $this->value = $value;
    }

    public function get(): int|float {
        return $this->value;
    }


}