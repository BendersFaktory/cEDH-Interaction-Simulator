<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Charge .env puis .env.test (APP_ENV=test est forcé par phpunit.xml.dist).
(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
