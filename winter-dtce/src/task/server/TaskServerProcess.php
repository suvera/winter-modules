<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\server;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\process\ServerWorkerProcess;

class TaskServerProcess extends ServerWorkerProcess {
    protected TaskServer $taskServer;

    public function __construct(
        WinterServer $wServer,
        ApplicationContext $ctx,
        TaskServer $taskServer
    ) {
        parent::__construct($wServer, $ctx);
        $this->taskServer = $taskServer;
    }

    protected function run(): void {
        $this->taskServer->start();
    }

}