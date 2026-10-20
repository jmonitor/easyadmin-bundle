<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Twig;

use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AssetExtension extends AbstractExtension
{
    private const PATH = 'bundles/jmonitoreasyadmin/';

    /**
     * @param list<string> $directories searched in order for the file whose hash versions the URL
     */
    public function __construct(
        private readonly Packages $packages,
        private readonly array $directories,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jm_asset', $this->url(...)),
        ];
    }

    public function url(string $file): string
    {
        $url = $this->packages->getUrl(self::PATH . $file);
        $version = $this->version($file);

        if ($version === null) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
    }

    private function version(string $file): ?string
    {
        foreach ($this->directories as $directory) {
            $path = $directory . '/' . $file;
            $hash = is_file($path) ? hash_file('xxh128', $path) : false;

            if ($hash !== false) {
                return substr($hash, 0, 8);
            }
        }

        return null;
    }
}
