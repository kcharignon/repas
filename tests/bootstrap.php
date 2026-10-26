<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// `make test-fast` (ParaTest) : une base de données par processus ; sans TEST_TOKEN (`make test`), rien ne change
Repas\Tests\Helper\ParallelTestDatabase::prepareProcess();
