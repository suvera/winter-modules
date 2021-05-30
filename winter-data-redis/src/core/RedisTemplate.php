<?php
namespace dev\winterframework\data\redis\core;

use dev\winterframework\data\redis\connection\RedisConnection;

class RedisTemplate implements RedisOperations {

    public function __construct(private RedisConnection $connection) {
    }

    public function get(string $key): mixed {
        // TODO: Implement get() method.
    }

    public function getAndSet(string $key, mixed $value): mixed {
        // TODO: Implement getAndSet() method.
    }

    public function set(string $key, mixed $value, int $ttlSecs = 0): void {
        // TODO: Implement set() method.
    }

    public function setIfAbsent(string $key, mixed $value, int $ttlSecs = 0): void {
        // TODO: Implement setIfAbsent() method.
    }

    public function setIfPresent(string $key, mixed $value, int $ttlSecs = 0): void {
        // TODO: Implement setIfPresent() method.
    }

    public function valueSize(string $key): int {
        // TODO: Implement valueSize() method.
    }

    public function increment(string $key, float|int $delta = 0): int {
        // TODO: Implement increment() method.
    }

    public function decrement(string $key, float|int $delta = 0): int {
        // TODO: Implement decrement() method.
    }

    public function setBit(string $key, int $offset, bool $value): bool {
        // TODO: Implement setBit() method.
    }

    public function getBit(string $key, int $offset): bool {
        // TODO: Implement getBit() method.
    }

    public function delete(string $key): void {
        // TODO: Implement delete() method.
    }

}