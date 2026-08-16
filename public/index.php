<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

// --- Response helpers ---

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

function redirect(string $path): void
{
    // 303 See Other: correct status for POST -> GET redirect (PRG pattern).
    // $path must be a server-relative path starting with '/', never user-supplied.
    http_response_code(303);
    header('Location: ' . $path);
    exit;
}

function sendJson(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function renderPage(string $title, string $viewTemplate, array $data = []): void
{
    // extract() makes $data keys available as variables in the view.
    // EXTR_SKIP prevents overwriting $title.
    extract($data, EXTR_SKIP);

    // Capture the view template's HTML output.
    ob_start();
    require __DIR__ . '/../views/' . $viewTemplate;
    $content = ob_get_clean();

    // Render within the shared layout.
    require __DIR__ . '/../views/layout.php';
}

// --- Handler stubs (implemented in steps 6-9) ---

function handleList(): void
{
    $pdo = getPdo();

    $searchTerm = validateString($_GET['q'] ?? null, 100);

    $sql = 'SELECT k.id, k.phrase, k.website, p.position
            FROM keywords k
            LEFT JOIN positions p
              ON p.id = (SELECT id FROM positions
                         WHERE keyword_id = k.id
                         ORDER BY recorded_at DESC, id DESC
                         LIMIT 1)';

    $params = [];
    if ($searchTerm !== null) {
        $sql .= ' WHERE k.phrase LIKE ? OR k.website LIKE ?';
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
    }

    $sql .= ' ORDER BY k.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute trend per keyword in the handler (not the view) — AGENTS rule:
    // "Do not put SQL or business logic in views/."
    $keywords = [];
    foreach ($rows as $row) {
        $keywords[] = [
            'id'       => (int) $row['id'],
            'phrase'   => $row['phrase'],
            'website'  => $row['website'],
            'position' => $row['position'] !== null ? (int) $row['position'] : null,
            'trend'    => getKeywordTrend($pdo, (int) $row['id']),
        ];
    }

    renderPage('Keyword List', 'keyword_list.php', [
        'keywords'   => $keywords,
        'searchTerm' => $searchTerm,
    ]);
}

function handleAddForm(): void
{
    renderPage('Add Keyword', 'keyword_form.php', [
        'keywordId'    => null,
        'phrase'       => '',
        'error'        => null,
        'formAction'   => '/add',
        'submitLabel'  => 'Add keyword',
    ]);
}

function handleCreate(): void
{
    $pdo = getPdo();

    $phrase = validateString($_POST['phrase'] ?? null, 200);

    if ($phrase === null) {
        renderPage('Add Keyword', 'keyword_form.php', [
            'keywordId'    => null,
            'phrase'       => is_string($_POST['phrase'] ?? null) ? $_POST['phrase'] : '',
            'error'        => 'Please enter a keyword (1-200 characters).',
            'formAction'   => '/add',
            'submitLabel'  => 'Add keyword',
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO keywords (phrase, website, created_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$phrase, SITE_URL, date('Y-m-d H:i:s')]);

    redirect('/');
}

function handleDetail(int $id): void
{
    // Step 9 (M5): query positions for keyword, render detail page
    echo "Detail for keyword $id (coming in step 9)";
}

function handleEditForm(int $id): void
{
    $pdo = getPdo();

    $stmt = $pdo->prepare('SELECT id, phrase FROM keywords WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        sendNotFound('Keyword not found.');
    }

    renderPage('Edit Keyword', 'keyword_form.php', [
        'keywordId'    => (int) $row['id'],
        'phrase'       => $row['phrase'],
        'error'        => null,
        'formAction'   => '/edit/' . (int) $row['id'],
        'submitLabel'  => 'Update keyword',
    ]);
}

function handleUpdate(int $id): void
{
    $pdo = getPdo();

    // Verify keyword exists (don't silently no-op on stale form POST).
    $stmt = $pdo->prepare('SELECT id FROM keywords WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() === false) {
        sendNotFound('Keyword not found.');
    }

    $phrase = validateString($_POST['phrase'] ?? null, 200);

    if ($phrase === null) {
        renderPage('Edit Keyword', 'keyword_form.php', [
            'keywordId'    => $id,
            'phrase'       => is_string($_POST['phrase'] ?? null) ? $_POST['phrase'] : '',
            'error'        => 'Please enter a keyword (1-200 characters).',
            'formAction'   => '/edit/' . $id,
            'submitLabel'  => 'Update keyword',
        ]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE keywords SET phrase = ? WHERE id = ?');
    $stmt->execute([$phrase, $id]);

    redirect('/');
}

function handleDelete(int $id): void
{
    $pdo = getPdo();

    // Verify keyword exists (avoid silent no-op on stale POST).
    $stmt = $pdo->prepare('SELECT id FROM keywords WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() === false) {
        sendNotFound('Keyword not found.');
    }

    // Positions cascade-delete via ON DELETE CASCADE (seed.php schema).
    $stmt = $pdo->prepare('DELETE FROM keywords WHERE id = ?');
    $stmt->execute([$id]);

    redirect('/');
}

function handleRefresh(): void
{
    $pdo = getPdo();
    $today = date('Y-m-d');

    $pdo->beginTransaction();
    try {
        // Fetch all keywords with their most recent position (correlated subquery,
        // same pattern as handleList()).
        $select = $pdo->prepare(
            'SELECT k.id, k.phrase,
                    (SELECT p.position FROM positions p
                     WHERE p.keyword_id = k.id
                     ORDER BY p.recorded_at DESC, p.id DESC
                     LIMIT 1) AS latest_position
             FROM keywords k
             ORDER BY k.id ASC'
        );
        $select->execute();
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the upsert once; execute per-keyword with different params.
        $upsert = $pdo->prepare(
            'INSERT INTO positions (keyword_id, position, recorded_at)
             VALUES (?, ?, ?)
             ON CONFLICT(keyword_id, recorded_at) DO UPDATE SET position = excluded.position'
        );

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $base = $row['latest_position'] !== null
                ? (int) $row['latest_position']
                : random_int(20, 80);

            // Random walk step: ±3, clamped to 1..100 — same range as seed.php.
            $newPosition = max(1, min(100, $base + random_int(-3, 3)));

            $upsert->execute([$id, $newPosition, $today]);

            $result[] = [
                'id'       => $id,
                'phrase'   => $row['phrase'],
                'position' => $newPosition,
                'trend'    => getKeywordTrend($pdo, $id) ?? 'stable',
            ];
        }

        $pdo->commit();

        sendJson([
            'status'   => 'ok',
            'updated'  => count($result),
            'keywords' => $result,
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        sendJson([
            'status'  => 'error',
            'message' => 'Refresh failed.',
        ]);
    }
}

// --- Request parsing ---

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Split path into segments: "/keyword/12" -> ["keyword", "12"]
$segments = explode('/', trim($path, '/'));
$first = $segments[0] ?? '';
$second = $segments[1] ?? '';

// --- Route dispatch ---

if ($method === 'GET' && $path === '/') {
    handleList();
} elseif ($method === 'GET' && $path === '/add') {
    handleAddForm();
} elseif ($method === 'POST' && $path === '/add') {
    handleCreate();
} elseif ($method === 'GET' && $first === 'keyword' && ($id = validateIntId($second)) !== null) {
    handleDetail($id);
} elseif ($method === 'GET' && $first === 'edit' && ($id = validateIntId($second)) !== null) {
    handleEditForm($id);
} elseif ($method === 'POST' && $first === 'edit' && ($id = validateIntId($second)) !== null) {
    handleUpdate($id);
} elseif ($method === 'POST' && $first === 'delete' && ($id = validateIntId($second)) !== null) {
    handleDelete($id);
} elseif ($method === 'POST' && $path === '/refresh') {
    handleRefresh();
} else {
    sendNotFound();
}
