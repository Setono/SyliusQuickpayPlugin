<?php

declare(strict_types=1);

use Setono\SyliusQuickpayPlugin\Tests\Application\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../../vendor/autoload.php';

(new Dotenv())->loadEnv(__DIR__ . '/../Application/.env', null, 'test');

$kernel = new Kernel('test', true);
$kernel->boot();

return new Application($kernel);
