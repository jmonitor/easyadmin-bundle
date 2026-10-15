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

    /** @var array<string, array{up_to_date: true}|array{up_to_date: false, outdated_recipes: list<string>}> */
    private array $cache = [];

    public function __construct(
        private readonly CommandRunner $commandRunner,
    ) {}

    public function boot(): void
    {
        try {
            $this->collect();
        } catch (CollectorException $e) {
            throw new BootFailedException($e->getMessage(), $e);
        }
    }

    /**
     * @return array{up_to_date: true}|array{up_to_date: false, outdated_recipes: list<string>}
     */
    public function collect(): array
    {
        $day = date('Y-m-d');

        if (isset($this->cache[$day])) {
            return $this->cache[$day];
        }

        try {
            $run = $this->commandRunner->runProcess(['composer', 'recipes', '-o'], self::TIMEOUT);
        } catch (RuntimeException $e) {
            throw new CollectorException($e->getMessage(), self::class, $e);
        }

        if ($run['exit_code'] === null) {
            throw new CollectorException('Unable to run flex recipe command', self::class);
        }

        $this->cache = [
            $day => $run['exit_code'] === 0
                ? ['up_to_date' => true]
                : ['up_to_date' => false, 'outdated_recipes' => $this->parseOutput($run['output'])],
        ];

        return $this->cache[$day];
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
