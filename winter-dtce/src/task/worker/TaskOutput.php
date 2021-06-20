<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker;

interface TaskOutput {

    public function get(): mixed;
}