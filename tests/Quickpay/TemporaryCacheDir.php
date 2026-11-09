<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A fresh directory per test for the SDK's mapper cache, removed after the test, for a unit test that builds the real
 * client provider
 */
trait TemporaryCacheDir
{
    private string $cacheDir;

    /**
     * @before
     */
    protected function createCacheDir(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/setono_sylius_quickpay_' . bin2hex(random_bytes(4));
    }

    /**
     * @after
     */
    protected function removeCacheDir(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }
}
