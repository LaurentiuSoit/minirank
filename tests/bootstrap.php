<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec('CREATE TABLE keywords (
    id INTEGER PRIMARY KEY AUTOINCREMENT
)');

$pdo->exec('CREATE TABLE positions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    keyword_id  INTEGER NOT NULL,
    position    INTEGER NOT NULL,
    recorded_at TEXT    NOT NULL,
    UNIQUE (keyword_id, recorded_at)
)');

$GLOBALS['testPdo'] = $pdo;