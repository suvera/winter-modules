<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker\output;

use dev\winterframework\dtce\task\worker\TaskOutput;
use dev\winterframework\io\stream\FileOutputStream;

class FileBasedOutput implements TaskOutput {

    protected FileOutputStream $stream;

    public function __construct(
        protected string $filePath,
        protected bool $deleteFileOnClose = false
    ) {
        $this->stream = new FileOutputStream($this->filePath);
    }

    public function __destruct() {
        $this->stream->close();
        if ($this->deleteFileOnClose) {
            $this->stream->destroy();
        }
    }

    public function append(mixed $str): void {
        $this->stream->write($str);
    }

    public function get(): string {
        return file_get_contents($this->filePath);
    }


}