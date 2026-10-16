<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector\Symfony;

use Jmonitor\EasyAdminBundle\Collector\Symfony\CommandRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;

final class CommandRunnerTest extends TestCase
{
    private CommandRunner $runner;

    protected function setUp(): void
    {
        $container = new Container();
        $container->set('event_dispatcher', new EventDispatcher());

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getBundles')->willReturn([]);
        $kernel->method('getProjectDir')->willReturn(__DIR__);
        $kernel->method('getContainer')->willReturn($container);

        $this->runner = new CommandRunner($kernel);
    }

    public function testAnUnknownCommandGivesNull(): void
    {
        self::assertNull($this->runner->run('non:existent:command'));
    }

    public function testItRunsACommand(): void
    {
        $this->register(new class ('test:output') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->write('hello');

                return Command::SUCCESS;
            }
        });

        self::assertSame(['exit_code' => 0, 'output' => 'hello'], $this->runner->run('test:output'));
    }

    public function testItRunsAProcessFromTheProjectDirectory(): void
    {
        $result = $this->runner->runPhpProcess(['-r', 'echo getcwd();'], 10);

        self::assertSame(0, $result['exit_code']);
        self::assertSame(realpath(__DIR__), realpath($result['output']));
    }

    public function testAProcessRunsAtNormalVerbosityUnderADebugKernel(): void
    {
        $env = $_ENV;
        $server = $_SERVER;
        $_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = 3;

        try {
            $result = $this->runner->runPhpProcess(['-r', 'echo getenv("SHELL_VERBOSITY");'], 10);
        } finally {
            $_ENV = $env;
            $_SERVER = $server;
        }

        self::assertSame('0', $result['output']);
    }

    private function register(Command $command): void
    {
        $application = $this->runner->getApplication();

        // Application::add() was replaced by addCommand() in Symfony 7.4 and removed in 8.0.
        if (method_exists($application, 'addCommand')) {
            $application->addCommand($command);
        } else {
            $application->add($command);
        }
    }
}
