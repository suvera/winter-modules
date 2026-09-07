<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\sqs;

use Aws\Sqs\SqsClient;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\util\log\Wlf4p;

/**
 * Represents a named AWS SQS connection. It holds the settings required to build an
 * SqsClient (region, credentials, version, ...) and lazily creates the underlying client.
 */
class SqsConnection {
    use Wlf4p;

    private static array $defaults = [
        'version' => 'latest',
    ];

    private string $name = '';
    private string $region = '';
    private string $version = 'latest';
    private array $credentials = [];

    private array $config = [];
    private ?SqsClient $rawClient = null;

    public function __construct(array $config, protected ApplicationContext $ctx) {
        foreach (self::$defaults as $key => $value) {
            if (isset($value)) {
                $this->config[$key] = $value;
            }
        }

        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && $key != 'config') {
                $this->$key = $value;
            } else {
                $this->config[$key] = $value;
            }
        }

        [$this->credentials, $this->config] = self::normalizeCredentials(
            $this->credentials,
            $this->config
        );
    }

    /**
     * Normalise the `credentials` setting into the map form the AWS SDK expects.
     *
     * Accepts:
     * - map form: ['key' => ..., 'secret' => ..., 'token' => ...]
     * - single-element list form: [['key' => ..., ...]] (as in sqs-config.yml samples)
     * - Winter Boot flattened dotted keys (Arrays::flattenByKey converts
     *   nested maps inside connection lists into dotted keys, so the plain
     *   `credentials` key never arrives): reassembled to any depth via
     *   extractDottedValues(). A non-empty partial map is passed through so
     *   the AWS SDK validates the shape (requires key + secret) loudly at
     *   client build instead of silently falling through to IMDS.
     *
     * @return array{0: array, 1: array} [credentials, config] with consumed
     *   dotted keys removed from config.
     */
    public static function normalizeCredentials(array $credentials, array $config): array {
        if ($credentials
            && array_is_list($credentials)
            && count($credentials) === 1
            && is_array($credentials[0])
        ) {
            $credentials = $credentials[0];
        }

        if (!$credentials) {
            [$dotted, $config] = self::extractDottedValues($config, 'credentials');
            if ($dotted !== []) {
                $credentials = $dotted;
            }
        }

        return [$credentials, $config];
    }

    /**
     * Reassemble a nested map that Winter Boot's flattenByKey split into
     * dotted keys. Collects every key starting with "$prefix.", splits the
     * remainder on '.' to any depth, and rebuilds nested arrays
     * (credentials.a.b.c becomes ['a' => ['b' => ['c' => ...]]]).
     * Consumed keys are removed from $config; all other keys are untouched.
     *
     * @return array{0: array, 1: array} [values, remainingConfig]
     */
    public static function extractDottedValues(array $config, string $prefix): array {
        $values = [];
        $needle = $prefix . '.';
        foreach ($config as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, $needle)) {
                continue;
            }
            $suffix = substr($key, strlen($needle));
            if ($suffix === '') {
                continue;
            }
            self::setByPath($values, explode('.', $suffix), $value);
            unset($config[$key]);
        }

        return [$values, $config];
    }

    private static function setByPath(array &$target, array $path, mixed $value): void {
        $segment = array_shift($path);
        if ($path === []) {
            $target[$segment] = $value;
            return;
        }
        if (!isset($target[$segment]) || !is_array($target[$segment])) {
            $target[$segment] = [];
        }
        self::setByPath($target[$segment], $path, $value);
    }

    public function getConfig(): array {
        return $this->config;
    }

    public function getConfigVal(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    public function getRawClient(): SqsClient {
        if (!isset($this->rawClient)) {
            $this->buildClient();
        }

        return $this->rawClient;
    }

    protected function buildClient(): void {
        $args = $this->config;

        $args['version'] = $this->version ?: 'latest';
        $args['region'] = $this->region ?: ($args['region'] ?? '');

        if ($this->credentials) {
            $args['credentials'] = $this->credentials;
        }

        // SqsUtil::buildClient() wires the Swoole HTTP handler for both API
        // requests and default-chain credential fetching (IMDS/ECS).
        $this->rawClient = SqsUtil::buildClient($args);
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): SqsConnection {
        $this->name = $name;
        return $this;
    }

    public function getRegion(): string {
        return $this->region;
    }

    public function setRegion(string $region): SqsConnection {
        $this->region = $region;
        return $this;
    }

}
