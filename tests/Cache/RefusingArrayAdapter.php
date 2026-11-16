<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Cache;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * An in-memory cache pool that, while refusing, saves nothing and says so, as a full or unreachable cache does.
 * Whatever it held before stays.
 */
final class RefusingArrayAdapter extends ArrayAdapter
{
    public bool $refusing = true;

    public function save(CacheItemInterface $item): bool
    {
        return !$this->refusing && parent::save($item);
    }
}
