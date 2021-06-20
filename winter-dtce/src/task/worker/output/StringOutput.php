<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker\output;

use dev\winterframework\dtce\task\worker\TaskOutput;

class StringOutput implements TaskOutput {

    public function __construct(
        protected string $value = ''
    ) {
    }

    public function append(mixed $str): void {
        $this->value .= $str;
    }

    public function get(): string {
        return $this->value;
    }


}