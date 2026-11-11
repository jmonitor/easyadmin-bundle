<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Tests\Collector;

use Jmonitor\EasyAdminBundle\Collector\FailFastHttpClient;
use Jmonitor\EasyAdminBundle\Tests\Fixtures\Cloudflare\FixtureCloudflareClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class FailFastHttpClientTest extends TestCase
{
    public function testAnAnswerIsPassedOn(): void
    {
        $inner = new FixtureCloudflareClient();
        $client = new FailFastHttpClient($inner);

        $response = $client->sendRequest(new Request('GET', 'https://api.cloudflare.com/client/v4/zones'));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($client->hasFailed());
    }

    public function testAnErrorStatusIsNotAFailure(): void
    {
        $client = new FailFastHttpClient(new FixtureCloudflareClient(['GET /zones' => 500]));

        self::assertSame(500, $client->sendRequest(new Request('GET', 'https://api.cloudflare.com/client/v4/zones'))->getStatusCode());
        self::assertFalse($client->hasFailed());
    }

    public function testTheAnswersToAskAgainLaterAreCounted(): void
    {
        $client = new FailFastHttpClient(new FixtureCloudflareClient(['GET /zones' => 500, 'GET /accounts' => 429, 'GET /user' => 403, 'GET /memberships' => 404]));
        self::assertSame(0, $client->serverErrors());

        foreach (['/zones', '/accounts', '/user', '/memberships'] as $path) {
            $client->sendRequest(new Request('GET', 'https://api.cloudflare.com/client/v4' . $path));
        }

        self::assertSame(2, $client->serverErrors(), 'a refused or unknown route answers the same the next time');
    }

    public function testARequestThatCouldNotBeSentFailsTheNextOnesWithoutAnAttempt(): void
    {
        $inner = new FixtureCloudflareClient(down: true);
        $client = new FailFastHttpClient($inner);
        $failures = [];

        foreach (['/zones', '/graphql'] as $path) {
            try {
                $client->sendRequest(new Request('GET', 'https://api.cloudflare.com/client/v4' . $path));
                self::fail('no exception');
            } catch (ClientExceptionInterface $e) {
                $failures[] = $e;
            }
        }

        self::assertTrue($client->hasFailed());
        self::assertSame(['GET /zones'], $inner->routes, 'the second request waits for no timeout');
        self::assertSame($failures[0], $failures[1]);
    }
}
