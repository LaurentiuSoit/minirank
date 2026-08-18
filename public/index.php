<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

// Start the session before any output. Cookie params harden it: HttpOnly
// blocks JS access to the cookie, SameSite=Lax blocks cross-site POSTs from
// sending it. `secure` is false because dev runs over plain HTTP.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => false,
]);
session_start();

// --- Response helpers ---

function sendJson(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function renderPage(string $title, string $viewTemplate, array $data = []): void
{
    // Auto-inject the user's projects for the project switcher in layout.php (S2).
    // Done in the controller layer so views stay free of SQL (AGENTS: no SQL in views/).
    if (isLoggedIn()) {
        $userId = currentUserId();
        if ($userId !== null) {
            $data['projects'] = getUserProjects(getPdo(), $userId);
        }
    }

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

function handleList(int $projectId, array $project): void
{
    $pdo = getPdo();

    $searchTerm = validateString($_GET['q'] ?? null, 100);

    // S4: position-range filter — validated integers 1–100, null = unbounded.
    $minPos = null;
    $val = filter_var($_GET['position_min'] ?? null, FILTER_VALIDATE_INT);
    if ($val !== false && $val >= 1 && $val <= 100) {
        $minPos = (int) $val;
    }

    $maxPos = null;
    $val = filter_var($_GET['position_max'] ?? null, FILTER_VALIDATE_INT);
    if ($val !== false && $val >= 1 && $val <= 100) {
        $maxPos = (int) $val;
    }

    // S4: movement filter — whitelist only, never placed in SQL.
    $movement = null;
    if (isset($_GET['movement'])) {
        $whitelist = ['improved', 'declined', 'stable'];
        if (in_array($_GET['movement'], $whitelist, true)) {
            $movement = $_GET['movement'];
        }
    }

    $sql = 'SELECT k.id, k.phrase, p.position
            FROM keywords k
            LEFT JOIN positions p
              ON p.id = (SELECT id FROM positions
                         WHERE keyword_id = k.id
                         ORDER BY recorded_at DESC, id DESC
                         LIMIT 1)
            WHERE k.project_id = ?';

    $params = [$projectId];
    if ($searchTerm !== null) {
        $sql .= ' AND k.phrase LIKE ?';
        $params[] = '%' . $searchTerm . '%';
    }

    // S4: position range narrows on the latest-position JOIN. Keywords with no
    // position (p.position IS NULL) are excluded when a range filter is active,
    // because NULL >= ? evaluates to NULL (false) — which is correct: a keyword
    // with no position can't match a position-range filter.
    if ($minPos !== null) {
        $sql .= ' AND p.position >= ?';
        $params[] = $minPos;
    }
    if ($maxPos !== null) {
        $sql .= ' AND p.position <= ?';
        $params[] = $maxPos;
    }

    $sql .= ' ORDER BY k.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // S4: batch-fetch all 7-day trends in one query (de-N+1) instead of calling
    // getKeywordTrend() per row.
    $keywordIds = [];
    foreach ($rows as $row) {
        $keywordIds[] = (int) $row['id'];
    }
    $trends = getKeywordTrends($pdo, $keywordIds);

    // Compute trend per keyword in the handler (not the view) — AGENTS rule:
    // "Do not put SQL or business logic in views/."
    $keywords = [];
    foreach ($rows as $row) {
        $kwId = (int) $row['id'];
        $trend = $trends[$kwId] ?? null;

        // Movement filter applied in PHP after trend computation.
        if ($movement !== null && $trend !== $movement) {
            continue;
        }

        $keywords[] = [
            'id'       => $kwId,
            'phrase'   => $row['phrase'],
            'position' => $row['position'] !== null ? (int) $row['position'] : null,
            'trend'    => $trend,
        ];
    }

    renderPage('Keyword List', 'keyword_list.php', [
        'keywords'    => $keywords,
        'searchTerm'  => $searchTerm,
        'positionMin' => $minPos,
        'positionMax' => $maxPos,
        'movement'    => $movement,
        'projectId'   => $projectId,
        'project'     => $project,
    ]);
}

function handleAddForm(int $projectId, array $project): void
{
    renderPage('Add Keyword', 'keyword_form.php', [
        'keywordId'    => null,
        'phrase'       => '',
        'error'        => null,
        'formAction'   => '/project/' . $projectId . '/add',
        'submitLabel'  => 'Add keyword',
        'projectId'    => $projectId,
    ]);
}

function handleCreate(int $projectId, array $project): void
{
    $pdo = getPdo();

    $phrase = validateString($_POST['phrase'] ?? null, 200);

    if ($phrase === null) {
        renderPage('Add Keyword', 'keyword_form.php', [
            'keywordId'    => null,
            'phrase'       => is_string($_POST['phrase'] ?? null) ? $_POST['phrase'] : '',
            'error'        => 'Please enter a keyword (1-200 characters).',
            'formAction'   => '/project/' . $projectId . '/add',
            'submitLabel'  => 'Add keyword',
            'projectId'    => $projectId,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO keywords (project_id, phrase, created_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$projectId, $phrase, date('Y-m-d H:i:s')]);

    redirect('/project/' . $projectId);
}

function handleDetail(int $projectId, array $project, int $keywordId): void
{
    $pdo = getPdo();

    $stmt = $pdo->prepare(
        'SELECT id, phrase, created_at FROM keywords WHERE id = ? AND project_id = ?'
    );
    $stmt->execute([$keywordId, $projectId]);
    $keyword = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($keyword === false) {
        sendNotFound('Keyword not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT position, recorded_at
         FROM positions
         WHERE keyword_id = ?
         ORDER BY recorded_at DESC, id DESC'
    );
    $stmt->execute([$keywordId]);
    $positionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    for ($i = 0; $i < count($positionRows); $i++) {
        $currentPosition = (int) $positionRows[$i]['position'];
        $previousPosition = isset($positionRows[$i + 1])
            ? (int) $positionRows[$i + 1]['position']
            : null;

        $history[] = [
            'date'     => date('M j, Y', strtotime($positionRows[$i]['recorded_at'])),
            'position' => $currentPosition,
            'trend'    => $previousPosition !== null
                ? calculateTrend($currentPosition, $previousPosition)
                : null,
            'hasTrend' => $previousPosition !== null,
        ];
    }

    // Compact [date, position] pairs for the line chart, in chronological
    // order (oldest first). Raw Y-m-d dates and integer positions — no user
    // input, position enforced 1-100 by the DB CHECK constraint.
    $positions = [];
    foreach (array_reverse($positionRows) as $row) {
        $positions[] = [
            $row['recorded_at'],
            (int) $row['position'],
        ];
    }

    $currentTrend = getKeywordTrend($pdo, (int) $keyword['id']) ?? 'stable';

    renderPage('Keyword Detail', 'keyword_detail.php', [
        'keyword'         => $keyword,
        'history'         => $history,
        'positions'       => $positions,
        'currentPosition' => count($history) > 0 ? $history[0]['position'] : null,
        'currentTrend'    => $currentTrend,
        'projectId'       => $projectId,
        'project'         => $project,
    ]);
}

function handleExport(int $projectId, array $project, int $keywordId): void
{
    $pdo = getPdo();

    $stmt = $pdo->prepare(
        'SELECT id, phrase FROM keywords WHERE id = ? AND project_id = ?'
    );
    $stmt->execute([$keywordId, $projectId]);
    $keyword = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($keyword === false) {
        sendNotFound('Keyword not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT recorded_at, position
         FROM positions
         WHERE keyword_id = ?
         ORDER BY recorded_at DESC'
    );
    $stmt->execute([$keywordId]);
    $positionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = sanitizeFilename($keyword['phrase'], $keywordId);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Position', 'Trend'], ',', '"', '');

    for ($i = 0; $i < count($positionRows); $i++) {
        $position = (int) $positionRows[$i]['position'];
        $date = date('M j, Y', strtotime($positionRows[$i]['recorded_at']));
        $previousPosition = isset($positionRows[$i + 1])
            ? (int) $positionRows[$i + 1]['position']
            : null;
        $trend = $previousPosition !== null
            ? calculateTrend($position, $previousPosition)
            : '';
        fputcsv($output, [$date, $position, $trend], ',', '"', '');
    }

    fclose($output);
    exit;
}

function handleEditForm(int $projectId, array $project, int $keywordId): void
{
    $pdo = getPdo();

    $stmt = $pdo->prepare('SELECT id, phrase FROM keywords WHERE id = ? AND project_id = ?');
    $stmt->execute([$keywordId, $projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        sendNotFound('Keyword not found.');
    }

    renderPage('Edit Keyword', 'keyword_form.php', [
        'keywordId'    => (int) $row['id'],
        'phrase'       => $row['phrase'],
        'error'        => null,
        'formAction'   => '/project/' . $projectId . '/keyword/' . $keywordId . '/edit',
        'submitLabel'  => 'Update keyword',
        'projectId'    => $projectId,
    ]);
}

function handleUpdate(int $projectId, array $project, int $keywordId): void
{
    $pdo = getPdo();

    // Verify keyword exists and belongs to this project (don't silently no-op on stale form POST).
    $stmt = $pdo->prepare('SELECT id FROM keywords WHERE id = ? AND project_id = ?');
    $stmt->execute([$keywordId, $projectId]);
    if ($stmt->fetchColumn() === false) {
        sendNotFound('Keyword not found.');
    }

    $phrase = validateString($_POST['phrase'] ?? null, 200);

    if ($phrase === null) {
        renderPage('Edit Keyword', 'keyword_form.php', [
            'keywordId'    => $keywordId,
            'phrase'       => is_string($_POST['phrase'] ?? null) ? $_POST['phrase'] : '',
            'error'        => 'Please enter a keyword (1-200 characters).',
            'formAction'   => '/project/' . $projectId . '/keyword/' . $keywordId . '/edit',
            'submitLabel'  => 'Update keyword',
            'projectId'    => $projectId,
        ]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE keywords SET phrase = ? WHERE id = ?');
    $stmt->execute([$phrase, $keywordId]);

    redirect('/project/' . $projectId);
}

function handleDelete(int $projectId, array $project, int $keywordId): void
{
    $pdo = getPdo();

    // Verify keyword exists and belongs to this project (avoid silent no-op on stale POST).
    $stmt = $pdo->prepare('SELECT id FROM keywords WHERE id = ? AND project_id = ?');
    $stmt->execute([$keywordId, $projectId]);
    if ($stmt->fetchColumn() === false) {
        sendNotFound('Keyword not found.');
    }

    // Positions cascade-delete via ON DELETE CASCADE (seed.php schema).
    $stmt = $pdo->prepare('DELETE FROM keywords WHERE id = ?');
    $stmt->execute([$keywordId]);

    redirect('/project/' . $projectId);
}

function handleRefresh(int $projectId): void
{
    $pdo = getPdo();
    $today = date('Y-m-d');

    $pdo->beginTransaction();
    try {
        // Fetch all keywords in this project with their most recent position.
        $select = $pdo->prepare(
            'SELECT k.id, k.phrase,
                    (SELECT p.position FROM positions p
                     WHERE p.keyword_id = k.id
                     ORDER BY p.recorded_at DESC, p.id DESC
                     LIMIT 1) AS latest_position
             FROM keywords k
             WHERE k.project_id = ?
             ORDER BY k.id ASC'
        );
        $select->execute([$projectId]);
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

// --- Auth handlers (S3) ---

function handleRegisterForm(): void
{
    renderPage('Register', 'auth_form.php', [
        'mode'  => 'register',
        'error' => null,
        'action' => '/register',
    ]);
}

function handleRegister(): void
{
    $pdo = getPdo();

    // Email: length-capped string, then format-validated.
    $email = validateString($_POST['email'] ?? null, 254);
    $password = $_POST['password'] ?? '';

    $emailValid = $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    $passwordValid = is_string($password) && strlen($password) >= 8 && strlen($password) <= 200;

    if (!$emailValid || !$passwordValid) {
        renderPage('Register', 'auth_form.php', [
            'mode'  => 'register',
            'error' => 'Please enter a valid email and a password of at least 8 characters.',
            'action' => '/register',
        ]);
        return;
    }

    // Prevent duplicate emails.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() !== false) {
        renderPage('Register', 'auth_form.php', [
            'mode'  => 'register',
            'error' => 'An account with this email already exists.',
            'action' => '/register',
        ]);
        return;
    }

    // Insert the new user with a hashed password (PASSWORD_DEFAULT = bcrypt).
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
    $stmt->execute([$email, $hash]);

    // Auto-login after registration (Q2 default).
    $_SESSION['user_id'] = (int) $pdo->lastInsertId();
    redirect('/');
}

function handleLoginForm(): void
{
    renderPage('Login', 'auth_form.php', [
        'mode'  => 'login',
        'error' => null,
        'action' => '/login',
    ]);
}

function handleLogin(): void
{
    $pdo = getPdo();

    $email = validateString($_POST['email'] ?? null, 254);
    $password = $_POST['password'] ?? '';

    $emailValid = $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    $passwordValid = is_string($password) && strlen($password) > 0;

    if (!$emailValid || !$passwordValid) {
        renderPage('Login', 'auth_form.php', [
            'mode'  => 'login',
            'error' => 'Invalid email or password.',
            'action' => '/login',
        ]);
        return;
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generic error on any failure: no user-enumeration (wrong email or wrong password).
    if ($user === false || !password_verify($password, $user['password_hash'])) {
        renderPage('Login', 'auth_form.php', [
            'mode'  => 'login',
            'error' => 'Invalid email or password.',
            'action' => '/login',
        ]);
        return;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    redirect('/');
}

function handleLogout(): void
{
    $_SESSION = [];
    session_destroy();
    redirect('/login');
}

// --- Project handlers (S2) ---
// Projects group keywords under a single tracked website. All project
// mutations (create/edit/delete) are POST + CSRF + auth. Project CRUD is
// separate from keyword CRUD but lives in the same dispatch file.

function handleProjectForm(): void
{
    renderPage('Add Project', 'project_form.php', [
        'projectId'      => null,
        'projectName'    => '',
        'projectWebsite' => '',
        'error'          => null,
        'formAction'     => '/project/add',
        'submitLabel'    => 'Add project',
    ]);
}

function handleProjectCreate(): void
{
    $pdo = getPdo();
    $userId = currentUserId();

    $name = validateString($_POST['name'] ?? null, 100);
    $website = validateString($_POST['website'] ?? null, 2048);

    if ($name === null || $website === null) {
        renderPage('Add Project', 'project_form.php', [
            'projectId'      => null,
            'projectName'    => is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
            'projectWebsite' => is_string($_POST['website'] ?? null) ? $_POST['website'] : '',
            'error'          => 'Please enter a name (1-100 characters) and a website URL (1-2048 characters).',
            'formAction'     => '/project/add',
            'submitLabel'    => 'Add project',
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO projects (user_id, name, website, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $name, $website, date('Y-m-d H:i:s')]);

    redirect('/project/' . (int) $pdo->lastInsertId());
}

function handleProjectEditForm(array $project): void
{
    renderPage('Edit Project', 'project_form.php', [
        'projectId'      => (int) $project['id'],
        'projectName'    => $project['name'],
        'projectWebsite' => $project['website'],
        'error'          => null,
        'formAction'     => '/project/' . (int) $project['id'] . '/edit',
        'submitLabel'    => 'Update project',
    ]);
}

function handleProjectUpdate(array $project): void
{
    $pdo = getPdo();
    $projectId = (int) $project['id'];

    $name = validateString($_POST['name'] ?? null, 100);
    $website = validateString($_POST['website'] ?? null, 2048);

    if ($name === null || $website === null) {
        renderPage('Edit Project', 'project_form.php', [
            'projectId'      => $projectId,
            'projectName'    => is_string($_POST['name'] ?? null) ? $_POST['name'] : '',
            'projectWebsite' => is_string($_POST['website'] ?? null) ? $_POST['website'] : '',
            'error'          => 'Please enter a name (1-100 characters) and a website URL (1-2048 characters).',
            'formAction'     => '/project/' . $projectId . '/edit',
            'submitLabel'    => 'Update project',
        ]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE projects SET name = ?, website = ? WHERE id = ?');
    $stmt->execute([$name, $website, $projectId]);

    redirect('/project/' . $projectId);
}

function handleProjectDelete(array $project): void
{
    $pdo = getPdo();

    // Cascade deletes keywords + positions via ON DELETE CASCADE (seed.php schema).
    $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ?');
    $stmt->execute([(int) $project['id']]);

    redirect('/');
}

// --- Request parsing ---

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Split path into segments: "/keyword/12" -> ["keyword", "12"]
$segments = explode('/', trim($path, '/'));
$first = $segments[0] ?? '';
$second = $segments[1] ?? '';

// Auth gate: routes outside auth-exempt set require a logged-in session.
// Auth routes (register/login/logout) remain accessible to anonymous users.
$authExempt = ['register', 'login', 'logout'];
if (!in_array($first, $authExempt, true)) {
    requireAuth();
}

// CSRF: every POST must carry a valid session-bound token (including
// register/login/logout — this blocks login-CSRF).
if ($method === 'POST') {
    verifyCsrf();
}

// --- Route dispatch ---

$pdo = getPdo();

// Auth routes (accessible to anonymous users).
if ($method === 'GET' && $path === '/register') {
    handleRegisterForm();
} elseif ($method === 'POST' && $path === '/register') {
    handleRegister();
} elseif ($method === 'GET' && $path === '/login') {
    handleLoginForm();
} elseif ($method === 'POST' && $path === '/login') {
    handleLogin();
} elseif ($method === 'POST' && $path === '/logout') {
    handleLogout();

// Root: redirect to the user's most-recent project, or to the add-project page
// if they have none.
} elseif ($method === 'GET' && $path === '/') {
    $userId = currentUserId();
    $project = getLatestProjectForUser($pdo, (int) $userId);
    if ($project !== null) {
        redirect('/project/' . (int) $project['id']);
    }
    redirect('/project/add');

// Project CRUD (not scoped to a specific project id).
} elseif ($method === 'GET' && $path === '/project/add') {
    handleProjectForm();
} elseif ($method === 'POST' && $path === '/project/add') {
    handleProjectCreate();

// Project-scoped routes: /project/{pid}/...
} elseif ($first === 'project' && ($projectId = validateIntId($segments[1] ?? null)) !== null) {
    // Ownership check: 404 if the project doesn't exist or belongs to another user.
    $project = getProjectForUser($pdo, $projectId, (int) currentUserId());
    if ($project === null) {
        sendNotFound('Project not found.');
    }
    $projectId = (int) $project['id'];
    $sub1 = $segments[2] ?? '';

    if ($method === 'GET' && $sub1 === '') {
        handleList($projectId, $project);
    } elseif ($method === 'GET' && $sub1 === 'add') {
        handleAddForm($projectId, $project);
    } elseif ($method === 'POST' && $sub1 === 'add') {
        handleCreate($projectId, $project);
    } elseif ($method === 'GET' && $sub1 === 'edit') {
        handleProjectEditForm($project);
    } elseif ($method === 'POST' && $sub1 === 'edit') {
        handleProjectUpdate($project);
    } elseif ($method === 'POST' && $sub1 === 'delete') {
        handleProjectDelete($project);
    } elseif ($method === 'POST' && $sub1 === 'refresh') {
        handleRefresh($projectId);
    } elseif ($sub1 === 'keyword' && ($keywordId = validateIntId($segments[3] ?? null)) !== null) {
        $sub2 = $segments[4] ?? '';

        if ($method === 'GET' && $sub2 === '') {
            handleDetail($projectId, $project, $keywordId);
        } elseif ($method === 'GET' && $sub2 === 'export') {
            handleExport($projectId, $project, $keywordId);
        } elseif ($method === 'GET' && $sub2 === 'edit') {
            handleEditForm($projectId, $project, $keywordId);
        } elseif ($method === 'POST' && $sub2 === 'edit') {
            handleUpdate($projectId, $project, $keywordId);
        } elseif ($method === 'POST' && $sub2 === 'delete') {
            handleDelete($projectId, $project, $keywordId);
        } else {
            sendNotFound();
        }
    } else {
        sendNotFound();
    }
} else {
    sendNotFound();
}
