<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector\Symfony;

use Jmonitor\EasyAdminBundle\Collector\Symfony\CommandRunner;
use Jmonitor\EasyAdminBundle\Collector\Symfony\FlexRecipesCollector;
use Jmonitor\Exceptions\CollectorException;
use PHPUnit\Framework\TestCase;

final class FlexRecipesCollectorTest extends TestCase
{
    public function testTheBootSampleIsServedByTheNextCollect(): void
    {
        $runner = $this->createMock(CommandRunner::class);
        $runner->expects(self::once())
            ->method('runProcess')
            ->with(['composer', 'recipes', '-o'])
            ->willReturn(['exit_code' => 0, 'output' => '', 'error_output' => '']);

        $collector = new FlexRecipesCollector($runner);
        $collector->boot();

        self::assertSame(['up_to_date' => true], $collector->collect());
    }

    public function testItListsTheOutdatedRecipes(): void
    {
        $output = <<<TXT
                  Outdated recipes.


                 * symfony/ux-turbo (recipe not installed)
                 * zenstruck/messenger-monitor-bundle (recipe not installed)

                Run:
                 * composer recipes vendor/package to see details about a recipe.
                 * composer recipes:update vendor/package to update that recipe.
            TXT;

        $runner = $this->createStub(CommandRunner::class);
        $runner->method('runProcess')->willReturn(['exit_code' => 1, 'output' => $output, 'error_output' => '']);

        self::assertSame(
            [
                'up_to_date' => false,
                'outdated_recipes' => [
                    'symfony/ux-turbo (recipe not installed)',
                    'zenstruck/messenger-monitor-bundle (recipe not installed)',
                ],
            ],
            (new FlexRecipesCollector($runner))->collect(),
        );
    }

    public function testAComposerThatCannotRunFails(): void
    {
        $runner = $this->createStub(CommandRunner::class);
        $runner->method('runProcess')->willReturn(['exit_code' => null, 'output' => '', 'error_output' => '']);

        $this->expectException(CollectorException::class);

        (new FlexRecipesCollector($runner))->collect();
    }

    public function testAFailureIsNotTakenForOutdatedRecipes(): void
    {
        $runner = $this->createStub(CommandRunner::class);
        $runner->method('runProcess')->willReturn([
            'exit_code' => 1,
            'output' => '',
            'error_output' => 'The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly',
        ]);

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('COMPOSER_HOME');

        (new FlexRecipesCollector($runner))->collect();
    }
}
