<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function validateIntId(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        return null;
    }
    return $id;
}

function validateString(mixed $value, int $maxLength): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '' || strlen($value) > $maxLength) {
        return null;
    }
    return $value;
}

function calculateTrend(int $currentPosition, int $previousPosition): string
{
    if ($currentPosition < $previousPosition) {
        return 'improved';
    }
    if ($currentPosition > $previousPosition) {
        return 'declined';
    }
    return 'stable';
}

function getKeywordTrend(PDO $pdo, int $keywordId): ?string
{
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));

    $stmt = $pdo->prepare(
        'SELECT position, recorded_at FROM positions WHERE keyword_id = ? AND recorded_at IN (?, ?) ORDER BY recorded_at'
    );
    $stmt->execute([$keywordId, $weekAgo, $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) < 2) {
        return null;
    }

    $previousPosition = (int) $rows[0]['position'];
    $currentPosition = (int) $rows[1]['position'];

    return calculateTrend($currentPosition, $previousPosition);
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sanitizeFilename(string $phrase, int $id): string
{
    $slug = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $phrase);
    $slug = preg_replace('/\s+/', '-', trim($slug));
    $slug = substr($slug, 0, 50);

    if ($slug === '' || $slug === '-') {
        return 'keyword-' . $id . '.csv';
    }
    return $slug . '.csv';
}
