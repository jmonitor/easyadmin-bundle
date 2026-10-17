<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp;

use Jmonitor\Utils\ShellExecutor;

/**
 * Answers every command with the same output, without starting a process.
 */
final class FixtureShellExecutor extends ShellExecutor
{
    public int $calls = 0;

    public function __construct(
        private readonly ?string $output,
    ) {}

    public function execute(string $command): ?string
    {
        $this->calls++;

        return $this->output;
    }
}
