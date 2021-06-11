<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\io;

interface TaskInputOutput {

    public function getId(): int;

    public function getSourceId(): int;

    public function getData(): mixed;

    public function getStorageHandler(): TaskStorageHandler;
}