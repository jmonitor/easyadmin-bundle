<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Collector;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A request that could not be sent fails the next ones without a new attempt: each would wait
 * for the same timeout.
 */
final class FailFastHttpClient implements ClientInterface
{
    private ?ClientExceptionInterface $failure = null;

    public function __construct(
        private readonly ClientInterface $inner,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            return $this->inner->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->failure = $e;

            throw $e;
        }
    }

    public function hasFailed(): bool
    {
        return $this->failure !== null;
    }
}
