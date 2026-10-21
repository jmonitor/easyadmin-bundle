<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp;

use Jmonitor\Utils\ShellExecutor;

/**
 * Answers every command with the same output, or each command with its own, without starting a process.
 */
final class FixtureShellExecutor extends ShellExecutor
{
    public int $calls = 0;

    /**
     * @param string|array<string, ?string>|null $output an output, or the outputs by command
     */
    public function __construct(
        private readonly string|array|null $output,
    ) {}

    public function execute(string $command): ?string
    {
        $this->calls++;

        return is_array($this->output) ? $this->output[$command] ?? null : $this->output;
    }
}
