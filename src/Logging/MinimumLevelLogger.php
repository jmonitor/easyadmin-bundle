<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Keeps warnings and above: the collectors chat at notice level on every collect.
 */
final class MinimumLevelLogger extends AbstractLogger
{
    private const KEPT = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    public function __construct(
        private readonly LoggerInterface $inner,
    ) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (in_array(strtolower((string) $level), self::KEPT, true)) {
            $this->inner->log($level, $message, $context);
        }
    }
}
