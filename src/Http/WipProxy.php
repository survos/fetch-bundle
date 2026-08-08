<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Http;

/**
 * Local dev convention across the Survos apps: any `*.wip` host is served through Symfony CLI's
 * local proxy (`symfony local:proxy:start`, listening on 127.0.0.1:7080 by default), since `.wip`
 * isn't real DNS and only resolves inside a browser that's been pointed at the proxy. A
 * server-to-server HttpClient call needs the same routing explicitly via the `proxy` request
 * option — see the CLAUDE.md memory "Verify .wip domains via symfony proxy".
 *
 * Centralizes what was independently written as `if (str_contains($endpoint, '.wip')) { $options['proxy']
 * = 'http://127.0.0.1:7080'; }` six times across ssai's own services (AiToolsClient,
 * MediaryEnrichmentService, NarrationTranscriptionService, ObserveService, ImageDescribeService,
 * ImageController) — and depot took a third, divergent approach (a manually-synced
 * `SSAI_HTTP_PROXY`-style env var per scoped client) that silently broke when `SSAI_HUB_URL` was
 * pointed at a `.wip` host without anyone setting the matching proxy var (2026-08-07). One correct
 * implementation, derived from the URL itself, instead of N almost-correct ones — see
 * CachingHttpClientFactory's own doc comment for the same reasoning applied to response caching.
 */
final class WipProxy
{
    public const string DEFAULT_PROXY = 'http://127.0.0.1:7080';

    /**
     * @return array{proxy?: string} merge into an HttpClient request's $options
     */
    public static function optionsFor(?string $url, string $proxy = self::DEFAULT_PROXY): array
    {
        return self::appliesTo($url) ? ['proxy' => $proxy] : [];
    }

    public static function appliesTo(?string $url): bool
    {
        return $url !== null && str_contains($url, '.wip');
    }
}
