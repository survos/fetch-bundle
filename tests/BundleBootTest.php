<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Tests;

use Survos\FetchBundle\Contract\ConcurrentFetcherInterface;
use Survos\FetchBundle\Contract\PersistentFetcherInterface;
use Survos\FetchBundle\Contract\RetryStrategyInterface;

final class BundleBootTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testContainerCompilesAndCoreServicesResolve(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertInstanceOf(PersistentFetcherInterface::class, $container->get(PersistentFetcherInterface::class));
        self::assertInstanceOf(ConcurrentFetcherInterface::class, $container->get(ConcurrentFetcherInterface::class));
        self::assertInstanceOf(RetryStrategyInterface::class, $container->get(RetryStrategyInterface::class));
    }

    public function testPaginateSliceIsRegisteredWhenJsonlBundleIsInstalled(): void
    {
        // require-dev pulls in survos/jsonl-bundle, so in this test environment the
        // class_exists(JsonlWriter::class) guard in SurvosFetchBundle::loadExtension()
        // should be true and register the Paginate/* slice.
        self::assertTrue(class_exists(\Survos\JsonlBundle\IO\JsonlWriter::class), 'test fixture assumption: jsonl-bundle should be installed via require-dev');

        self::bootKernel();
        $container = static::getContainer();

        self::assertInstanceOf(
            \Survos\FetchBundle\Paginate\Service\Paginator::class,
            $container->get(\Survos\FetchBundle\Paginate\Service\Paginator::class),
        );
    }
}
