<?php
namespace dev\winterframework\data\redis\core;

interface ValueOperations {

    public function get(string $key): mixed;

    /**
     * Set value of key and return its old value.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    public function getAndSet(string $key, mixed $value): mixed;

    public function set(string $key, mixed $value, int $ttlSecs = 0): void;

    public function setIfAbsent(string $key, mixed $value, int $ttlSecs = 0): void;

    public function setIfPresent(string $key, mixed $value, int $ttlSecs = 0): void;

    public function valueSize(string $key): int;

    public function delete(string $key): void;

    public function increment(string $key, int|float $delta = 0): int;

    public function decrement(string $key, int|float $delta = 0): int;

    public function setBit(string $key, int $offset, bool $value): bool;

    public function getBit(string $key, int $offset): bool;

    public function exists(string $key): bool;
}