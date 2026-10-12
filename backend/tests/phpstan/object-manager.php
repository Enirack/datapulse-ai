<?php

declare(strict_types=1);

// Bootstraps a Doctrine ObjectManager for phpstan-doctrine's repository/
// query-builder type inference. Never actually connects — it only needs
// the mapping metadata, not a live database.

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
