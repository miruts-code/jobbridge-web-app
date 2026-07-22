<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function configure_local_mail_transport(): void
{
    if (APP_ENV !== 'local') {
        return;
    }

    // This project is intentionally wired for local WAMP testing only.
    // PHP mail() will hand the message to the local SMTP catcher instead of a real provider.
    @ini_set('SMTP', MAIL_HOST);
    @ini_set('smtp_port', (string) MAIL_PORT);
    @ini_set('sendmail_from', MAIL_FROM);
}

configure_local_mail_transport();

function app_url(string $page = 'home', array $params = []): string
{
    $query = array_merge(['page' => $page], $params);
    return APP_BASE_PATH . '/index.php?' . http_build_query($query);
}

function esc(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect_to(string $page, array $params = []): never
{
    header('Location: ' . app_url($page, $params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash_messages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $token)) {
        flash('error', 'Invalid security token. Please try again.');
        redirect_to('home');
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null) {
        return $user;
    }

    $statement = run_query('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $_SESSION['user_id']]);
    $user = $statement->fetch() ?: null;

    return $user;
}

function login_user(array $user): void
{
    $_SESSION['user_id'] = $user['id'];
}

function logout_user(): void
{
    unset($_SESSION['user_id']);
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please login to continue.');
        redirect_to('login');
    }

    return $user;
}

function require_role(string $role): array
{
    $user = require_login();
    if (($user['role'] ?? '') !== $role) {
        flash('error', 'You are not allowed to access that page.');
        redirect_to('home');
    }

    return $user;
}

function format_date(?string $value): string
{
    if (!$value) {
        return '-';
    }

    return date('M j, Y', strtotime($value));
}

function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
 
function send_email(string $to, string $subject, string $html): bool
{
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: JobBridge <' . MAIL_FROM . '>',
    ]);

    return mail($to, $subject, $html, $headers);
}

function store_uploaded_cv_file(array $file): ?string
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $uploadDir = __DIR__ . '/../media/cvs';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $originalName = basename((string) ($file['name'] ?? 'cv'));
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $fileName = uuid() . ($extension ? '.' . $extension : '');
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return null;
    }

    return 'media/cvs/' . $fileName;
}