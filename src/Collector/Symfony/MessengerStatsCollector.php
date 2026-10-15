<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector\Symfony;

use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;

/**
 * Reads `messenger:stats` in a separate process: run in the web process, the command can leave
 * the Doctrine connection in a "MySQL server has gone away" state.
 *
 * @internal
 */
final class MessengerStatsCollector implements ComponentCollectorInterface
{
    private const TIMEOUT = 3;

    /** @var array<mixed>|null */
    private ?array $bootCollect = null;

    public function __construct(
        private readonly CommandRunner $commandRunner,
    ) {}

    public function boot(): void
    {
        try {
            $this->bootCollect = $this->collect();
        } catch (CollectorException $e) {
            throw new BootFailedException($e->getMessage(), $e);
        }
    }

    /**
     * @return array<mixed>
     */
    public function collect(): array
    {
        if ($this->bootCollect !== null) {
            $output = $this->bootCollect;
            $this->bootCollect = null;

            return $output;
        }

        $run = $this->commandRunner->runPhpProcess(['bin/console', 'messenger:stats', '--format=json'], self::TIMEOUT);

        if ($run['exit_code'] !== 0) {
            throw new CollectorException('messenger:stats command failed (exit code: ' . var_export($run['exit_code'], true) . ')', self::class);
        }

        try {
            $stats = json_decode($run['output'], true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CollectorException('Failed to decode messenger:stats output: ' . $e->getMessage(), self::class, $e);
        }

        if (!is_array($stats)) {
            throw new CollectorException('Unexpected messenger:stats output', self::class);
        }

        return $stats;
    }
}
