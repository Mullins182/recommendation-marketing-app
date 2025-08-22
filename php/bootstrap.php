<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

if (!isset($_ENV['AES_KEY_BASE64'])) {
    throw new RuntimeException('AES_KEY_BASE64 missing in .env');
}