<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Fixtures\FrankenPhp;

use Jmonitor\Utils\UrlFetcher;

/**
 * Answers every URL with the same content, or fails like a closed port when there is none.
 */
final class FixtureUrlFetcher extends UrlFetcher
{
    public int $calls = 0;

    public function __construct(
        private readonly ?string $content,
    ) {}

    public function fetch(string $url): string
    {
        $this->calls++;

        if ($this->content === null) {
            throw new \RuntimeException(sprintf('Failed to fetch "%s": Connection refused', $url));
        }

        return $this->content;
    }
}
