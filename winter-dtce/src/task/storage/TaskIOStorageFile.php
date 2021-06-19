<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\io\stream\FileInputStream;
use dev\winterframework\io\stream\InputStream;

class TaskIOStorageFile implements TaskIOStorageHandler {
    protected string $baseDir;
    protected array $files = [];
    protected int $maxSize = 1000000;

    public function __construct(
        protected ApplicationContext $ctx,
        protected array $taskDef
    ) {
        $this->baseDir = $this->taskDef['storage.path'] ?? sys_get_temp_dir();
        $this->baseDir = rtrim($this->baseDir, '/');

        $written = file_put_contents(
            $this->baseDir . DIRECTORY_SEPARATOR . 'dtce-store.txt',
            'DTCE'
        );
        if ($written === false) {
            throw new DtceException('Could not write to temp folder ' . $this->baseDir);
        }
    }

    public function __destruct() {
        foreach ($this->files as $key => $file) {
            unlink($this->files[$file]);
        }
    }

    public function getInputStream(int|string $dataId): InputStream {
        if (!isset($this->files[$dataId])) {
            throw new DtceException('Could not find data for dataId ' . $dataId . ' in task store');
        }

        return new FileInputStream($this->files[$dataId]);
    }

    public function put(int|string $dataId, string $data): void {
        if (count($this->files) >= $this->maxSize) {
            $key = '';
            foreach ($this->files as $key => $tmpData) {
                break;
            }
            unlink($this->files[$key]);
            unset($this->files[$key]);
        }
        $this->files[$dataId] = $this->baseDir . DIRECTORY_SEPARATOR . $dataId . '.data';
    }

}