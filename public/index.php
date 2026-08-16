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
    // Step 6 (M4): query keywords, render views/keyword_list.php
    echo 'Keyword list (coming in step 6)';
}

function handleAddForm(): void
{
    // Step 7 (M1): render add form
    echo 'Add form (coming in step 7)';
}

function handleCreate(): void
{
    // Step 7 (M1): validate POST, INSERT keyword, redirect to /
    echo 'Create (coming in step 7)';
}

function handleDetail(int $id): void
{
    // Step 9 (M5): query positions for keyword, render detail page
    echo "Detail for keyword $id (coming in step 9)";
}

function handleEditForm(int $id): void
{
    // Step 7 (M1): query keyword, render edit form
    echo "Edit form for keyword $id (coming in step 7)";
}

function handleUpdate(int $id): void
{
    // Step 7 (M1): validate POST, UPDATE keyword, redirect
    echo "Update keyword $id (coming in step 7)";
}

function handleDelete(int $id): void
{
    // Step 7 (M1): DELETE keyword, redirect
    echo "Delete keyword $id (coming in step 7)";
}

function handleRefresh(): void
{
    // Step 8 (M3): generate today's positions, return JSON
    sendJson(['status' => 'not implemented']);
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
