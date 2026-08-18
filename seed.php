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
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE projects (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    website    TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE keywords (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL,
    phrase      TEXT    NOT NULL,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
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

    $pdo->exec('CREATE INDEX idx_positions_keyword_recorded ON positions (keyword_id, recorded_at)');
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

function insertProject(PDO $pdo, int $userId, string $name, string $website): int
{
    $stmt = $pdo->prepare('INSERT INTO projects (user_id, name, website, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $name, $website, date('Y-m-d H:i:s')]);
    return (int) $pdo->lastInsertId();
}

function insertKeyword(PDO $pdo, int $projectId, string $phrase, string $createdAt): int
{
    $stmt = $pdo->prepare('INSERT INTO keywords (project_id, phrase, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$projectId, $phrase, $createdAt]);
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

function seedKeyword(PDO $pdo, int $projectId, string $phrase, string $createdAt): int
{
    $keywordId = insertKeyword($pdo, $projectId, $phrase, $createdAt);
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

    // Demo user (S3). Password is hashed — never stored plaintext.
    // This is seeded demo data, not a real secret (see README "Demo account").
    $demoHash = password_hash('minirank', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
    $stmt->execute(['demo@example-shop.de', $demoHash]);
    $userId = (int) $pdo->lastInsertId();

    // Demo projects (S2): one tracked website per project.
    $shopProjectId = insertProject($pdo, $userId, 'Shop', 'https://www.example-shop.de');
    $blogProjectId = insertProject($pdo, $userId, 'Blog', 'https://www.example-blog.com');

    $createdAt = date('Y-m-d H:i:s', strtotime('-' . (NUM_DAYS - 1) . ' days'));

    // Split the 10 keywords: first 5 → Shop project, next 5 → Blog project.
    $shopKeywords = array_slice(KEYWORDS, 0, 5);
    $blogKeywords = array_slice(KEYWORDS, 5, 5);

    foreach ($shopKeywords as $phrase) {
        seedKeyword($pdo, $shopProjectId, $phrase, $createdAt);
    }
    foreach ($blogKeywords as $phrase) {
        seedKeyword($pdo, $blogProjectId, $phrase, $createdAt);
    }

    echo 'Seeded 2 projects, ' . count(KEYWORDS) . ' keywords, ' . (count(KEYWORDS) * NUM_DAYS) . ' positions into data/minirank.sqlite' . PHP_EOL;
    echo 'Demo user: demo@example-shop.de / minirank' . PHP_EOL;
}

main();
