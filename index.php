<?php
declare(strict_types=1);

/**
 * To-do List (COMP016 Lab Activity 5)
 *
 * Refactor of Lab 4: tasks now live in a MySQL table (task_db.tasks) and are
 * reached through PDO prepared statements. The PHP session is kept only for
 * the CSRF token and one-time flash messages, never for task data.
 *
 * File layout
 *   1. Configuration and bootstrap
 *   2. Helpers
 *   3. Actions (CRUD against MySQL)
 *   4. Request handling (POST -> action -> redirect -> GET)
 *   5. View data
 *   6. Template
 */

/* ---------------------------------------------------------------------------
 * 1. Configuration and bootstrap
 * ------------------------------------------------------------------------ */

require_once 'db.php'; // provides $pdo and sets the timezone

const MAX_NAME_LENGTH        = 100;
const MAX_DESCRIPTION_LENGTH = 1000;
const MAX_TASKS              = 100;
const PRIORITIES             = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

/* ---------------------------------------------------------------------------
 * 2. Helpers
 * ------------------------------------------------------------------------ */

/** Escape a value for safe output inside HTML (prevents XSS). */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Read a string from $_POST, ignoring tampered values such as arrays. */
function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

/** Trim the value and collapse repeated whitespace into single spaces. */
function normalize(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

/** Trim the value but keep line breaks, so descriptions stay readable. */
function normalize_multiline(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace("/[ \t]+/u", ' ', $value) ?? '';
    $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

    return trim($value);
}

/** Store a one-time message that is shown after the redirect. */
function flash(string $type, string $message, ?int $taskId = null): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'task_id' => $taskId];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

/**
 * Read and validate the fields submitted by the add and edit forms.
 *
 * @return array{0: string, 1: string, 2: string, 3: ?string} [name, description, priority, error]
 */
function read_task_input(): array
{
    $name        = normalize(post_string('task_name'));
    $description = normalize_multiline(post_string('task_description'));
    $priority    = post_string('priority', 'medium');
    $error       = null;

    if (!array_key_exists($priority, PRIORITIES)) {
        $priority = 'medium';
    }

    if ($name === '') {
        $error = 'Enter a task name.';
    } elseif (mb_strlen($name) > MAX_NAME_LENGTH) {
        $error = 'Keep the task name to ' . MAX_NAME_LENGTH . ' characters or fewer.';
    } elseif ($description === '') {
        $error = 'Enter a task description.';
    } elseif (mb_strlen($description) > MAX_DESCRIPTION_LENGTH) {
        $error = 'Keep the description to ' . MAX_DESCRIPTION_LENGTH . ' characters or fewer.';
    }

    return [$name, $description, $priority, $error];
}

/* ---------------------------------------------------------------------------
 * 3. Actions
 *
 * Every query uses prepare() and execute(), so user input is sent to MySQL
 * as data and can never change the SQL statement (no SQL injection).
 * ------------------------------------------------------------------------ */

/** CREATE: insert a new task. */
function add_task(PDO $pdo): void
{
    [$name, $description, $priority, $error] = read_task_input();

    if ($error === null) {
        $count = $pdo->prepare('SELECT COUNT(*) FROM tasks');
        $count->execute();
        if ((int) $count->fetchColumn() >= MAX_TASKS) {
            $error = 'You can keep up to ' . MAX_TASKS . ' tasks. Delete one to add another.';
        }
    }

    if ($error !== null) {
        $_SESSION['old'] = [
            'task_name'        => post_string('task_name'),
            'task_description' => post_string('task_description'),
            'priority'         => $priority,
        ];
        flash('error', $error);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tasks (task_name, task_description, priority) VALUES (?, ?, ?)'
    );
    $stmt->execute([$name, $description, $priority]);

    flash('success', 'Task added.', (int) $pdo->lastInsertId());
}

/** UPDATE: change the name, description and priority of a task. */
function update_task(PDO $pdo, int $id): void
{
    [$name, $description, $priority, $error] = read_task_input();
    if ($error !== null) {
        flash('error', $error);
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE tasks SET task_name = ?, task_description = ?, priority = ? WHERE id = ?'
    );
    $stmt->execute([$name, $description, $priority, $id]);

    if ($stmt->rowCount() === 0) {
        flash('error', 'That task no longer exists.');
        return;
    }
    flash('success', 'Task updated.', $id);
}

/** UPDATE: flip a task between pending and completed. */
function toggle_task(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare(
        "UPDATE tasks SET status = IF(status = 'pending', 'completed', 'pending') WHERE id = ?"
    );
    $stmt->execute([$id]);
}

/** DELETE: remove one task. */
function delete_task(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        flash('error', 'That task no longer exists.');
        return;
    }
    flash('success', 'Task deleted.');
}

/** DELETE: remove every completed task. */
function clear_done(PDO $pdo): void
{
    $stmt = $pdo->prepare("DELETE FROM tasks WHERE status = 'completed'");
    $stmt->execute();
    $removed = $stmt->rowCount();

    flash('success', $removed === 1 ? 'Cleared 1 completed task.' : "Cleared {$removed} completed tasks.");
}

/* ---------------------------------------------------------------------------
 * 4. Request handling
 *
 * Every state change arrives as a POST. The script runs the action, then
 * redirects (Post/Redirect/Get) so a page refresh never re-submits the form.
 * ------------------------------------------------------------------------ */

function handle_post(PDO $pdo): void
{
    if (!hash_equals($_SESSION['csrf'], post_string('csrf'))) {
        flash('error', 'Your session expired. Please try again.');
        return;
    }

    $id = (int) post_string('id', '0');

    try {
        match (post_string('action')) {
            'add'        => add_task($pdo),
            'update'     => update_task($pdo, $id),
            'toggle'     => toggle_task($pdo, $id),
            'delete'     => delete_task($pdo, $id),
            'clear_done' => clear_done($pdo),
            default      => flash('error', 'Unknown action.'),
        };
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        flash('error', 'Something went wrong with the database. Please try again.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post($pdo);
    header('Location: ' . (strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/'), true, 303);
    exit;
}

/* ---------------------------------------------------------------------------
 * 5. View data
 * ------------------------------------------------------------------------ */

$flash = $_SESSION['flash'] ?? null;
$old   = $_SESSION['old'] ?? ['task_name' => '', 'task_description' => '', 'priority' => 'medium'];
unset($_SESSION['flash'], $_SESSION['old']);

$dbError = null;
$tasks   = [];

// READ: fetch all tasks, newest first.
try {
    $stmt = $pdo->prepare('SELECT * FROM tasks ORDER BY created_at DESC, id DESC');
    $stmt->execute();
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    $dbError = 'Could not load tasks. Did you import task_db.sql?';
}

$total  = count($tasks);
$done   = count(array_filter($tasks, fn (array $task): bool => $task['status'] === 'completed'));
$active = $total - $done;
$theme  = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : '';

?>
<!DOCTYPE html>
<html lang="en"<?= $theme !== '' ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>To-do list</title>
    <link rel="stylesheet" href="style.css">
    <script src="script.js" defer></script>
</head>
<body>
<a class="skip-link" href="#taskList">Skip to tasks</a>

<div class="app">
    <header class="app-header">
        <div>
            <h1 class="app-title">To-do list</h1>
            <p class="app-subtitle">Stay on top of what needs to get done.</p>
        </div>
        <button type="button" class="btn" id="themeToggle" hidden>Dark mode</button>
    </header>

    <main>
        <section class="card" aria-labelledby="progressTitle">
            <div class="progress-head">
                <h2 class="card-title" id="progressTitle">Progress</h2>
                <p class="progress-summary"><?= $done ?> of <?= $total ?> done</p>
            </div>
            <progress class="progress" max="<?= max($total, 1) ?>" value="<?= $done ?>" aria-labelledby="progressTitle"></progress>
        </section>

        <section class="card" aria-labelledby="addTitle">
            <h2 class="card-title" id="addTitle">New task</h2>
            <form method="post" action="" class="add-form" id="addForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">

                <div class="add-form-title">
                    <label class="visually-hidden" for="nameInput">Task name</label>
                    <input class="input" type="text" name="task_name" id="nameInput"
                           placeholder="What needs to get done?" maxlength="<?= MAX_NAME_LENGTH ?>"
                           autocomplete="off" value="<?= e($old['task_name']) ?>"<?= $flash && $flash['type'] === 'error' ? ' autofocus' : '' ?>>
                    <span class="counter" id="counter" aria-hidden="true">0/<?= MAX_NAME_LENGTH ?></span>
                </div>

                <label class="visually-hidden" for="prioritySelect">Priority</label>
                <select class="input" name="priority" id="prioritySelect">
                    <?php foreach (PRIORITIES as $value => $label): ?>
                        <option value="<?= $value ?>"<?= $value === $old['priority'] ? ' selected' : '' ?>><?= $label ?> priority</option>
                    <?php endforeach; ?>
                </select>

                <div class="add-form-description">
                    <label class="visually-hidden" for="descriptionInput">Task description</label>
                    <textarea class="input" name="task_description" id="descriptionInput" rows="3"
                              placeholder="Add a few details" maxlength="<?= MAX_DESCRIPTION_LENGTH ?>"><?= e($old['task_description']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary add-form-submit">Add task</button>
            </form>
        </section>

        <section class="card" aria-labelledby="tasksTitle">
            <div class="list-head">
                <h2 class="card-title" id="tasksTitle">Tasks</h2>
                <?php if ($done > 0): ?>
                    <form method="post" action="" data-confirm="Clear <?= $done ?> completed <?= $done === 1 ? 'task' : 'tasks' ?>? This can't be undone." data-confirm-label="Clear completed">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_done">
                        <button type="submit" class="btn btn-small">Clear completed</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($dbError !== null): ?>
                <p class="empty" role="alert"><?= e($dbError) ?></p>
            <?php endif; ?>

            <div class="toolbar">
                <div class="filters" role="group" aria-label="Filter tasks">
                    <button type="button" class="filter is-active" data-filter="all" aria-pressed="true">All <span class="filter-count"><?= $total ?></span></button>
                    <button type="button" class="filter" data-filter="active" aria-pressed="false">Active <span class="filter-count"><?= $active ?></span></button>
                    <button type="button" class="filter" data-filter="done" aria-pressed="false">Done <span class="filter-count"><?= $done ?></span></button>
                </div>
                <div class="search">
                    <label class="visually-hidden" for="searchInput">Search tasks</label>
                    <input class="input" type="search" id="searchInput" placeholder="Search tasks" autocomplete="off">
                </div>
            </div>

            <ul class="task-list" id="taskList">
                <?php foreach ($tasks as $task): ?>
                    <?php
                    $isDone  = $task['status'] === 'completed';
                    $created = strtotime($task['created_at']) ?: time();
                    $isNew   = $flash && $flash['type'] === 'success' && $flash['task_id'] === $task['id'];
                    $classes = 'task task-' . $task['priority']
                        . ($isDone ? ' is-done' : '')
                        . ($isNew ? ' is-new' : '');
                    ?>
                    <li class="<?= $classes ?>" data-done="<?= $isDone ? 'true' : 'false' ?>" data-title="<?= e(mb_strtolower($task['task_name'] . ' ' . $task['task_description'])) ?>">
                        <form method="post" action="" class="task-toggle">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $task['id'] ?>">
                            <button type="submit" class="check"
                                    aria-label="<?= $isDone ? 'Mark as pending: ' : 'Mark as completed: ' ?><?= e($task['task_name']) ?>"></button>
                        </form>

                        <div class="task-body">
                            <div class="task-view">
                                <p class="task-title"><?= e($task['task_name']) ?></p>
                                <p class="task-description"><?= e($task['task_description']) ?></p>
                                <p class="task-meta">
                                    <span class="chip"><?= e(PRIORITIES[$task['priority']]) ?> priority</span>
                                    <time datetime="<?= date('c', $created) ?>">Added <?= date('M j, g:i A', $created) ?></time>
                                </p>
                            </div>

                            <form method="post" action="" class="task-edit" hidden>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= $task['id'] ?>">
                                <input class="input" type="text" name="task_name" value="<?= e($task['task_name']) ?>"
                                       maxlength="<?= MAX_NAME_LENGTH ?>" autocomplete="off" aria-label="Task name">
                                <select class="input" name="priority" aria-label="Priority">
                                    <?php foreach (PRIORITIES as $value => $label): ?>
                                        <option value="<?= $value ?>"<?= $value === $task['priority'] ? ' selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <textarea class="input" name="task_description" rows="3"
                                          maxlength="<?= MAX_DESCRIPTION_LENGTH ?>" aria-label="Task description"><?= e($task['task_description']) ?></textarea>
                                <button type="submit" class="btn btn-primary btn-small">Save</button>
                                <button type="button" class="btn btn-small" data-action="cancel-edit">Cancel</button>
                            </form>
                        </div>

                        <div class="task-actions">
                            <button type="button" class="btn btn-small" data-action="edit">Edit</button>
                            <form method="post" action="" data-confirm="Delete &ldquo;<?= e($task['task_name']) ?>&rdquo;? This can't be undone." data-confirm-label="Delete">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $task['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Delete</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="empty" id="emptyState"<?= $total > 0 || $dbError !== null ? ' hidden' : '' ?>>No tasks yet. Add your first one above.</p>
            <p class="empty" id="noMatch" hidden>No tasks match this view.</p>
        </section>
    </main>

    <footer class="app-footer">
        <p>COMP016 Lab Activity 5. Tasks are stored in a MySQL database.</p>
        <p>Shortcuts: <kbd>N</kbd> new task, <kbd>/</kbd> search, <kbd>Esc</kbd> cancel editing.</p>
    </footer>
</div>

<div class="toast<?= $flash ? ' is-visible' : '' ?>" id="toast"
     data-type="<?= e($flash['type'] ?? 'success') ?>"
     role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>">
    <span id="toastMessage"><?= e($flash['message'] ?? '') ?></span>
    <button type="button" class="toast-close" id="toastClose" aria-label="Dismiss message" hidden>&times;</button>
</div>

<dialog class="dialog" id="confirmDialog" aria-labelledby="confirmTitle">
    <form method="dialog">
        <h2 class="dialog-title" id="confirmTitle">Are you sure?</h2>
        <p class="dialog-text" id="confirmText"></p>
        <div class="dialog-actions">
            <button type="submit" class="btn" value="cancel">Cancel</button>
            <button type="submit" class="btn btn-danger-solid" value="confirm" id="confirmOk">Confirm</button>
        </div>
    </form>
</dialog>
</body>
</html>
