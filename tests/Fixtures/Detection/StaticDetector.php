<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\Detection;

use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentDetectorInterface;
use Jmonitor\EasyAdminBundle\Detection\Detection;

final class StaticDetector implements ComponentDetectorInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly Component $component,
        private readonly Detection $detection,
    ) {}

    public function component(): Component
    {
        return $this->component;
    }

    public function detect(): Detection
    {
        $this->calls++;

        return $this->detection;
    }
}
