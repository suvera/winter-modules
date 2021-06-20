<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker\output;

use dev\winterframework\dtce\task\worker\TaskOutput;

class ObjectOutput implements TaskOutput {
    protected object $value;

    public function set(object $obj): void {
        $this->value = $obj;
    }
    
    public function get(): object {
        return $this->value;
    }


}