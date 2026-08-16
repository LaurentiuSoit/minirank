<?php
declare(strict_types=1);

require __DIR__ . '/src/db.php';

const KEYWORDS = [
    'best running shoes',
    'wireless headphones',
    'ergonomic office chair',
    'mechanical keyboard',
    '4k monitor',
    'premium yoga mat',
    'automatic coffee maker',
    'robot vacuum cleaner',
    'bluetooth speaker',
    'standing desk converter',
];
const NUM_DAYS = 30;

function createSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE keywords (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    phrase     TEXT    NOT NULL,
    website    TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE positions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    keyword_id  INTEGER NOT NULL,
    position    INTEGER NOT NULL CHECK (position >= 1 AND position <= 100),
    recorded_at TEXT    NOT NULL,
    UNIQUE (keyword_id, recorded_at),
    FOREIGN KEY (keyword_id) REFERENCES keywords (id) ON DELETE CASCADE
)
SQL);

    $pdo->exec('CREATE INDEX idx_positions_recorded_at ON positions (recorded_at)');
}

function clamp(int $v, int $min, int $max): int
{
    if ($v < $min) {
        return $min;
    }
    if ($v > $max) {
        return $max;
    }
    return $v;
}

function insertKeyword(PDO $pdo, string $phrase, string $website, string $createdAt): int
{
    $stmt = $pdo->prepare('INSERT INTO keywords (phrase, website, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$phrase, $website, $createdAt]);
    return (int) $pdo->lastInsertId();
}

function insertPosition(PDO $pdo, int $keywordId, int $position, string $date): void
{
    $stmt = $pdo->prepare('INSERT INTO positions (keyword_id, position, recorded_at) VALUES (?, ?, ?)');
    $stmt->execute([$keywordId, $position, $date]);
}

function generateWalk(int $base, int $days): array
{
    $walk = [];
    $position = $base;
    for ($i = $days - 1; $i >= 0; $i--) {
        if ($i < $days - 1) {
            $position = clamp($position + random_int(-3, 3), 1, 100);
        }
        $walk[date('Y-m-d', strtotime('-' . $i . ' days'))] = $position;
    }
    return $walk;
}

function seedKeyword(PDO $pdo, string $phrase, string $website, string $createdAt): int
{
    $keywordId = insertKeyword($pdo, $phrase, $website, $createdAt);
    $base = random_int(20, 80);
    $walk = generateWalk($base, NUM_DAYS);
    foreach ($walk as $date => $position) {
        insertPosition($pdo, $keywordId, $position, $date);
    }
    return $keywordId;
}

function main(): void
{
    ensureDataDir();
    if (file_exists(DB_PATH)) {
        unlink(DB_PATH);
    }
    $pdo = getPdo();
    createSchema($pdo);
    $createdAt = date('Y-m-d H:i:s', strtotime('-' . (NUM_DAYS - 1) . ' days'));
    foreach (KEYWORDS as $phrase) {
        seedKeyword($pdo, $phrase, SITE_URL, $createdAt);
    }
    echo 'Seeded ' . count(KEYWORDS) . ' keywords, ' . (count(KEYWORDS) * NUM_DAYS) . ' positions into data/minirank.sqlite' . PHP_EOL;
}

main();
