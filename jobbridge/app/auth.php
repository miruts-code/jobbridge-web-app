<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function find_user_by_email(string $email): ?array
{
    $statement = run_query('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => normalize_email($email)]);
    return $statement->fetch() ?: null;
}

function authenticate_user(string $email, string $password): ?array
{
    $user = find_user_by_email($email);
    if (!$user || !password_verify($password, $user['password'])) {
        return null;
    }

    return $user;
}

function create_user(string $email, string $password, string $role): array
{
    $id = uuid();
    $now = date('Y-m-d H:i:s');
    run_query(
        'INSERT INTO users (id, email, password, role, created_at, updated_at) VALUES (:id, :email, :password, :role, :created_at, :updated_at)',
        [
            'id' => $id,
            'email' => normalize_email($email),
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    return find_user_by_email($email);
}

function create_pending_user(string $email, string $password, string $role, string $verificationCode): void
{
    $id = uuid();
    $now = date('Y-m-d H:i:s');
    run_query(
        'INSERT INTO pending_users (id, email, password, role, verification_code, created_at)
         VALUES (:id, :email, :password, :role, :verification_code, :created_at)
         ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role), verification_code = VALUES(verification_code), created_at = VALUES(created_at)',
        [
            'id' => $id,
            'email' => normalize_email($email),
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'verification_code' => $verificationCode,
            'created_at' => $now,
        ]
    );
    send_email(
        $email,
        'Verify your JobBridge account',
        '<p>Your verification code is <strong>' . esc($verificationCode) . '</strong>.</p>'
    );
}

function find_pending_user(string $email, string $code): ?array
{
    $statement = run_query(
        'SELECT * FROM pending_users WHERE email = :email AND verification_code = :code LIMIT 1',
        [
            'email' => normalize_email($email),
            'code' => $code,
        ]
    );

    return $statement->fetch() ?: null;
}

function pending_user_is_valid(array $pendingUser): bool
{
    $createdAt = strtotime($pendingUser['created_at']);
    return $createdAt !== false && (time() - $createdAt) <= 1200;
}

function consume_pending_user(array $pendingUser): array
{
    run_query('DELETE FROM pending_users WHERE email = :email', ['email' => $pendingUser['email']]);

    $id = uuid();
    $now = date('Y-m-d H:i:s');
    run_query(
        'INSERT INTO users (id, email, password, role, created_at, updated_at)
         VALUES (:id, :email, :password, :role, :created_at, :updated_at)',
        [
            'id' => $id,
            'email' => $pendingUser['email'],
            'password' => $pendingUser['password'],
            'role' => $pendingUser['role'],
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    $user = find_user_by_email($pendingUser['email']);
    ensure_profile($user);

    return $user;
}

function ensure_profile(array $user): void
{
    $now = date('Y-m-d H:i:s');
    if (($user['role'] ?? '') === 'client') {
        run_query(
            'INSERT IGNORE INTO client_profiles (id, user_id, created_at, updated_at) VALUES (:id, :user_id, :created_at, :updated_at)',
            [
                'id' => uuid(),
                'user_id' => $user['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    } else {
        run_query(
            'INSERT IGNORE INTO freelancer_profiles (id, user_id, created_at, updated_at) VALUES (:id, :user_id, :created_at, :updated_at)',
            [
                'id' => uuid(),
                'user_id' => $user['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}

function find_profile(array $user): ?array
{
    if (($user['role'] ?? '') === 'client') {
        $statement = run_query('SELECT * FROM client_profiles WHERE user_id = :user_id LIMIT 1', ['user_id' => $user['id']]);
        return $statement->fetch() ?: null;
    }

    $statement = run_query('SELECT * FROM freelancer_profiles WHERE user_id = :user_id LIMIT 1', ['user_id' => $user['id']]);
    return $statement->fetch() ?: null;
}

function profile_completion(array $profile, string $role): int
{
    if ($role === 'client') {
        $fields = ['company_name', 'website', 'bio'];
    } else {
        $fields = ['title', 'bio', 'skills', 'location', 'hourly_rate'];
    }

    $filled = 0;
    foreach ($fields as $field) {
        $value = $profile[$field] ?? '';
        if ($value !== '' && $value !== null) {
            $filled++;
        }
    }

    return (int) round(($filled / max(count($fields), 1)) * 100);
}