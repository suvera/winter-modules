<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\io\stream\FileInputStream;
use dev\winterframework\io\stream\InputStream;
use dev\winterframework\util\log\Wlf4p;

class TaskIOStorageDisk implements TaskIOStorageHandler {
    use Wlf4p;

    protected string $baseDir;
    protected int $maxSize = 1000000;

    protected int $ttl;

    public function __construct(
        protected ApplicationContext $ctx,
        protected array $taskDef
    ) {
        $this->baseDir = $this->taskDef['storage.path'] ?? sys_get_temp_dir();
        $this->baseDir = rtrim($this->baseDir, '/');

        $this->ttl = $this->taskDef['storage.ttl'] ?? self::GC_TIME;
        if ($this->ttl < 0) {
            $this->ttl = self::GC_TIME;
        }

        $written = file_put_contents(
            $this->baseDir . DIRECTORY_SEPARATOR . 'dtce-store.txt',
            'DTCE'
        );
        if ($written === false) {
            throw new DtceException('Could not write to temp folder ' . $this->baseDir);
        }
    }

    public function getInputStream(int|string $dataId): InputStream {
        return new FileInputStream($this->getFilePath($dataId));
    }

    protected function getFilePath(string $dataId): string {
        return $this->baseDir . DIRECTORY_SEPARATOR . $dataId . '.data';
    }

    protected function gc(): void {
        $files = glob($this->baseDir . "/*.data");
        $now = time();

        foreach ($files as $file) {
            if (is_file($file)) {
                if (($now - filemtime($file)) >= $this->ttl) {
                    unlink($file);
                }
            }
        }
    }

    public function put(int|string $dataId, string $data): void {
        if (time() % 20 === 0) {
            $this->gc();
        }

        $file = $this->getFilePath($dataId);
        $result = file_put_contents($file, '' . $data);

        if ($result === false) {
            self::logError('Could not create file ' . $file);
            throw new DtceException('Could not create file ' . $file);
        }
        //self::logInfo('File created ' . $file);
    }

    public function delete(int|string $dataId): void {
        $file = $this->getFilePath($dataId);
        unlink($file);
    }


}