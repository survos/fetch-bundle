<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Tests;

use Survos\FetchBundle\SurvosFetchBundle;
use Survos\JsonlBundle\SurvosJsonlBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    public function registerBundles(): array
    {
        return [
            new FrameworkBundle(),
            new SurvosKitBundle(),
            new SurvosJsonlBundle(),
            new SurvosFetchBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_client' => [],
                'messenger' => [],
            ]);
            $container->loadFromExtension('survos_fetch', [
                'persistent_cache_path' => sys_get_temp_dir() . '/fetch-bundle-tests/fetch_cache.db',
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/fetch-bundle-tests/cache/'.spl_object_hash($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/fetch-bundle-tests/log';
    }
}
