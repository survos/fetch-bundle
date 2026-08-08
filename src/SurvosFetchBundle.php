<?php
declare(strict_types=1);

namespace Survos\FetchBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\FetchBundle\Cache\SqliteCachePoolFactory;
use Survos\FetchBundle\Contract\ConcurrentFetcherInterface;
use Survos\FetchBundle\Contract\PersistentFetcherInterface;
use Survos\FetchBundle\Contract\RetryStrategyInterface;
use Survos\FetchBundle\Fetch\SymfonyConcurrentFetcher;
use Survos\FetchBundle\Paginate\Command\FetchDownloadCommand;
use Survos\FetchBundle\Paginate\MessageHandler\PaginatedFetchMessageHandler;
use Survos\FetchBundle\Paginate\Service\Paginator;
use Survos\FetchBundle\Retry\ExponentialBackoffRetry;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\FetchBundle\Service\ChunkDownloader;
use Survos\FetchBundle\Service\PersistentFetcher;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosFetchBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('persistent_cache_path')
                    ->defaultValue('%kernel.project_dir%/var/data/fetch_cache.db')
                    ->info('SQLite file backing PersistentFetcher -- an app-controlled-TTL cache independent of what (if anything) the origin sends as Cache-Control/Expires. Deliberately outside %kernel.cache_dir% so it survives cache:clear.')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Auto-registers src/Command/ (and src/Controller/) per Survos conventions.
        parent::loadExtension($config, $container, $builder);

        // Core services
        $builder->autowire(ExponentialBackoffRetry::class)->setPublic(false);
        $builder->autowire(SymfonyConcurrentFetcher::class)->setPublic(false);
        $builder->autowire(ChunkDownloader::class)->setPublic(false);

        // App-controlled-TTL cached fetch, backed by a disposable SQLite pool (see PersistentFetcher).
        $poolId = SqliteCachePoolFactory::register(
            container: $container,
            idPrefix: 'survos_fetch',
            dbPath: $config['persistent_cache_path'],
        );
        $builder->autowire(PersistentFetcher::class)
            ->setArgument('$cache', new Reference($poolId))
            ->setPublic(false);
        if (!$builder->hasAlias(PersistentFetcherInterface::class)) {
            $builder->setAlias(PersistentFetcherInterface::class, PersistentFetcher::class)->setPublic(false);
        }

        // No-DB async JSONL paginator: message handler + kickoff service + demo command.
        // survos/jsonl-bundle is optional (see composer.json "suggest") -- only registered
        // when the app actually has it installed, so apps that just want PersistentFetcher/
        // SymfonyConcurrentFetcher/ChunkDownloader aren't forced to pull in JSONL tooling.
        if (class_exists(JsonlWriter::class)) {
            // Autoconfigured so #[AsMessageHandler] on the handler is picked up.
            $builder->autowire(PaginatedFetchMessageHandler::class)
                ->setAutoconfigured(true)
                ->setPublic(false);
            $builder->autowire(Paginator::class)
                ->setAutoconfigured(true)
                ->setPublic(true);
            $builder->autowire(FetchDownloadCommand::class)
                ->setAutoconfigured(true)
                ->setPublic(false);
        }

        // Interface aliases — only if the app hasn't provided its own implementation.
        if (!$builder->hasAlias(ConcurrentFetcherInterface::class)) {
            $builder->setAlias(ConcurrentFetcherInterface::class, SymfonyConcurrentFetcher::class)->setPublic(false);
        }
        if (!$builder->hasAlias(RetryStrategyInterface::class)) {
            $builder->setAlias(RetryStrategyInterface::class, ExponentialBackoffRetry::class)->setPublic(false);
        }
    }
}
