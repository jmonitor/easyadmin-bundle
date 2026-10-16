<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector\Symfony;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs console commands of the application, or processes from its project directory.
 *
 * @internal
 */
class CommandRunner
{
    private readonly Application $application;

    private readonly string $projectDir;

    /** @var list<string>|false|null */
    private array|false|null $phpBinary = null;

    public function __construct(KernelInterface $kernel)
    {
        $this->application = new Application(clone $kernel);
        $this->application->setAutoExit(false);
        $this->projectDir = $kernel->getProjectDir();
    }

    /**
     * @return array{exit_code: int, output: string}|null null when the command does not exist
     */
    public function run(string $command): ?array
    {
        if (!$this->application->has($command)) {
            return null;
        }

        $output = new BufferedOutput();

        return [
            'exit_code' => $this->application->doRun(new ArrayInput(['command' => $command]), $output),
            'output' => $output->fetch(),
        ];
    }

    /**
     * @param list<string> $command
     *
     * @return array{exit_code: int|null, output: string, error_output: string}
     */
    public function runProcess(array $command, int $timeout): array
    {
        $process = new Process($command, $this->projectDir, timeout: $timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'output' => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
        ];
    }

    /**
     * Prepends the PHP binary to the command, or runs it as is when the binary cannot be found.
     *
     * @param list<string> $command
     *
     * @return array{exit_code: int|null, output: string, error_output: string}
     */
    public function runPhpProcess(array $command, int $timeout): array
    {
        $phpBinary = $this->phpBinary();

        return $this->runProcess($phpBinary === false ? $command : [...$phpBinary, ...$command], $timeout);
    }

    /**
     * @param array{exit_code: int|null, output: string, error_output: string} $run
     */
    public static function failureMessage(string $command, array $run): string
    {
        $details = trim($run['error_output']) !== '' ? trim($run['error_output']) : trim($run['output']);
        $message = sprintf('%s failed (exit code: %s)', $command, var_export($run['exit_code'], true));

        return $details === '' ? $message : $message . ': ' . $details;
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    /**
     * @return list<string>|false
     */
    private function phpBinary(): array|false
    {
        if ($this->phpBinary !== null) {
            return $this->phpBinary;
        }

        if (PHP_SAPI === 'frankenphp') {
            return $this->phpBinary = ['frankenphp', 'php-cli'];
        }

        $binary = (new PhpExecutableFinder())->find(false);

        return $this->phpBinary = $binary === false ? false : [$binary];
    }
}
