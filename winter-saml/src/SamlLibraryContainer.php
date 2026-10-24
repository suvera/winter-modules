<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use SimpleSAML\SAML2\Compat\AbstractContainer;
use SimpleSAML\SAML2\Compat\ContainerSingleton;
use dev\winterframework\util\log\Wlf4p;
use Stringable;

/**
 * What simplesamlphp/saml2 asks of its host: a logger (Winter's), a UTC
 * clock, and nothing that touches the request or a session. It is
 * process-wide and holds no per-request state, so it is safe under Swoole.
 */
final class SamlLibraryContainer extends AbstractContainer {
    private static bool $installed = false;

    private ClockInterface $clock;

    public function __construct() {
        $this->clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable {
                return new DateTimeImmutable('now', new DateTimeZone('UTC'));
            }
        };
    }

    /** Installs the container once per process. */
    public static function install(): void {
        if (!self::$installed) {
            ContainerSingleton::setContainer(new self());
            self::$installed = true;
        }
    }

    public function setBlacklistedAlgorithms(?array $algos): void {
        if ($algos !== null) {
            $this->blacklistedEncryptionAlgorithms = $algos;
        }
    }

    public function getLogger(): LoggerInterface {
        return new class extends AbstractLogger {
            use Wlf4p;

            public function log($level, string|Stringable $message, array $context = []): void {
                match ($level) {
                    'emergency', 'alert', 'critical', 'error' => self::logError((string)$message),
                    'warning' => self::logWarning((string)$message),
                    'notice', 'info' => self::logInfo((string)$message),
                    default => self::logDebug((string)$message),
                };
            }
        };
    }

    public function debugMessage($message, string $type): void {
        // Messages carry personal data and assertions; never log them.
    }

    public function getPOSTRedirectURL(string $url, array $data = []): string {
        throw new SamlException('HTTP-POST sending is not supported');
    }

    public function getTempDir(): string {
        return sys_get_temp_dir();
    }

    public function writeFile(string $filename, string $data, ?int $mode = null): void {
        file_put_contents($filename, $data);
        chmod($filename, $mode ?? 0600);
    }

    public function getClock(): ClockInterface {
        return $this->clock;
    }
}
