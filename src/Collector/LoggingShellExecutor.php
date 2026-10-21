<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Utils\ShellExecutor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A command that cannot run, or gives no output, is logged and gives null: the values it feeds are
 * decorative, and the log is the only place telling why they are missing.
 */
final class LoggingShellExecutor extends ShellExecutor
{
    private readonly ShellExecutor $inner;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ShellExecutor $inner = null,
    ) {
        $this->inner = $inner ?? new ProcessShellExecutor();
    }

    public function execute(string $command): ?string
    {
        try {
            $output = $this->inner->execute($command);

            if ($output === null) {
                $this->logger->warning('"{command}" gave no output.', ['command' => $command]);
            }

            return $output;
        } catch (\Throwable $e) {
            // No proc_open, a process that cannot start or fails.
            $this->logger->warning('Cannot run "{command}": {reason}', ['command' => $command, 'reason' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }
}
