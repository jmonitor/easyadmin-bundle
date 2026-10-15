<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Collector\Symfony\CommandRunner;
use Jmonitor\EasyAdminBundle\Collector\Symfony\FlexRecipesCollector;
use Jmonitor\EasyAdminBundle\Collector\Symfony\MessengerStatsCollector;
use Jmonitor\EasyAdminBundle\Collector\Symfony\SchedulerCollector;
use Jmonitor\EasyAdminBundle\Collector\Symfony\SymfonyCollector;
use Jmonitor\EasyAdminBundle\Component;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class SymfonyDetector implements ComponentDetectorInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private bool $messenger,
        private bool $scheduler,
        private bool $flex,
    ) {}

    public function component(): Component
    {
        return Component::Symfony;
    }

    public function detect(): Detection
    {
        $components = [];

        if ($this->messenger || $this->scheduler || $this->flex) {
            $runner = new CommandRunner($this->kernel);

            if ($this->scheduler) {
                $components['scheduler'] = new SchedulerCollector($runner);
            }

            if ($this->flex) {
                $components['flex_recipes'] = new FlexRecipesCollector($runner);
            }

            if ($this->messenger) {
                $components['messenger'] = new MessengerStatsCollector($runner);
            }
        }

        return Detection::found(new CollectorSet(Component::Symfony, [
            new CollectorSpec(new SymfonyCollector($this->kernel, $components), Lifetime::Static),
        ]));
    }
}
