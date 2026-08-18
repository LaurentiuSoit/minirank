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

// Batch version of getKeywordTrend() — fetches the 7-day trend for many
// keywords in ONE query instead of N+1. Used by the keyword list page (S4).
// Returns [keywordId => 'improved'|'declined'|'stable'|null].
function getKeywordTrends(PDO $pdo, array $keywordIds): array
{
    if (empty($keywordIds)) {
        return [];
    }

    // Build an IN(?, ?, ...) placeholder string — one '?' per ID.
    // This produces placeholder *syntax* only; every value is bound below.
    $inPlaceholders = str_repeat('?,', count($keywordIds) - 1) . '?';

    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));

    $stmt = $pdo->prepare(
        'SELECT keyword_id, position, recorded_at
         FROM positions
         WHERE keyword_id IN (' . $inPlaceholders . ')
           AND recorded_at IN (?, ?)
         ORDER BY keyword_id, recorded_at ASC'
    );

    $params = array_values($keywordIds);
    $params[] = $weekAgo;  // [0] per keyword = older (previous)
    $params[] = $today;    // [1] per keyword = newer (current)
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group the two positions (week-ago, today) per keyword.
    // ORDER BY keyword_id, recorded_at ASC guarantees positions[0] < positions[1]
    // chronologically, so [0] = previous and [1] = current.
    $positionsByKeyword = [];
    foreach ($rows as $row) {
        $positionsByKeyword[(int) $row['keyword_id']][] = (int) $row['position'];
    }

    $trends = [];
    foreach ($positionsByKeyword as $kwId => $positions) {
        if (count($positions) >= 2) {
            $trends[$kwId] = calculateTrend($positions[1], $positions[0]);
        } else {
            // Only one of {weekAgo, today} exists → not enough data.
            $trends[$kwId] = null;
        }
    }

    // Ensure every requested ID appears in the result (keywords with zero
    // position rows get null), so the caller never hits an undefined key.
    foreach ($keywordIds as $id) {
        $key = (int) $id;
        if (!isset($trends[$key])) {
            $trends[$key] = null;
        }
    }

    return $trends;
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

// --- HTTP response helpers (shared so auth/CSRF code in this file can use them) ---

function redirect(string $path): void
{
    // 303 See Other: correct status for POST -> GET redirect (PRG pattern).
    // $path must be a server-relative path starting with '/', never user-supplied.
    http_response_code(303);
    header('Location: ' . $path);
    exit;
}

function sendNotFound(string $message = 'Not Found'): void
{
    http_response_code(404);
    echo escape($message);
    exit;
}

function sendBadRequest(string $message = 'Bad Request'): void
{
    http_response_code(400);
    echo escape($message);
    exit;
}

// --- Auth + CSRF helpers (S3) ---

function currentUserId(): ?int
{
    $id = $_SESSION['user_id'] ?? null;
    return is_int($id) && $id > 0 ? $id : null;
}

function isLoggedIn(): bool
{
    return currentUserId() !== null;
}

// Returns the session-bound CSRF token, generating one on first use.
// 32 bytes (256 bits) of randomness, hex-encoded = 64 chars.
function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verifies the CSRF token on POST requests. The auth routes (login/register/logout)
// are NOT exempt — login-CSRF is a real attack — so this runs for every POST.
function verifyCsrf(): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if ($sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        sendBadRequest('Invalid or missing CSRF token.');
    }
}

// Redirects to /login when there is no authenticated user. Called for every
// route except register/login/logout.
function requireAuth(): void
{
    if (currentUserId() === null) {
        redirect('/login');
    }
}

// --- Project helpers (S2) ---

// Fetches a project row only if it belongs to $userId (ownership check).
// Returns null when the project doesn't exist or belongs to another user —
// the caller treats both cases as 404 to avoid leaking existence.
function getProjectForUser(PDO $pdo, int $projectId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? AND user_id = ?');
    $stmt->execute([$projectId, $userId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    return $project ?: null;
}

// Returns the user's most-recently-created project (for the / → redirect).
// Returns null when the user has zero projects.
function getLatestProjectForUser(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    return $project ?: null;
}

// Returns all projects for a user, ordered by id — used by the project switcher.
function getUserProjects(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, name, website FROM projects WHERE user_id = ? ORDER BY id ASC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
