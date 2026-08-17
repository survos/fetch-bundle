<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorates an HttpClient (typically a scoped client already configured with a fixed `base_uri`
 * in framework.yaml) so that requests to a `.wip` base_uri automatically route through Symfony
 * CLI's local proxy — see {@see WipProxy} for why this exists.
 *
 * With a $baseUri, the `.wip`-ness check runs once at construction against that value (the
 * scoped client's own config, e.g. `%env(SSAI_HUB_URL)%`) rather than per-request against $url —
 * a scoped client's requests pass a path relative to its baked-in base_uri, so the host isn't
 * reliably visible in $url itself.
 *
 * Omit $baseUri and the check moves to each request's own $url, which is what a client with no
 * single destination needs — see request().
 *
 * Usage, decorating a scoped client from an app's own services.yaml (bundles wanting the same
 * thing from their own loadExtension() should register this directly rather than adding a
 * factory — the wiring here is three lines):
 *
 *     Survos\FetchBundle\Http\WipProxyHttpClient:
 *         decorates: 'ssai.hub'
 *         arguments:
 *             $client: '@.inner'
 *             $baseUri: '%env(SSAI_HUB_URL)%'
 *
 * Or, for a client that posts to many different hosts (webhook delivery):
 *
 *     survos.webhook.http_client:
 *         class: Survos\FetchBundle\Http\WipProxyHttpClient
 *         arguments:
 *             $client: '@http_client'
 */
final class WipProxyHttpClient implements HttpClientInterface, ResetInterface
{
    /** null when no $baseUri was given — decide per request instead. See request(). */
    private readonly ?bool $applies;

    public function __construct(
        private HttpClientInterface $client,
        ?string $baseUri = null,
        private readonly string $proxy = WipProxy::DEFAULT_PROXY,
    ) {
        $this->applies = $baseUri === null ? null : WipProxy::appliesTo($baseUri);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Two modes, and the difference is not a style choice:
        //
        //   $baseUri given  — a SCOPED client. Its callers pass a path, not a URL, so $url
        //                     carries no host to test; the decision has to come from the
        //                     configured base_uri once, at construction.
        //   $baseUri null   — a FAN-OUT client, where every request goes somewhere different
        //                     and the host is right there in $url. Webhook delivery is the
        //                     motivating case: one client posts to every subscriber's own
        //                     callback URL, and only the `.wip` ones need the proxy.
        $applies = $this->applies ?? WipProxy::appliesTo($url);

        if ($applies && !isset($options['proxy'])) {
            $options['proxy'] = $this->proxy;
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
