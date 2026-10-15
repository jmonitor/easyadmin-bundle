<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector\Symfony;

use Jmonitor\EasyAdminBundle\Collector\Symfony\CommandRunner;
use Jmonitor\EasyAdminBundle\Collector\Symfony\SchedulerCollector;
use Jmonitor\Exceptions\CollectorException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\NamespaceNotFoundException;

final class SchedulerCollectorTest extends TestCase
{
    public function testItParsesTheTasksOfDebugScheduler(): void
    {
        $output = <<<EOF
             --------------------------------------- ----------------------------------------------------------------------------------------------------- ---------------------------------
              Trigger                                 Provider                                                                                              Next Run
             --------------------------------------- ----------------------------------------------------------------------------------------------------- ---------------------------------
              every 3 hour                            Symfony\Component\Console\Messenger\RunCommandMessage (app:foo)                                       Sat, 20 Dec 2025 03:20:00 +0100
              every 24 hours with 0-5 second jitter   Symfony\Component\Console\Messenger\RunCommandMessage (app:bar)                                       Sat, 20 Dec 2025 02:05:03 +0100
              0 0 * * *                               Symfony\Component\Console\Messenger\RunCommandMessage (app:bar:foo)                                   Sun, 21 Dec 2025 00:00:00 +0100
             --------------------------------------- ----------------------------------------------------------------------------------------------------- ---------------------------------
            EOF;

        $result = (new SchedulerCollector($this->runner($output)))->collect();

        self::assertCount(3, $result);
        self::assertSame(
            ['trigger' => 'every 3 hour', 'command' => 'app:foo', 'arguments' => [], 'next_run' => 1_766_197_200, 'description' => 'Description of app:foo'],
            $result[0],
        );
        self::assertSame('every 24 hours with 0-5 second jitter', $result[1]['trigger']);
        self::assertSame('0 0 * * *', $result[2]['trigger']);
        self::assertSame(1_766_271_600, $result[2]['next_run']);
    }

    public function testItSplitsTheCommandArguments(): void
    {
        $output = <<<EOF
              every 6 hours      Symfony\Component\Console\Messenger\RunCommandMessage ('app:artist:publish-ready' --apply --limit 100)           Sat, 20 Dec 2025 06:29:00 +0100
              every 12 hours     Symfony\Component\Console\Messenger\RunCommandMessage ("app:artist:publish-ready" --apply --limit 100)           Sat, 20 Dec 2025 12:29:00 +0100
              every 24 hours     Symfony\Component\Console\Messenger\RunCommandMessage ('app:report' --label='a) b' "--to=ops team")              Sun, 21 Dec 2025 00:00:00 +0100
              every 48 hours     Symfony\Component\Console\Messenger\RunCommandMessage ('app:gone' --force)                                       Mon, 22 Dec 2025 00:00:00 +0100
            EOF;

        $result = (new SchedulerCollector($this->runner($output)))->collect();

        self::assertCount(4, $result);
        self::assertSame('app:artist:publish-ready', $result[0]['command']);
        self::assertSame(['--apply', '--limit', '100'], $result[0]['arguments']);
        self::assertSame('app:artist:publish-ready', $result[1]['command']);
        self::assertSame(['--label=a) b', '--to=ops team'], $result[2]['arguments']);
        self::assertSame(['--force'], $result[3]['arguments']);
        self::assertNull($result[3]['description'], 'the command no longer exists');
    }

    public function testAMissingDebugSchedulerFails(): void
    {
        $runner = $this->createStub(CommandRunner::class);
        $runner->method('run')->willReturn(null);

        $this->expectException(CollectorException::class);

        (new SchedulerCollector($runner))->collect();
    }

    private function runner(string $output): CommandRunner
    {
        $application = $this->createStub(Application::class);
        $application->method('find')->willReturnCallback(function (string $name): Command {
            if ($name === 'app:gone') {
                throw new NamespaceNotFoundException('There are no commands defined in the "app" namespace.');
            }

            $command = $this->createStub(Command::class);
            $command->method('getDescription')->willReturn('Description of ' . $name);

            return $command;
        });

        $runner = $this->createStub(CommandRunner::class);
        $runner->method('getApplication')->willReturn($application);
        $runner->method('run')->willReturn(['exit_code' => 0, 'output' => $output]);

        return $runner;
    }
}
