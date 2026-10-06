<?php
declare(strict_types=1);

/**
 * Minimal bootstrap + assertions for the module srcTest/ scripts (there is
 * no phpunit in this repo). Run a test file directly, e.g.:
 *
 *   php winter-data-redis/srcTest/RedisCacheTest.php
 *
 * Autoload comes from ./vendor (composer install). Without it, point
 * WINTER_BOOT_HOME at a winter-boot checkout with its own vendor/.
 * EXTRA_AUTOLOAD=<path/to/vendor/autoload.php> adds third-party SDKs.
 * TEST_FILTER=<substring> runs only the matching tests.
 */

$root = __DIR__;
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $wbHome = getenv('WINTER_BOOT_HOME') ?: dirname($root) . '/winter-boot';
    $autoload = $wbHome . '/vendor/autoload.php';
}
if (!is_file($autoload)) {
    fwrite(STDERR, "No autoloader: run composer install or set WINTER_BOOT_HOME\n");
    exit(2);
}
$loader = require $autoload;

$composer = json_decode(file_get_contents($root . '/composer.json'), true);
foreach ($composer['autoload']['psr-4'] as $ns => $dir) {
    // prepend: this checkout wins over any installed copy of the modules
    $loader->addPsr4($ns, $root . '/' . $dir, true);
}

// Optional extra autoloader for third-party SDKs (aws/aws-sdk-php,
// opensearch-project/opensearch-php) when not installed here. Registered
// after the main one, so framework/module classes still resolve above.
$extra = getenv('EXTRA_AUTOLOAD');
if ($extra && is_file($extra)) {
    require $extra;
}

final class T {
    private static int $passed = 0;
    private static array $failed = [];

    public static function test(string $name, callable $fn): void {
        $filter = getenv('TEST_FILTER');
        if ($filter && !str_contains($name, $filter)) {
            return;
        }
        try {
            $fn();
            self::$passed++;
            echo "  ok   $name\n";
        } catch (Throwable $e) {
            self::$failed[] = $name;
            echo "  FAIL $name: " . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $msg = ''): void {
        if ($expected !== $actual) {
            throw new RuntimeException(($msg ? "$msg: " : '') . 'expected '
                . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true(bool $cond, string $msg = 'expected true'): void {
        if (!$cond) {
            throw new RuntimeException($msg);
        }
    }

    public static function throws(string $class, callable $fn): Throwable {
        try {
            $fn();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        throw new RuntimeException("expected $class, nothing thrown");
    }

    /**
     * Instance of a generated class implementing $interface. Methods listed
     * in $impl delegate to the given closures; any other call throws.
     *
     * @param array<string, Closure> $impl
     */
    public static function stub(string $interface, array $impl = []): object {
        static $n = 0;
        $ref = new ReflectionClass($interface);
        $cls = 'TestStub' . (++$n);
        $methods = '';
        foreach ($ref->getMethods() as $m) {
            if ($m->isStatic()) {
                continue;
            }
            $params = [];
            foreach ($m->getParameters() as $p) {
                $s = ($p->hasType() ? self::typeStr($p->getType()) . ' ' : '')
                    . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
                if ($p->isDefaultValueAvailable()) {
                    $s .= ' = ' . ($p->isDefaultValueConstant()
                            ? '\\' . ltrim($p->getDefaultValueConstantName(), '\\')
                            : var_export($p->getDefaultValue(), true));
                }
                $params[] = $s;
            }
            if ($m->isConstructor()) {
                $methods .= 'public function __construct(' . implode(', ', $params) . ") {}\n";
                continue;
            }
            $ret = $m->hasReturnType() ? ': ' . self::typeStr($m->getReturnType()) : '';
            $isVoid = $ret === ': void' || $ret === ': never';
            $call = '$this->__impl(' . var_export($m->getName(), true) . ', func_get_args())';
            $methods .= 'public function ' . $m->getName() . '(' . implode(', ', $params) . ')' . $ret
                . ' { ' . ($isVoid ? $call . ';' : 'return ' . $call . ';') . " }\n";
        }
        eval('final class ' . $cls . ' implements \\' . $interface . ' {
            public array $impl = [];
            private function __impl(string $name, array $args): mixed {
                if (!isset($this->impl[$name])) {
                    throw new LogicException("stub method not implemented: $name");
                }
                return ($this->impl[$name])(...$args);
            }
            ' . $methods . '}');
        $obj = (new ReflectionClass($cls))->newInstanceWithoutConstructor();
        $obj->impl = $impl;
        return $obj;
    }

    private static function typeStr(ReflectionType $type): string {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $name = $type->isBuiltin() || in_array($name, ['self', 'static'], true) ? $name : '\\' . $name;
            return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $name;
        }
        $sep = $type instanceof ReflectionIntersectionType ? '&' : '|';
        return implode($sep, array_map(fn($t) => self::typeStr($t), $type->getTypes()));
    }

    public static function done(): never {
        echo "\n" . self::$passed . ' passed, ' . count(self::$failed) . " failed\n";
        exit(self::$failed ? 1 : 0);
    }
}
