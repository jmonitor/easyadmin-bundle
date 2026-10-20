<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Twig;

use Jmonitor\EasyAdminBundle\Twig\AssetExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\UrlPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Asset\VersionStrategy\StaticVersionStrategy;

final class AssetExtensionTest extends TestCase
{
    private string $installed;
    private string $source;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/jmonitor_assets_' . bin2hex(random_bytes(4));
        $this->installed = $root . '/installed';
        $this->source = $root . '/source';
        mkdir($this->installed, recursive: true);
        mkdir($this->source);
    }

    protected function tearDown(): void
    {
        foreach ([$this->installed, $this->source] as $directory) {
            array_map(unlink(...), glob($directory . '/*') ?: []);
            rmdir($directory);
        }
        rmdir(\dirname($this->installed));
    }

    public function testTheVersionFollowsTheInstalledCopy(): void
    {
        $extension = $this->extension(new PathPackage('', new EmptyVersionStrategy()));
        file_put_contents($this->source . '/app.css', 'new {}');
        file_put_contents($this->installed . '/app.css', 'old {}');
        $stale = $extension->url('app.css');

        file_put_contents($this->installed . '/app.css', 'new {}');

        self::assertMatchesRegularExpression('#^/bundles/jmonitoreasyadmin/app\.css\?v=[0-9a-f]{8}$#', $stale);
        self::assertNotSame($stale, $extension->url('app.css'), 'installing the assets changes the URL');
    }

    public function testTheSourceVersionsAnAssetInstalledElsewhere(): void
    {
        $extension = $this->extension(new PathPackage('', new EmptyVersionStrategy()));
        file_put_contents($this->source . '/app.js', 'a');
        $before = $extension->url('app.js');

        file_put_contents($this->source . '/app.js', 'b');

        self::assertNotSame($before, $extension->url('app.js'));
    }

    public function testAMissingFileIsNotVersioned(): void
    {
        $extension = $this->extension(new PathPackage('', new EmptyVersionStrategy()));

        self::assertSame('/bundles/jmonitoreasyadmin/missing.js', $extension->url('missing.js'));
    }

    public function testTheApplicationPackageStillApplies(): void
    {
        $extension = $this->extension(new UrlPackage('https://cdn.example.com', new StaticVersionStrategy('v1')));
        file_put_contents($this->source . '/app.js', 'a');

        self::assertMatchesRegularExpression('#^https://cdn\.example\.com/bundles/jmonitoreasyadmin/app\.js\?v1&v=[0-9a-f]{8}$#', $extension->url('app.js'));
    }

    private function extension(Package $package): AssetExtension
    {
        return new AssetExtension(new Packages($package), [$this->installed, $this->source]);
    }
}
