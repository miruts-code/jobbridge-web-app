<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function page_header(string $title): void
{
    $user = current_user();
    $unreadContracts = $user ? count_unread_contract_messages_for_user($user['id']) : 0;
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= esc(APP_NAME . ' - ' . $title) ?></title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body>
    <section class="homepage-header">
        <div class="homepage-header-buttons">
            <a href="<?= esc(app_url('home')) ?>">Home</a>
            <?php if ($user): ?>
                <?php if ($user['role'] === 'client'): ?>
                    <a href="<?= esc(app_url('create-advert')) ?>">Create Advert</a>
                    <a href="<?= esc(app_url('my-jobs')) ?>">My Jobs</a>
                <?php endif; ?>
                <?php if ($user['role'] === 'freelancer'): ?>
                    <a href="<?= esc(app_url('my-applications')) ?>">My Applications</a>
                    <a href="<?= esc(app_url('my-proposals')) ?>">My Proposals</a>
                <?php endif; ?>
                <a href="<?= esc(app_url('my-contracts')) ?>">
                    My Contracts<?php if ($unreadContracts > 0): ?><span class="badge-pill"><?= $unreadContracts ?></span><?php endif; ?>
                </a>
                <a href="<?= esc(app_url('profile')) ?>">My Profile</a>
                <a href="<?= esc(app_url('logout')) ?>">Logout</a>
            <?php else: ?>
                <a href="<?= esc(app_url('login')) ?>">Login</a>
                <a href="<?= esc(app_url('register')) ?>">Register</a>
            <?php endif; ?>
        </div>
        <div class="brand-hero">
            <h1>Build Your Career Future <a class="home-icon" href="<?= esc(app_url('home')) ?>">🏠</a></h1>
            <p>The marketplace where bold talent meets serious clients.</p>
        </div>
        <div class="alerts"><?= render_flash_stack(); ?></div>
    </section>
    <main class="page-shell">
    <?php
}

function page_footer(): void
{
    ?>
    </main>
    <script src="assets/js/main.js"></script>
    </body>
    </html>
    <?php
}

function render_flash_stack(): void
{
    $messages = get_flash_messages();
    if (!$messages) {
        return;
    }
    echo '<div class="flash-stack">';
    foreach ($messages as $flashMessage) {
        echo '<div class="flash ' . esc($flashMessage['type']) . '">' . esc($flashMessage['message']) . '</div>';
    }
    echo '</div>';
}

function list_job_adverts(array $filters = [], int $pageNumber = 1, int $perPage = 9): array
{
    $pageNumber = max(1, $pageNumber);
    $perPage = max(1, $perPage);
    $offset = ($pageNumber - 1) * $perPage;

    $sql = 'SELECT ja.*, u.email AS owner_email
            FROM job_adverts ja
            JOIN users u ON u.id = ja.created_by
            WHERE ja.is_published = 1';
    $params = [];
    if (!empty($filters['location'])) {
        $sql .= ' AND ja.location LIKE :location';
        $params['location'] = '%' . $filters['location'] . '%';
    }
    if (!empty($filters['employment_type'])) {
        $sql .= ' AND ja.employment_type = :employment_type';
        $params['employment_type'] = $filters['employment_type'];
    }
    if (!empty($filters['experience_level'])) {
        $sql .= ' AND ja.experience_level = :experience_level';
        $params['experience_level'] = $filters['experience_level'];
    }

    $sql .= ' ORDER BY ja.created_at DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

    return run_query($sql, $params)->fetchAll();
}

function count_job_adverts(array $filters = []): int
{
    $sql = 'SELECT COUNT(*) AS total FROM job_adverts WHERE is_published = 1';
    $params = [];
    if (!empty($filters['location'])) {
        $sql .= ' AND location LIKE :location';
        $params['location'] = '%' . $filters['location'] . '%';
    }
    if (!empty($filters['employment_type'])) {
        $sql .= ' AND employment_type = :employment_type';
        $params['employment_type'] = $filters['employment_type'];
    }
    if (!empty($filters['experience_level'])) {
        $sql .= ' AND experience_level = :experience_level';
        $params['experience_level'] = $filters['experience_level'];
    }

    return (int) (run_query($sql, $params)->fetch()['total'] ?? 0);
}

function find_job_advert(string $id): ?array
{
    $statement = run_query(
        'SELECT ja.*, u.email AS owner_email
         FROM job_adverts ja
         JOIN users u ON u.id = ja.created_by
         WHERE ja.id = :id AND ja.is_published = 1
         LIMIT 1',
        ['id' => $id]
    );

    return $statement->fetch() ?: null;
}

function count_applications_for_advert(string $id): int
{
    $statement = run_query('SELECT COUNT(*) AS total FROM job_applications WHERE job_advert_id = :id', ['id' => $id]);
    return (int) ($statement->fetch()['total'] ?? 0);
}

function count_proposals_for_advert(string $id): int
{
    $statement = run_query('SELECT COUNT(*) AS total FROM proposals WHERE job_advert_id = :id', ['id' => $id]);
    return (int) ($statement->fetch()['total'] ?? 0);
}

function find_advert_for_user(string $advertId, string $userId): ?array
{
    $statement = run_query(
        'SELECT * FROM job_adverts WHERE id = :id AND created_by = :created_by LIMIT 1',
        [
            'id' => $advertId,
            'created_by' => $userId,
        ]
    );

    return $statement->fetch() ?: null;
}

function find_contract(string $contractId): ?array
{
    $statement = run_query(
        'SELECT c.*, ja.title, ja.company_name, cu.email AS client_email, fu.email AS freelancer_email
         FROM contracts c
         JOIN job_adverts ja ON ja.id = c.job_advert_id
         JOIN users cu ON cu.id = c.client_id
         JOIN users fu ON fu.id = c.freelancer_id
         WHERE c.id = :id
         LIMIT 1',
        ['id' => $contractId]
    );

    return $statement->fetch() ?: null;
}

function find_contract_for_user(string $contractId, string $userId): ?array
{
    $statement = run_query(
        'SELECT c.*, ja.title, ja.company_name, cu.email AS client_email, fu.email AS freelancer_email
         FROM contracts c
         JOIN job_adverts ja ON ja.id = c.job_advert_id
         JOIN users cu ON cu.id = c.client_id
         JOIN users fu ON fu.id = c.freelancer_id
         WHERE c.id = :id AND (c.client_id = :client_user_id OR c.freelancer_id = :freelancer_user_id)
         LIMIT 1',
        [
            'id' => $contractId,
            'client_user_id' => $userId,
            'freelancer_user_id' => $userId,
        ]
    );

    return $statement->fetch() ?: null;
}

function contract_conversation(string $contractId): array
{
    $statement = run_query('SELECT * FROM conversations WHERE contract_id = :id LIMIT 1', ['id' => $contractId]);
    $conversation = $statement->fetch();
    if ($conversation) {
        return $conversation;
    }

    $now = date('Y-m-d H:i:s');
    run_query(
        'INSERT INTO conversations (id, contract_id, created_at, updated_at)
         VALUES (:id, :contract_id, :created_at, :updated_at)',
        [
            'id' => uuid(),
            'contract_id' => $contractId,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    $statement = run_query('SELECT * FROM conversations WHERE contract_id = :id LIMIT 1', ['id' => $contractId]);
    return $statement->fetch() ?: [];
}

function contract_messages(string $contractId): array
{
    $conversation = contract_conversation($contractId);
    if (!$conversation) {
        return [];
    }

    return run_query(
        'SELECT m.*, u.id AS sender_user_id, u.email AS sender_email
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = :conversation_id
         ORDER BY m.created_at ASC',
        ['conversation_id' => $conversation['id']]
    )->fetchAll();
}

function contract_milestones(string $contractId): array
{
    return run_query(
        'SELECT * FROM milestones WHERE contract_id = :contract_id ORDER BY created_at DESC',
        ['contract_id' => $contractId]
    )->fetchAll();
}

function contract_review(string $contractId): ?array
{
    $statement = run_query('SELECT * FROM reviews WHERE contract_id = :id LIMIT 1', ['id' => $contractId]);
    return $statement->fetch() ?: null;
}

function count_unread_contract_messages_for_user(string $userId): int
{
    $statement = run_query(
        'SELECT COUNT(*) AS total
         FROM messages m
         JOIN conversations c ON c.id = m.conversation_id
         JOIN contracts ct ON ct.id = c.contract_id
         WHERE m.is_read = 0 AND m.sender_id <> :sender_user_id AND (ct.client_id = :client_user_id OR ct.freelancer_id = :freelancer_user_id)',
        [
            'sender_user_id' => $userId,
            'client_user_id' => $userId,
            'freelancer_user_id' => $userId,
        ]
    );

    return (int) ($statement->fetch()['total'] ?? 0);
}

function mark_contract_messages_read(string $contractId, string $userId): void
{
    $conversation = contract_conversation($contractId);
    if (!$conversation) {
        return;
    }

    run_query(
        'UPDATE messages SET is_read = 1
         WHERE conversation_id = :conversation_id AND sender_id <> :user_id AND is_read = 0',
        [
            'conversation_id' => $conversation['id'],
            'user_id' => $userId,
        ]
    );
}

function update_freelancer_rating(string $freelancerId): void
{
    $statement = run_query(
        'SELECT AVG(r.rating) AS avg_rating, COUNT(r.id) AS total_reviews
         FROM reviews r
         JOIN contracts c ON c.id = r.contract_id
         WHERE c.freelancer_id = :freelancer_id',
        ['freelancer_id' => $freelancerId]
    );
    $row = $statement->fetch() ?: [];

    run_query(
        'UPDATE freelancer_profiles
         SET average_rating = :average_rating, total_reviews = :total_reviews, updated_at = :updated_at
         WHERE user_id = :user_id',
        [
            'average_rating' => isset($row['avg_rating']) ? round((float) $row['avg_rating'], 2) : 0,
            'total_reviews' => (int) ($row['total_reviews'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
            'user_id' => $freelancerId,
        ]
    );
}

function public_profile_data(array $user): array
{
    $profile = find_profile($user) ?? [];
    $data = [
        'user' => $user,
        'profile' => $profile,
        'completion' => profile_completion_for_user($user, $profile),
    ];

    if (($user['role'] ?? '') === 'client') {
        $data['total_jobs_posted'] = (int) (run_query('SELECT COUNT(*) AS total FROM job_adverts WHERE created_by = :id', ['id' => $user['id']])->fetch()['total'] ?? 0);
        $data['total_active_contracts'] = (int) (run_query('SELECT COUNT(*) AS total FROM contracts WHERE client_id = :id AND status = "active"', ['id' => $user['id']])->fetch()['total'] ?? 0);
    } else {
        $data['completed_jobs_count'] = (int) (run_query('SELECT COUNT(*) AS total FROM contracts WHERE freelancer_id = :id AND status = "completed"', ['id' => $user['id']])->fetch()['total'] ?? 0);
    }

    return $data;
}

function has_proposed_for_advert(string $advertId, string $freelancerId): bool
{
    $statement = run_query(
        'SELECT id FROM proposals WHERE job_advert_id = :job_advert_id AND freelancer_id = :freelancer_id LIMIT 1',
        [
            'job_advert_id' => $advertId,
            'freelancer_id' => $freelancerId,
        ]
    );

    return (bool) $statement->fetch();
}

function proposals_for_advert(string $advertId): array
{
    return run_query(
        'SELECT p.*, u.email AS freelancer_email
         FROM proposals p
         JOIN users u ON u.id = p.freelancer_id
         WHERE p.job_advert_id = :id
         ORDER BY p.created_at DESC',
        ['id' => $advertId]
    )->fetchAll();
}

function find_proposal(string $proposalId): ?array
{
    $statement = run_query(
        'SELECT p.*, ja.created_by AS advert_owner
         FROM proposals p
         JOIN job_adverts ja ON ja.id = p.job_advert_id
         WHERE p.id = :id
         LIMIT 1',
        ['id' => $proposalId]
    );

    return $statement->fetch() ?: null;
}

function create_contract_from_proposal(array $proposal): void
{
    $existing = run_query('SELECT id FROM contracts WHERE proposal_id = :proposal_id LIMIT 1', ['proposal_id' => $proposal['id']])->fetch();
    $now = date('Y-m-d H:i:s');

    if ($existing) {
        run_query(
            'UPDATE contracts SET status = :status, updated_at = :updated_at WHERE id = :id',
            [
                'status' => 'active',
                'updated_at' => $now,
                'id' => $existing['id'],
            ]
        );

        contract_conversation($existing['id']);
        return;
    }

    $contractId = uuid();
    run_query(
        'INSERT INTO contracts (id, proposal_id, client_id, freelancer_id, job_advert_id, status, started_at, completed_at, created_at, updated_at)
         VALUES (:id, :proposal_id, :client_id, :freelancer_id, :job_advert_id, :status, :started_at, :completed_at, :created_at, :updated_at)',
        [
            'id' => $contractId,
            'proposal_id' => $proposal['id'],
            'client_id' => $proposal['advert_owner'],
            'freelancer_id' => $proposal['freelancer_id'],
            'job_advert_id' => $proposal['job_advert_id'],
            'status' => 'active',
            'started_at' => $now,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    contract_conversation($contractId);
}

function my_jobs_for_user(string $userId): array
{
    return run_query('SELECT * FROM job_adverts WHERE created_by = :id ORDER BY created_at DESC', ['id' => $userId])->fetchAll();
}

function applications_for_advert(string $advertId): array
{
    return run_query(
        'SELECT *
         FROM job_applications
         WHERE job_advert_id = :id
         ORDER BY created_at DESC',
        ['id' => $advertId]
    )->fetchAll();
}

function my_applications_for_user(array $user): array
{
    return run_query('SELECT * FROM job_applications WHERE email = :email ORDER BY created_at DESC', ['email' => $user['email']])->fetchAll();
}

function my_proposals_for_user(string $userId): array
{
    return run_query(
        'SELECT p.*, ja.title, ja.company_name
         FROM proposals p
         JOIN job_adverts ja ON ja.id = p.job_advert_id
         WHERE p.freelancer_id = :id
         ORDER BY p.created_at DESC',
        ['id' => $userId]
    )->fetchAll();
}

function my_contracts_for_user(string $userId): array
{
    return run_query(
        'SELECT c.*, ja.title, ja.company_name
         FROM contracts c
         JOIN job_adverts ja ON ja.id = c.job_advert_id
         WHERE c.client_id = :client_user_id OR c.freelancer_id = :freelancer_user_id
         ORDER BY c.created_at DESC',
        [
            'client_user_id' => $userId,
            'freelancer_user_id' => $userId,
        ]
    )->fetchAll();
}

function profile_completion_for_user(array $user, ?array $profile): int
{
    if (!$profile) {
        return 0;
    }

    return profile_completion($profile, $user['role']);
}