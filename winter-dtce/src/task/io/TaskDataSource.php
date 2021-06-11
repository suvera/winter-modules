<?php
declare(strict_types=1);


namespace dev\winterframework\dtce\task\io;

interface TaskDataSource {
    const SOURCE_IN_MEMORY = 1;
    const SOURCE_FILE = 2;
    const SOURCE_REDIS = 3;
    const SOURCE_REDIS_FILE = 4;
    const SOURCE_DB = 5;
    const SOURCE_DB_FILE = 6;

}