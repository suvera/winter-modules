<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task;

interface TaskStatus {

    const UNKNOWN = -1;
    
    const QUEUED = 1;
    const RUNNING = 2;
    const FINISHED = 3;
    const ERRORED = 4;
    const STOPPED = 5;
}