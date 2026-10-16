<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector\Symfony;

use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Symfony\Component\Process\Exception\RuntimeException;

/**
 * @internal
 */
final class FlexRecipesCollector implements ComponentCollectorInterface
{
    private const TIMEOUT = 5;

    /** @var array{up_to_date: true}|array{up_to_date: false, outdated_recipes: list<string>}|null */
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
     * @return array{up_to_date: true}|array{up_to_date: false, outdated_recipes: list<string>}
     */
    public function collect(): array
    {
        if ($this->bootCollect !== null) {
            $output = $this->bootCollect;
            $this->bootCollect = null;

            return $output;
        }

        try {
            $run = $this->commandRunner->runProcess(['composer', 'recipes', '-o'], self::TIMEOUT);
        } catch (RuntimeException $e) {
            throw new CollectorException($e->getMessage(), self::class, $e);
        }

        if ($run['exit_code'] === 0) {
            return ['up_to_date' => true];
        }

        // Flex also exits with 1 when it lists outdated recipes.
        if (!str_contains($run['output'], 'Outdated recipes.')) {
            throw new CollectorException(CommandRunner::failureMessage('composer recipes', $run), self::class);
        }

        return ['up_to_date' => false, 'outdated_recipes' => $this->parseOutput($run['output'])];
    }

    /**
     * @return list<string>
     */
    private function parseOutput(string $output): array
    {
        $output = str_replace('Outdated recipes.', '', $output);
        $output = trim(explode('Run:', $output)[0]);

        if ($output === '') {
            return [];
        }

        $recipes = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '* ')) {
                $recipes[] = trim(substr($line, 2));
            }
        }

        return $recipes;
    }
}
