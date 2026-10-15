<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector\Symfony;

use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\StringInput;

/**
 * @internal
 */
final class SchedulerCollector implements ComponentCollectorInterface
{
    /** @var list<array{trigger: string, command: string, arguments: list<string>, next_run: int, description: ?string}>|null */
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
     * @return list<array{trigger: string, command: string, arguments: list<string>, next_run: int, description: ?string}>
     */
    public function collect(): array
    {
        if ($this->bootCollect !== null) {
            $output = $this->bootCollect;
            $this->bootCollect = null;

            return $output;
        }

        $run = $this->commandRunner->run('debug:scheduler');

        if ($run === null) {
            throw new CollectorException('Unable to run debug:scheduler command', self::class);
        }

        if ($run['exit_code'] !== 0) {
            throw new CollectorException('debug:scheduler command failed (exit code: ' . var_export($run['exit_code'], true) . ')', self::class);
        }

        return array_map(
            fn(array $task): array => [...$task, 'description' => $this->description($task['command'])],
            $this->parseOutput($run['output']),
        );
    }

    private function description(string $commandName): ?string
    {
        try {
            return $this->commandRunner->getApplication()->find($commandName)->getDescription();
        } catch (CommandNotFoundException) {
            return null;
        }
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function splitCommandInput(string $commandInput): array
    {
        // StringInput only exposes its tokens since Symfony 7.1 (getRawTokens())
        $input = new class ($commandInput) extends StringInput {
            /** @var list<string> */
            public array $parsedTokens = [];

            /**
             * @param list<string> $tokens
             */
            protected function setTokens(array $tokens): void
            {
                $this->parsedTokens = $tokens;
                parent::setTokens($tokens);
            }
        };

        $tokens = $input->parsedTokens;
        $name = $input->getFirstArgument();

        if ($name === null) {
            return [$commandInput, $tokens];
        }

        unset($tokens[array_search($name, $tokens, true)]);

        return [$name, array_values($tokens)];
    }

    /**
     * @return list<array{trigger: string, command: string, arguments: list<string>, next_run: int}>
     */
    private function parseOutput(string $output): array
    {
        $tasks = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '---') || str_starts_with($line, 'Trigger')) {
                continue;
            }

            if (!preg_match(
                '/^(.+?)\s+Symfony\\\\Component\\\\Console\\\\Messenger\\\\RunCommandMessage\s+\((.+)\)\s+(.+)$/',
                $line,
                $matches,
            )) {
                continue;
            }

            try {
                $nextRun = new \DateTimeImmutable(trim($matches[3]));
            } catch (\Exception) {
                continue;
            }

            [$command, $arguments] = $this->splitCommandInput(trim($matches[2]));

            $tasks[] = [
                'trigger' => trim($matches[1]),
                'command' => $command,
                'arguments' => $arguments,
                'next_run' => $nextRun->getTimestamp(),
            ];
        }

        return $tasks;
    }
}
