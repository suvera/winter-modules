<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task;

interface TaskCommand {
    const ADD_TASK = 1;
    const FINISH_TASK = 2;
    const ERROR_TASK = 3;
    const STOP_TASK = 4;
    const GRAB_TASK = 5;
    const GET_TASK = 6;
}