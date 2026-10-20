<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Jmonitor\EasyAdminBundle\JmonitorEasyAdminBundle;
use Jmonitor\EasyAdminBundle\Metric\EndOfLife;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\App\DashboardController;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp\FixtureUrlFetcher;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

/**
 * The bundle's controller needs Twig, Security and EasyAdmin, so even the unit-level kernel
 * boots the full admin stack, on a SQLite in-memory database. endoflife.date is never reached.
 */
class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly array $frameworkConfig = [],
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new TwigComponentBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new EasyAdminBundle();
        yield new JmonitorEasyAdminBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__ . '/App';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/jmonitor_easyadmin_bundle/' . md5(serialize([$this->bundleConfig, $this->frameworkConfig])) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/jmonitor_easyadmin_bundle/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', array_replace_recursive([
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router' => ['utf8' => true],
            'assets' => [],
            'translator' => ['fallbacks' => ['en']],
        ], $this->frameworkConfig));

        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => [],
        ]);

        $container->extension('security', [
            'password_hashers' => [InMemoryUser::class => 'plaintext'],
            'providers' => ['in_memory' => ['memory' => ['users' => [
                'admin' => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                'viewer' => ['password' => 'viewer', 'roles' => ['ROLE_USER']],
                'super' => ['password' => 'super', 'roles' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']],
            ]]]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'in_memory', 'logout' => ['path' => '/logout']]],
            'access_control' => [['path' => '^/admin', 'roles' => 'ROLE_ADMIN']],
        ]);

        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => ['auto_mapping' => true],
        ]);

        $container->extension('jmonitor_easyadmin', $this->bundleConfig);

        $container->services()
            ->set(DashboardController::class)
            ->autowire()
            ->autoconfigure()
            ->tag('controller.service_arguments')
        ;
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(EndOfLife::class)->setArgument('$fetcher', new Definition(FixtureUrlFetcher::class, [null]));
            }
        });
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'easyadmin.routes');
    }
}
