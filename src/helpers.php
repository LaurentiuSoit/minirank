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
