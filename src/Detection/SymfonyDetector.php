<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Detection;

use Jmonitor\EasyAdminBundle\Collector\CollectorSet;
use Jmonitor\EasyAdminBundle\Collector\CollectorSpec;
use Jmonitor\EasyAdminBundle\Collector\Lifetime;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Collector\Components\MessengerStatsCollector;
use Jmonitor\JmonitorBundle\Collector\Components\SchedulerCollector;
use Jmonitor\JmonitorBundle\Collector\SymfonyCollector;
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
