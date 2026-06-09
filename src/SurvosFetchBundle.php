<?php
declare(strict_types=1);

namespace Survos\FetchBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\FetchBundle\Contract\ConcurrentFetcherInterface;
use Survos\FetchBundle\Contract\RetryStrategyInterface;
use Survos\FetchBundle\Fetch\SymfonyConcurrentFetcher;
use Survos\FetchBundle\Paginate\MessageHandler\PaginatedFetchMessageHandler;
use Survos\FetchBundle\Paginate\Service\Paginator;
use Survos\FetchBundle\Retry\ExponentialBackoffRetry;
use Survos\FetchBundle\Service\ChunkDownloader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class SurvosFetchBundle extends AbstractSurvosBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Auto-registers src/Command/ (and src/Controller/) per Survos conventions.
        parent::loadExtension($config, $container, $builder);

        // Core services
        $builder->autowire(ExponentialBackoffRetry::class)->setPublic(false);
        $builder->autowire(SymfonyConcurrentFetcher::class)->setPublic(false);
        $builder->autowire(ChunkDownloader::class)->setPublic(false);

        // No-DB async JSONL paginator: message handler + kickoff service.
        // Autoconfigured so #[AsMessageHandler] on the handler is picked up.
        $builder->autowire(PaginatedFetchMessageHandler::class)
            ->setAutoconfigured(true)
            ->setPublic(false);
        $builder->autowire(Paginator::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        // Interface aliases — only if the app hasn't provided its own implementation.
        if (!$builder->hasAlias(ConcurrentFetcherInterface::class)) {
            $builder->setAlias(ConcurrentFetcherInterface::class, SymfonyConcurrentFetcher::class)->setPublic(false);
        }
        if (!$builder->hasAlias(RetryStrategyInterface::class)) {
            $builder->setAlias(RetryStrategyInterface::class, ExponentialBackoffRetry::class)->setPublic(false);
        }
    }
}
