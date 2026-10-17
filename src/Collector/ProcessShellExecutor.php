<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Jmonitor\Utils\ShellExecutor;
use Symfony\Component\Process\Process;

/**
 * Runs the command like the library executor, but an empty output is an exception carrying the
 * exit code and the error output, so the log says why instead of guessing.
 */
final class ProcessShellExecutor extends ShellExecutor
{
    public function execute(string $command): string
    {
        $process = Process::fromShellCommandline($command);
        $process->run();
        $output = $process->getOutput();

        if ($output !== '') {
            return $output;
        }

        $stderr = trim($process->getErrorOutput());

        throw new \RuntimeException(sprintf(
            'exit code %s (%s): %s',
            $process->getExitCode() ?? '?',
            $process->getExitCodeText() ?? 'unknown',
            $stderr === '' ? 'no error output' : $stderr,
        ));
    }
}
