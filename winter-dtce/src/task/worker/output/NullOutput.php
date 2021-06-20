<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker\output;

use dev\winterframework\dtce\task\worker\TaskOutput;

class NullOutput implements TaskOutput {

    public function get(): mixed {
        return null;
    }
    
}