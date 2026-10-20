<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Asset;

use Jmonitor\EasyAdminBundle\Asset\ContentHashVersionStrategy;
use PHPUnit\Framework\TestCase;

final class ContentHashVersionStrategyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jmonitor_assets_' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function testTheVersionFollowsTheContent(): void
    {
        $strategy = new ContentHashVersionStrategy($this->directory);
        file_put_contents($this->directory . '/app.css', 'a {}');
        $first = $strategy->applyVersion('app.css');

        file_put_contents($this->directory . '/app.css', 'b {}');

        self::assertMatchesRegularExpression('/^app\.css\?v=[0-9a-f]{8}$/', $first);
        self::assertNotSame($first, $strategy->applyVersion('app.css'));
    }

    public function testAMissingFileIsNotVersioned(): void
    {
        $strategy = new ContentHashVersionStrategy($this->directory);

        self::assertSame('', $strategy->getVersion('missing.js'));
        self::assertSame('missing.js', $strategy->applyVersion('missing.js'));
    }
}
