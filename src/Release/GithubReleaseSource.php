<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Release;

/**
 * GitHub refuses the requests without a User-Agent.
 */
final readonly class GithubReleaseSource implements ReleaseSource
{
    private const URL = 'https://api.github.com/repos/%s/releases/latest';

    public function __construct(
        private int $timeout = 3,
    ) {}

    public function latest(string $repository): LatestRelease
    {
        $url = sprintf(self::URL, $repository);
        $context = stream_context_create(['http' => [
            'timeout' => $this->timeout,
            'header' => "User-Agent: jmonitor-easyadmin-bundle\r\nAccept: application/vnd.github+json\r\n",
        ]]);

        error_clear_last();
        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new \RuntimeException(sprintf('Failed to fetch "%s": %s', $url, error_get_last()['message'] ?? 'unknown error'));
        }

        $response = json_decode($content, true);

        if (!is_array($response)) {
            throw new \RuntimeException(sprintf('Failed to decode the JSON of "%s".', $url));
        }

        return self::fromResponse($response);
    }

    /**
     * @param array<mixed> $response
     */
    public static function fromResponse(array $response): LatestRelease
    {
        $tag = $response['tag_name'] ?? null;
        $url = $response['html_url'] ?? null;

        if (!is_string($tag) || $tag === '' || !is_string($url) || $url === '') {
            throw new \RuntimeException('No release in the response.');
        }

        return new LatestRelease(ltrim($tag, 'v'), $url);
    }
}
