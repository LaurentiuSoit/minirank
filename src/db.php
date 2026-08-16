<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

const DATA_DIR = __DIR__ . '/../data';
const DB_PATH = DATA_DIR . '/minirank.sqlite';
const SITE_URL = 'https://www.example-shop.de';

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }
}

function getPdo(): PDO
{
    ensureDataDir();
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}
