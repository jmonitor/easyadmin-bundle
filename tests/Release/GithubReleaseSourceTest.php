<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Release;

use Jmonitor\EasyAdminBundle\Release\GithubReleaseSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GithubReleaseSourceTest extends TestCase
{
    public function testTheTagLosesItsV(): void
    {
        $release = GithubReleaseSource::fromResponse(['tag_name' => 'v2.4.0', 'html_url' => 'https://github.com/jmonitor/collector/releases/tag/v2.4.0']);

        self::assertSame('2.4.0', $release->version);
        self::assertSame('https://github.com/jmonitor/collector/releases/tag/v2.4.0', $release->url);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidResponses(): iterable
    {
        yield 'empty' => [[]];
        yield 'empty tag' => [['tag_name' => '', 'html_url' => 'u']];
        yield 'no url' => [['tag_name' => 'v1', 'html_url' => null]];
        yield 'rate limited' => [['message' => 'API rate limit exceeded']];
        yield 'script url' => [['tag_name' => 'v1.4.0', 'html_url' => 'javascript:alert(1)']];
        yield 'url elsewhere' => [['tag_name' => 'v1.4.0', 'html_url' => 'https://example.com/jmonitor/collector/releases/tag/v1.4.0']];
    }

    /**
     * @param array<mixed> $response
     */
    #[DataProvider('invalidResponses')]
    public function testAResponseWithoutTagIsRefused(array $response): void
    {
        $this->expectException(\RuntimeException::class);

        GithubReleaseSource::fromResponse($response);
    }
}
