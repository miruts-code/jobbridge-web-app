<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/site.php';

$page = $_GET['page'] ?? 'home';
$user = current_user();

if ($page === 'logout') {
    logout_user();
    flash('success', 'You are now logged out.');
    redirect_to('home');
}

if ($page === 'login' && is_post()) {
    verify_csrf();
    $authUser = authenticate_user((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($authUser) {
        login_user($authUser);
        ensure_profile($authUser);
        flash('success', 'Logged in successfully.');
        redirect_to('home');
    }
    flash('error', 'Invalid email or password.');
    redirect_to('login');
}

if ($page === 'register' && is_post()) {
    verify_csrf();
    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? 'freelancer') === 'client' ? 'client' : 'freelancer';

    if (!$email || !$password) {
        flash('error', 'Email and password are required.');
        redirect_to('register');
    }

    if (find_user_by_email($email)) {
        flash('error', 'Email exists on the platform.');
        redirect_to('register');
    }

    $verificationCode = bin2hex(random_bytes(5));
    create_pending_user($email, $password, $role, $verificationCode);
    flash('success', 'Verification code sent for ' . $email . '.');
    redirect_to('verify-account', ['email' => $email]);
}

if ($page === 'verify-account' && is_post()) {
    verify_csrf();
    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $pendingUser = find_pending_user($email, $code);

    if (!$pendingUser || !pending_user_is_valid($pendingUser)) {
        flash('error', 'Invalid or expired verification code.');
        redirect_to('verify-account', ['email' => $email]);
    }

    $newUser = consume_pending_user($pendingUser);
    login_user($newUser);
    flash('success', 'Account verified and logged in successfully.');
    redirect_to('home');
}

if ($page === 'forgot-password' && is_post()) {
    verify_csrf();
    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $foundUser = find_user_by_email($email);

    if (!$foundUser) {
        flash('error', 'Email not found.');
        redirect_to('forgot-password');
    }

    $token = bin2hex(random_bytes(10));
    run_query(
        'INSERT INTO tokens (id, user_id, token, token_type, created_at) VALUES (:id, :user_id, :token, :token_type, :created_at)',
        [
            'id' => uuid(),
            'user_id' => $foundUser['id'],
            'token' => $token,
            'token_type' => 'password_reset',
            'created_at' => date('Y-m-d H:i:s'),
        ]
    );

    send_email(
        $email,
        'Reset your JobBridge password',
        '<p>Your password reset token is <strong>' . esc($token) . '</strong>.</p>'
        . '<p>Open the reset password page and enter this token with your email address.</p>'
    );

    flash('success', 'Password reset token sent to your email.');
    redirect_to('reset-password', ['email' => $email]);
}

if ($page === 'reset-password' && is_post()) {
    verify_csrf();
    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $token = trim((string) ($_POST['token'] ?? ''));
    $password1 = (string) ($_POST['password1'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');

    if ($password1 !== $password2) {
        flash('error', 'Passwords do not match.');
        redirect_to('reset-password', ['email' => $email, 'token' => $token]);
    }

    $statement = run_query(
        'SELECT t.*, u.email FROM tokens t JOIN users u ON u.id = t.user_id WHERE u.email = :email AND t.token = :token AND t.token_type = :token_type LIMIT 1',
        [
            'email' => $email,
            'token' => $token,
            'token_type' => 'password_reset',
        ]
    );
    $row = $statement->fetch();
    if (!$row) {
        flash('error', 'Invalid or expired password reset link.');
        redirect_to('forgot-password');
    }

    run_query('UPDATE users SET password = :password, updated_at = :updated_at WHERE email = :email', [
        'password' => password_hash($password1, PASSWORD_DEFAULT),
        'updated_at' => date('Y-m-d H:i:s'),
        'email' => $email,
    ]);
    run_query('DELETE FROM tokens WHERE id = :id', ['id' => $row['id']]);
    send_email(
        $email,
        'Your JobBridge password has been reset',
        '<p>Your password was updated successfully.</p>'
    );
    redirect_to('login');
}

if ($page === 'create-advert' && is_post()) {
    $user = require_role('client');
    verify_csrf();

    $deadline = (string) ($_POST['deadline'] ?? '');
    $deadlineDate = DateTimeImmutable::createFromFormat('Y-m-d', $deadline);
    $todayDate = new DateTimeImmutable('today');
    if (!$deadlineDate || $deadlineDate->format('Y-m-d') !== $deadline || $deadlineDate < $todayDate) {
        flash('error', 'Deadline must be today or a future date.');
        redirect_to('create-advert');
    }

    $now = date('Y-m-d H:i:s');
    run_query(
        'INSERT INTO job_adverts (id, title, company_name, employment_type, experience_level, description, job_type, location, is_published, deadline, skills, created_by, created_at, updated_at)
         VALUES (:id, :title, :company_name, :employment_type, :experience_level, :description, :job_type, :location, :is_published, :deadline, :skills, :created_by, :created_at, :updated_at)',
        [
            'id' => uuid(),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'company_name' => trim((string) ($_POST['company_name'] ?? '')),
            'employment_type' => (string) ($_POST['employment_type'] ?? 'full_time'),
            'experience_level' => (string) ($_POST['experience_level'] ?? 'entry'),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'job_type' => (string) ($_POST['job_type'] ?? 'remote'),
            'location' => trim((string) ($_POST['location'] ?? '')) ?: null,
            'is_published' => 1,
            'deadline' => $deadline,
            'skills' => trim((string) ($_POST['skills'] ?? '')),
            'created_by' => $user['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );
    flash('success', 'Advert created successfully.');
    redirect_to('my-jobs');
}

if ($page === 'apply' && is_post()) {
    verify_csrf();
    $advert = find_job_advert((string) ($_POST['job_advert_id'] ?? ''));
    if (!$advert) {
        flash('error', 'Job advert not found.');
        redirect_to('home');
    }

    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $duplicate = run_query('SELECT id FROM job_applications WHERE job_advert_id = :job_advert_id AND LOWER(email) = :email LIMIT 1', [
        'job_advert_id' => $advert['id'],
        'email' => $email,
    ])->fetch();

    if ($duplicate) {
        flash('error', 'You have already applied to this job.');
        redirect_to('advert', ['id' => $advert['id']]);
    }

    $cvPath = store_uploaded_cv_file($_FILES['cv_file'] ?? []);
    if (!$cvPath) {
        flash('error', 'Please upload a CV file.');
        redirect_to('advert', ['id' => $advert['id']]);
    }

    run_query(
        'INSERT INTO job_applications (id, job_advert_id, name, email, portfolio_url, cv_path, status, created_at, updated_at)
         VALUES (:id, :job_advert_id, :name, :email, :portfolio_url, :cv_path, :status, :created_at, :updated_at)',
        [
            'id' => uuid(),
            'job_advert_id' => $advert['id'],
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => $email,
            'portfolio_url' => trim((string) ($_POST['portfolio_url'] ?? '')) ?: null,
            'cv_path' => $cvPath,
            'status' => 'applied',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]
    );
    flash('success', 'Application submitted successfully.');
    redirect_to('advert', ['id' => $advert['id']]);
}

if ($page === 'propose' && is_post()) {
    $user = require_role('freelancer');
    verify_csrf();
    $advert = find_job_advert((string) ($_POST['job_advert_id'] ?? ''));
    if (!$advert) {
        flash('error', 'Job advert not found.');
        redirect_to('home');
    }

    if (has_proposed_for_advert($advert['id'], $user['id'])) {
        flash('error', 'You already submitted a proposal for this advert.');
        redirect_to('advert', ['id' => $advert['id']]);
    }

    run_query(
        'INSERT INTO proposals (id, freelancer_id, job_advert_id, cover_letter, bid_amount, estimated_duration_weeks, status, created_at, updated_at)
         VALUES (:id, :freelancer_id, :job_advert_id, :cover_letter, :bid_amount, :estimated_duration_weeks, :status, :created_at, :updated_at)',
        [
            'id' => uuid(),
            'freelancer_id' => $user['id'],
            'job_advert_id' => $advert['id'],
            'cover_letter' => trim((string) ($_POST['cover_letter'] ?? '')),
            'bid_amount' => (string) ($_POST['bid_amount'] ?? '0'),
            'estimated_duration_weeks' => (int) ($_POST['estimated_duration_weeks'] ?? 1),
            'status' => 'submitted',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]
    );

    flash('success', 'Proposal submitted successfully.');
    redirect_to('advert', ['id' => $advert['id']]);
}

if ($page === 'decide-proposal' && is_post()) {
    $user = require_role('client');
    verify_csrf();
    $proposal = find_proposal((string) ($_POST['proposal_id'] ?? ''));
    if (!$proposal) {
        flash('error', 'Proposal not found.');
        redirect_to('my-jobs');
    }

    $advert = find_job_advert((string) $proposal['job_advert_id']);
    if (!$advert || $advert['created_by'] !== $user['id']) {
        flash('error', 'You are not allowed to decide on this proposal.');
        redirect_to('my-jobs');
    }

    $status = (string) ($_POST['status'] ?? 'rejected');
    if (!in_array($status, ['shortlisted', 'accepted', 'rejected'], true)) {
        $status = 'rejected';
    }

    run_query('UPDATE proposals SET status = :status, updated_at = :updated_at WHERE id = :id', [
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s'),
        'id' => $proposal['id'],
    ]);

    if ($status === 'accepted') {
        create_contract_from_proposal($proposal);
    }

    flash('success', 'Proposal updated successfully.');
    redirect_to('advert-proposals', ['id' => $proposal['job_advert_id']]);
}

if ($page === 'profile' && is_post()) {
    $user = require_login();
    verify_csrf();

    $now = date('Y-m-d H:i:s');
    if ($user['role'] === 'client') {
        run_query(
            'INSERT INTO client_profiles (id, user_id, company_name, website, bio, created_at, updated_at)
             VALUES (:id, :user_id, :company_name, :website, :bio, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE company_name = VALUES(company_name), website = VALUES(website), bio = VALUES(bio), updated_at = VALUES(updated_at)',
            [
                'id' => uuid(),
                'user_id' => $user['id'],
                'company_name' => trim((string) ($_POST['company_name'] ?? '')),
                'website' => trim((string) ($_POST['website'] ?? '')),
                'bio' => trim((string) ($_POST['bio'] ?? '')),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    } else {
        run_query(
            'INSERT INTO freelancer_profiles (id, user_id, title, bio, skills, location, hourly_rate, created_at, updated_at)
             VALUES (:id, :user_id, :title, :bio, :skills, :location, :hourly_rate, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE title = VALUES(title), bio = VALUES(bio), skills = VALUES(skills), location = VALUES(location), hourly_rate = VALUES(hourly_rate), updated_at = VALUES(updated_at)',
            [
                'id' => uuid(),
                'user_id' => $user['id'],
                'title' => trim((string) ($_POST['title'] ?? '')),
                'bio' => trim((string) ($_POST['bio'] ?? '')),
                'skills' => trim((string) ($_POST['skills'] ?? '')),
                'location' => trim((string) ($_POST['location'] ?? '')),
                'hourly_rate' => isset($_POST['hourly_rate']) && $_POST['hourly_rate'] !== '' ? (float) $_POST['hourly_rate'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    flash('success', 'Profile updated successfully.');
    redirect_to('profile');
}

if ($page === 'update-advert' && is_post()) {
    $user = require_role('client');
    verify_csrf();

    $advertId = (string) ($_POST['advert_id'] ?? '');
    $advert = find_advert_for_user($advertId, $user['id']);
    if (!$advert) {
        flash('error', 'Advert not found.');
        redirect_to('my-jobs');
    }

    run_query(
        'UPDATE job_adverts SET title = :title, company_name = :company_name, employment_type = :employment_type, experience_level = :experience_level, description = :description, job_type = :job_type, location = :location, is_published = :is_published, deadline = :deadline, skills = :skills, updated_at = :updated_at WHERE id = :id',
        [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'company_name' => trim((string) ($_POST['company_name'] ?? '')),
            'employment_type' => (string) ($_POST['employment_type'] ?? 'full_time'),
            'experience_level' => (string) ($_POST['experience_level'] ?? 'entry'),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'job_type' => (string) ($_POST['job_type'] ?? 'remote'),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'is_published' => isset($_POST['is_published']) ? 1 : 0,
            'deadline' => (string) ($_POST['deadline'] ?? date('Y-m-d')),
            'skills' => trim((string) ($_POST['skills'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $advertId,
        ]
    );

    flash('success', 'Advert updated successfully.');
    redirect_to('my-jobs');
}

if ($page === 'delete-advert' && is_post()) {
    $user = require_role('client');
    verify_csrf();

    $advertId = (string) ($_POST['advert_id'] ?? '');
    $advert = find_advert_for_user($advertId, $user['id']);
    if (!$advert) {
        flash('error', 'Advert not found.');
        redirect_to('my-jobs');
    }

    run_query('DELETE FROM job_adverts WHERE id = :id', ['id' => $advertId]);
    flash('success', 'Advert deleted successfully.');
    redirect_to('my-jobs');
}

if ($page === 'contract-detail' && is_post()) {
    $user = require_login();
    verify_csrf();

    $contractId = (string) ($_POST['contract_id'] ?? '');
    $contract = find_contract_for_user($contractId, $user['id']);
    if (!$contract) {
        flash('error', 'Contract not found.');
        redirect_to('my-contracts');
    }

    $action = (string) ($_POST['action'] ?? '');
    $conversation = contract_conversation($contract['id']);
    $now = date('Y-m-d H:i:s');

    if ($action === 'send-message') {
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') {
            flash('error', 'Message cannot be empty.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        run_query(
            'INSERT INTO messages (id, conversation_id, sender_id, message, is_read, created_at, updated_at)
             VALUES (:id, :conversation_id, :sender_id, :message, 0, :created_at, :updated_at)',
            [
                'id' => uuid(),
                'conversation_id' => $conversation['id'],
                'sender_id' => $user['id'],
                'message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        flash('success', 'Message sent.');
        redirect_to('contract-detail', ['id' => $contract['id']]);
    }

    if ($action === 'add-milestone') {
        if ($contract['client_id'] !== $user['id']) {
            flash('error', 'Only the client can add milestones.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            flash('error', 'Milestone title is required.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        run_query(
            'INSERT INTO milestones (id, contract_id, title, details, amount, status, created_at, updated_at)
             VALUES (:id, :contract_id, :title, :details, :amount, :status, :created_at, :updated_at)',
            [
                'id' => uuid(),
                'contract_id' => $contract['id'],
                'title' => $title,
                'details' => trim((string) ($_POST['details'] ?? '')),
                'amount' => (string) ($_POST['amount'] ?? '0'),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        flash('success', 'Milestone added.');
        redirect_to('contract-detail', ['id' => $contract['id']]);
    }

    if ($action === 'update-milestone-status') {
        if ($contract['freelancer_id'] !== $user['id']) {
            flash('error', 'Only the freelancer can update milestone progress.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        $milestoneId = (string) ($_POST['milestone_id'] ?? '');
        $milestone = run_query(
            'SELECT * FROM milestones WHERE id = :id AND contract_id = :contract_id LIMIT 1',
            [
                'id' => $milestoneId,
                'contract_id' => $contract['id'],
            ]
        )->fetch();

        if (!$milestone) {
            flash('error', 'Milestone not found.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        if (($milestone['status'] ?? '') === 'approved') {
            flash('warning', 'Milestone is already completed.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        run_query(
            'UPDATE milestones SET status = :status, updated_at = :updated_at WHERE id = :id',
            [
                'status' => 'approved',
                'updated_at' => $now,
                'id' => $milestone['id'],
            ]
        );

        flash('success', 'Milestone marked as completed.');
        redirect_to('contract-detail', ['id' => $contract['id']]);
    }

    if ($action === 'complete-contract') {
        if ($contract['status'] !== 'active') {
            flash('error', 'Only active contracts can be completed.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        run_query(
            'UPDATE contracts SET status = :status, completed_at = :completed_at, updated_at = :updated_at WHERE id = :id',
            [
                'status' => 'completed',
                'completed_at' => $now,
                'updated_at' => $now,
                'id' => $contract['id'],
            ]
        );

        flash('success', 'Contract marked as completed.');
        redirect_to('contract-detail', ['id' => $contract['id']]);
    }

    if ($action === 'submit-review') {
        if ($contract['client_id'] !== $user['id']) {
            flash('error', 'Only the client can leave a freelancer review in this build.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        $rating = (int) ($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            flash('error', 'Rating must be between 1 and 5.');
            redirect_to('contract-detail', ['id' => $contract['id']]);
        }

        run_query(
            'INSERT INTO reviews (id, contract_id, reviewer_id, rating, feedback, created_at, updated_at)
             VALUES (:id, :contract_id, :reviewer_id, :rating, :feedback, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), feedback = VALUES(feedback), updated_at = VALUES(updated_at)',
            [
                'id' => uuid(),
                'contract_id' => $contract['id'],
                'reviewer_id' => $user['id'],
                'rating' => $rating,
                'feedback' => trim((string) ($_POST['feedback'] ?? '')),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        update_freelancer_rating($contract['freelancer_id']);

        flash('success', 'Review submitted.');
        redirect_to('contract-detail', ['id' => $contract['id']]);
    }

    flash('error', 'Unknown contract action.');
    redirect_to('contract-detail', ['id' => $contract['id']]);
}

function page_title_for_route(string $page): string
{
    return match ($page) {
        'home' => 'Home',
        'login' => 'Login',
        'register' => 'Create Account',
        'verify-account' => 'Verify Account',
        'forgot-password' => 'Forgot Password',
        'reset-password' => 'Reset Password',
        'create-advert' => 'Create Advert',
        'update-advert' => 'Update Advert',
        'public-profile' => 'Public Profile',
        'contract-detail' => 'Contract Detail',
        'advert' => 'Job Advert',
        'my-jobs' => 'My Jobs',
        'advert-applications' => 'Applications',
        'advert-proposals' => 'Proposals',
        'my-applications' => 'My Applications',
        'my-proposals' => 'My Proposals',
        'my-contracts' => 'My Contracts',
        'profile' => 'Edit Profile',
        default => ucwords(str_replace('-', ' ', $page)),
    };
}

$title = page_title_for_route($page);
page_header($title);
if (($page === 'home') === false) {
    render_flash_stack();
}

if ($page === 'login') {
    ?>
    <section class="grid grid-centered">
        <div class="span-4 form-card">
            <h2>Login</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                <label>Email<input type="email" name="email" required></label>
                <label>Password<div class="actions"><input type="password" id="login-password" name="password" required><button type="button" data-toggle-password="#login-password">Show</button></div></label>
                <input type="submit" value="Login">
            </form>
            <p class="muted"><a href="<?= esc(app_url('forgot-password')) ?>">Forgot password?</a></p>
        </div>
    </section>
    <?php
} elseif ($page === 'register') {
    ?>
    <section class="grid grid-centered">
        <div class="span-4 form-card">
            <h2>Create account</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                <label>Email<input type="email" name="email" required></label>
                <label>Password<div class="actions"><input type="password" id="register-password" name="password" required><button type="button" data-toggle-password="#register-password">Show</button></div></label>
                <label>Role
                    <select name="role">
                        <option value="freelancer">Freelancer</option>
                        <option value="client">Client</option>
                    </select>
                </label>
                <input type="submit" value="Register">
            </form>
            <p class="muted"><a href="<?= esc(app_url('forgot-password')) ?>">Forgot password?</a></p>
        </div>
    </section>
    <?php
} elseif ($page === 'verify-account') {
    $email = normalize_email((string) ($_GET['email'] ?? ''));
    ?>
    <section class="grid grid-centered">
        <div class="span-4 form-card">
            <h2>Verify account</h2>
            <p class="muted">Enter the code that was generated for <?= esc($email) ?>.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                <label>Email<input type="email" name="email" value="<?= esc($email) ?>" required></label>
                <label>Code<input type="text" name="code" required></label>
                <input type="submit" value="Verify">
            </form>
        </div>
    </section>
    <?php
} elseif ($page === 'forgot-password') {
    ?>
    <section class="grid grid-centered">
        <div class="span-4 form-card">
            <h2>Forgot password</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                <label>Email<input type="email" name="email" required></label>
                <input type="submit" value="Create reset token">
            </form>
        </div>
    </section>
    <?php
} elseif ($page === 'reset-password') {
    $email = normalize_email((string) ($_GET['email'] ?? ''));
    $token = trim((string) ($_GET['token'] ?? ''));
    ?>
    <section class="grid grid-centered">
        <div class="span-4 form-card">
            <h2>Set new password</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                <label>Email<input type="email" name="email" value="<?= esc($email) ?>" required></label>
                <label>Token<input type="text" name="token" value="<?= esc($token) ?>" required></label>
                <label>Password<div class="actions"><input type="password" id="password-one" name="password1" required><button type="button" data-toggle-password="#password-one">Show</button></div></label>
                <label>Confirm password<div class="actions"><input type="password" id="password-two" name="password2" required><button type="button" data-toggle-password="#password-two">Show</button></div></label>
                <input type="submit" value="Reset password">
            </form>
        </div>
    </section>
    <?php
} elseif ($page === 'create-advert') {
    require_role('client');
    ?>
    <section class="grid grid-centered">
        <div class="span-8 form-card">
            <h2>Create advert</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                <label>Title<input type="text" name="title" required></label>
                <label>Company name<input type="text" name="company_name" required></label>
                <label>Employment type
                    <select name="employment_type">
                        <option value="full_time">Full Time</option>
                        <option value="part_time">Part Time</option>
                        <option value="contract">Contract</option>
                        <option value="internship">Internship</option>
                    </select>
                </label>
                <label>Experience level
                    <select name="experience_level">
                        <option value="entry">Entry</option>
                        <option value="mid">Mid</option>
                        <option value="senior">Senior</option>
                    </select>
                </label>
                <label>Job type
                    <select name="job_type">
                        <option value="remote">Remote</option>
                        <option value="onsite">Onsite</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                </label>
                <label>Location<input type="text" name="location"></label>
                <label>Deadline<input type="date" name="deadline" min="<?= esc(date('Y-m-d')) ?>" required></label>
                <label>Skills<input type="text" name="skills" required></label>
                <label>Description<textarea name="description" required></textarea></label>
                <input type="submit" value="Publish advert">
            </form>
        </div>
    </section>
    <?php
} elseif ($page === 'update-advert') {
    $user = require_role('client');
    $advertId = (string) ($_GET['id'] ?? '');
    $advert = $advertId ? find_advert_for_user($advertId, $user['id']) : null;
    if (!$advert) {
        echo '<div class="notice">Advert not found.</div>';
    } else {
        ?>
        <section class="grid grid-centered">
            <div class="span-8 form-card">
                <h2>Update advert</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="advert_id" value="<?= esc($advert['id']) ?>">
                    <label>Title<input type="text" name="title" value="<?= esc($advert['title']) ?>" required></label>
                    <label>Company name<input type="text" name="company_name" value="<?= esc($advert['company_name']) ?>" required></label>
                    <label>Employment type
                        <select name="employment_type">
                            <option value="full_time" <?= $advert['employment_type'] === 'full_time' ? 'selected' : '' ?>>Full Time</option>
                            <option value="part_time" <?= $advert['employment_type'] === 'part_time' ? 'selected' : '' ?>>Part Time</option>
                            <option value="contract" <?= $advert['employment_type'] === 'contract' ? 'selected' : '' ?>>Contract</option>
                            <option value="internship" <?= $advert['employment_type'] === 'internship' ? 'selected' : '' ?>>Internship</option>
                        </select>
                    </label>
                    <label>Experience level
                        <select name="experience_level">
                            <option value="entry" <?= $advert['experience_level'] === 'entry' ? 'selected' : '' ?>>Entry</option>
                            <option value="mid" <?= $advert['experience_level'] === 'mid' ? 'selected' : '' ?>>Mid</option>
                            <option value="senior" <?= $advert['experience_level'] === 'senior' ? 'selected' : '' ?>>Senior</option>
                        </select>
                    </label>
                    <label>Job type
                        <select name="job_type">
                            <option value="remote" <?= $advert['job_type'] === 'remote' ? 'selected' : '' ?>>Remote</option>
                            <option value="onsite" <?= $advert['job_type'] === 'onsite' ? 'selected' : '' ?>>Onsite</option>
                            <option value="hybrid" <?= $advert['job_type'] === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                        </select>
                    </label>
                    <label>Location<input type="text" name="location" value="<?= esc((string) ($advert['location'] ?? '')) ?>"></label>
                    <label>Deadline<input type="date" name="deadline" value="<?= esc($advert['deadline']) ?>" required></label>
                    <label>Skills<input type="text" name="skills" value="<?= esc($advert['skills']) ?>" required></label>
                    <label>Description<textarea name="description" required><?= esc($advert['description']) ?></textarea></label>
                    <label><input type="checkbox" name="is_published" <?= (int) $advert['is_published'] === 1 ? 'checked' : '' ?>> Published</label>
                    <input type="submit" value="Update advert">
                </form>
            </div>
        </section>
        <?php
    }
} elseif ($page === 'public-profile') {
    $targetUserId = (string) ($_GET['id'] ?? '');
    $targetUser = $targetUserId ? run_query('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $targetUserId])->fetch() : null;
    if (!$targetUser) {
        echo '<div class="notice">Public profile not found.</div>';
    } else {
        $profileData = public_profile_data($targetUser);
        $profile = $profileData['profile'];
        ?>
        <section class="grid grid-centered">
            <div class="span-8 card">
                <h2><?= esc($targetUser['email']) ?></h2>
                <div class="meta">
                    <span class="pill"><?= esc($targetUser['role']) ?></span>
                    <span class="pill">Completion: <?= esc((string) $profileData['completion']) ?>%</span>
                </div>
                <?php if ($targetUser['role'] === 'freelancer'): ?>
                    <div class="meta-grid">
                        <div class="meta-item"><strong>Title</strong><span><?= esc((string) ($profile['title'] ?? '-')) ?></span></div>
                        <div class="meta-item"><strong>Location</strong><span><?= esc((string) ($profile['location'] ?? '-')) ?></span></div>
                        <div class="meta-item"><strong>Hourly Rate(ETB)</strong><span><?= esc((string) ($profile['hourly_rate'] ?? '-')) ?></span></div>
                        <div class="meta-item"><strong>Average Rating</strong><span><?= esc((string) ($profile['average_rating'] ?? '0')) ?> / 5 (<?= esc((string) ($profile['total_reviews'] ?? '0')) ?> reviews)</span></div>
                        <div class="meta-item"><strong>Completed Jobs</strong><span><?= esc((string) $profileData['completed_jobs_count']) ?></span></div>
                    </div>
                    <h3>Skills</h3>
                    <p><?= esc((string) ($profile['skills'] ?? 'No skills listed yet.')) ?></p>
                <?php else: ?>
                    <div class="meta-grid">
                        <div class="meta-item"><strong>Company Name</strong><span><?= esc((string) ($profile['company_name'] ?? '-')) ?></span></div>
                        <div class="meta-item"><strong>Website</strong><span><?= esc((string) ($profile['website'] ?? '-')) ?></span></div>
                        <div class="meta-item"><strong>Total Jobs Posted</strong><span><?= esc((string) $profileData['total_jobs_posted']) ?></span></div>
                        <div class="meta-item"><strong>Active Contracts</strong><span><?= esc((string) $profileData['total_active_contracts']) ?></span></div>
                    </div>
                <?php endif; ?>
                <h3>Bio</h3>
                <p><?= esc((string) ($profile['bio'] ?? 'No bio added yet.')) ?></p>
            </div>
        </section>
        <?php
    }
} elseif ($page === 'contract-detail') {
    $user = require_login();
    $contractId = (string) ($_GET['id'] ?? '');
    $contract = $contractId ? find_contract_for_user($contractId, $user['id']) : null;
    if (!$contract) {
        echo '<div class="notice">Contract not found.</div>';
    } else {
        mark_contract_messages_read($contract['id'], $user['id']);
        $messages = contract_messages($contract['id']);
        $milestones = contract_milestones($contract['id']);
        $review = contract_review($contract['id']);
        $conversation = contract_conversation($contract['id']);
        ?>
        <section class="grid">
            <div class="span-8 card">
                <h2><?= esc($contract['title']) ?></h2>
                <div class="meta">
                    <span class="pill">Client: <a data-profile-popup href="<?= esc(app_url('public-profile', ['id' => $contract['client_id']])) ?>"><?= esc((string) ($contract['client_email'] ?? $contract['client_id'])) ?></a></span>
                    <span class="pill">Freelancer: <a data-profile-popup href="<?= esc(app_url('public-profile', ['id' => $contract['freelancer_id']])) ?>"><?= esc((string) ($contract['freelancer_email'] ?? $contract['freelancer_id'])) ?></a></span>
                    <span class="pill">Status: <?= esc($contract['status']) ?></span>
                </div>
                <p><strong>Company:</strong> <?= esc($contract['company_name']) ?></p>
                <p><strong>Started:</strong> <?= esc(format_date($contract['started_at'])) ?></p>
                <p><strong>Completed:</strong> <?= esc(format_date($contract['completed_at'])) ?></p>
                <?php if ($review): ?>
                    <div class="muted-box">
                        <strong>Review</strong>
                        <p>Rating: <?= esc((string) $review['rating']) ?>/5</p>
                        <p><?= esc((string) $review['feedback']) ?></p>
                    </div>
                <?php endif; ?>
                <h3>Milestones</h3>
                <?php if ($milestones): ?>
                    <div class="cards">
                        <?php foreach ($milestones as $milestone): ?>
                            <div class="job-card">
                                <h4><?= esc($milestone['title']) ?></h4>
                                <p><?= esc((string) $milestone['details']) ?></p>
                                <p><strong>Amount:</strong> <?= esc((string) $milestone['amount']) ?></p>
                                <p><strong>Status:</strong> <?= esc((string) ($milestone['status'] !== '' ? ucfirst((string) $milestone['status']) : 'Pending')) ?></p>
                                <?php if ($contract['freelancer_id'] === $user['id'] && ($milestone['status'] ?? '') !== 'approved'): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="contract_id" value="<?= esc($contract['id']) ?>">
                                        <input type="hidden" name="milestone_id" value="<?= esc($milestone['id']) ?>">
                                        <input type="hidden" name="action" value="update-milestone-status">
                                        <input type="submit" value="Mark milestone completed">
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">No milestones yet.</p>
                <?php endif; ?>
                <h3>Messages</h3>
                <div class="muted-box" style="max-height: 320px; overflow:auto;">
                    <?php if ($messages): ?>
                        <?php foreach ($messages as $message): ?>
                            <div style="margin-bottom: 12px;">
                                <strong><a data-profile-popup href="<?= esc(app_url('public-profile', ['id' => $message['sender_user_id']])) ?>"><?= esc($message['sender_email']) ?></a></strong>
                                <span class="muted"><?= esc(format_date($message['created_at'])) ?></span>
                                <div><?= nl2br(esc($message['message'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No messages yet.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="span-4 form-card">
                <h3>Send message</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="contract_id" value="<?= esc($contract['id']) ?>">
                    <input type="hidden" name="action" value="send-message">
                    <label>Message<textarea name="message" required></textarea></label>
                    <input type="submit" value="Send">
                </form>

                <?php if ($contract['client_id'] === $user['id']): ?>
                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <h3>Add milestone</h3>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="contract_id" value="<?= esc($contract['id']) ?>">
                        <input type="hidden" name="action" value="add-milestone">
                        <label>Title<input type="text" name="title" required></label>
                        <label>Details<textarea name="details"></textarea></label>
                        <label>Amount<input type="number" step="0.01" min="0" name="amount"></label>
                        <input type="submit" value="Add milestone">
                    </form>

                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <h3>Complete contract</h3>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="contract_id" value="<?= esc($contract['id']) ?>">
                        <input type="hidden" name="action" value="complete-contract">
                        <input type="submit" value="Mark completed">
                    </form>

                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <h3>Leave review</h3>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="contract_id" value="<?= esc($contract['id']) ?>">
                        <input type="hidden" name="action" value="submit-review">
                        <label>Rating
                            <select name="rating">
                                <option value="5">5</option>
                                <option value="4">4</option>
                                <option value="3">3</option>
                                <option value="2">2</option>
                                <option value="1">1</option>
                            </select>
                        </label>
                        <label>Feedback<textarea name="feedback"></textarea></label>
                        <input type="submit" value="Submit review">
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }
} elseif ($page === 'advert') {
    $advertId = (string) ($_GET['id'] ?? '');
    $advert = $advertId ? find_job_advert($advertId) : null;
    if (!$advert) {
        echo '<div class="notice">Job advert not found.</div>';
    } else {
        $applicationCount = count_applications_for_advert($advert['id']);
        $proposalCount = count_proposals_for_advert($advert['id']);
        $canApplyOrPropose = !$user || $user['role'] === 'freelancer';
        ?>
        <section class="grid">
            <div class="<?= $canApplyOrPropose ? 'span-8 card' : 'span-12 card' ?>">
                <h2><?= esc($advert['title']) ?></h2>
                <div class="meta">
                    <span class="pill"><?= esc($advert['company_name']) ?></span>
                    <span class="pill"><?= esc($advert['employment_type']) ?></span>
                    <span class="pill"><?= esc($advert['job_type']) ?></span>
                    <span class="pill">Deadline: <?= esc(format_date($advert['deadline'])) ?></span>
                </div>
                <p><?= nl2br(esc($advert['description'])) ?></p>
                <p><strong>Skills:</strong> <?= esc($advert['skills']) ?></p>
                <p class="muted">Applications: <?= $applicationCount ?> | Proposals: <?= $proposalCount ?></p>
            </div>
            <?php if ($canApplyOrPropose): ?>
                <div class="span-4 form-card">
                    <h3>Apply</h3>
                    <form method="post" action="<?= esc(app_url('apply')) ?>" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="job_advert_id" value="<?= esc($advert['id']) ?>">
                        <label>Name<input type="text" name="name" required></label>
                        <label>Email<input type="email" name="email" required></label>
                        <label>Portfolio URL<input type="url" name="portfolio_url"></label>
                        <label>CV file<input type="file" name="cv_file" accept=".pdf,.doc,.docx"></label>
                        <input type="submit" value="Submit application">
                    </form>
                    <hr style="border-color: var(--border); margin: 10px 0;">
                    <h3>Submit proposal</h3>
                    <?php if ($user && $user['role'] === 'freelancer' && has_proposed_for_advert($advert['id'], $user['id'])): ?>
                        <div class="notice">You already submitted a proposal for this job.</div>
                    <?php elseif ($user && $user['role'] === 'freelancer'): ?>
                        <form method="post" action="<?= esc(app_url('propose')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="job_advert_id" value="<?= esc($advert['id']) ?>">
                            <label>Cover letter<textarea name="cover_letter" required></textarea></label>
                            <label>Bid amount<input type="number" step="0.01" min="1" name="bid_amount" required></label>
                            <label>Estimated duration (weeks)<input type="number" min="1" name="estimated_duration_weeks" required></label>
                            <input type="submit" value="Submit proposal">
                        </form>
                    <?php else: ?>
                        <div class="notice">Login as a freelancer to submit a proposal.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }
} elseif ($page === 'my-jobs') {
    $user = require_role('client');
    $jobs = my_jobs_for_user($user['id']);
    ?>
    <section class="grid cards">
        <?php foreach ($jobs as $job): ?>
            <div class="job-card">
                <h3><?= esc($job['title']) ?></h3>
                <p class="muted"><?= esc($job['company_name']) ?></p>
                <p><?= esc($job['skills']) ?></p>
                <div class="job-actions">
                    <a class="btn" href="<?= esc(app_url('advert', ['id' => $job['id']])) ?>">View details</a>
                    <a class="btn" href="<?= esc(app_url('update-advert', ['id' => $job['id']])) ?>">Update</a>
                    <form method="post" action="<?= esc(app_url('delete-advert')) ?>" onsubmit="return confirm('Delete this advert?');">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="advert_id" value="<?= esc($job['id']) ?>">
                        <input type="submit" value="Delete">
                    </form>
                    <a class="btn" href="<?= esc(app_url('advert-applications', ['id' => $job['id']])) ?>">View applications</a>
                    <a class="btn" href="<?= esc(app_url('advert-proposals', ['id' => $job['id']])) ?>">View proposals</a>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$jobs): ?>
            <div class="notice">No adverts yet. Create the first listing for your company.</div>
        <?php endif; ?>
    </section>
    <?php
} elseif ($page === 'advert-applications') {
    $user = require_role('client');
    $advertId = (string) ($_GET['id'] ?? '');
    $advert = $advertId ? find_job_advert($advertId) : null;
    if (!$advert || $advert['created_by'] !== $user['id']) {
        echo '<div class="notice">You are not allowed to view these applications.</div>';
    } else {
        $applications = applications_for_advert($advert['id']);
        ?>
        <section class="grid cards">
            <?php foreach ($applications as $application): ?>
                <div class="job-card">
                    <h3><?= esc($application['name']) ?></h3>
                    <p class="muted"><?= esc($application['email']) ?></p>
                    <p><strong>Status:</strong> <?= esc($application['status']) ?></p>
                    <div class="application-actions">
                        <?php if (!empty($application['portfolio_url'])): ?>
                            <a class="btn" href="<?= esc((string) $application['portfolio_url']) ?>" target="_blank" rel="noopener">Portfolio</a>
                        <?php endif; ?>
                        <?php if (!empty($application['cv_path'])): ?>
                            <a class="btn" href="<?= esc((string) $application['cv_path']) ?>" target="_blank" rel="noopener">Open CV</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$applications): ?>
                <div class="notice">No applications yet.</div>
            <?php endif; ?>
        </section>
        <?php
    }
} elseif ($page === 'advert-proposals') {
    $user = require_role('client');
    $advertId = (string) ($_GET['id'] ?? '');
    $advert = $advertId ? find_job_advert($advertId) : null;
    if (!$advert || $advert['created_by'] !== $user['id']) {
        echo '<div class="notice">You are not allowed to view these proposals.</div>';
    } else {
        $proposals = proposals_for_advert($advert['id']);
        ?>
        <section class="grid cards">
            <?php foreach ($proposals as $proposal): ?>
                <div class="job-card">
                    <h3><a data-profile-popup href="<?= esc(app_url('public-profile', ['id' => $proposal['freelancer_id']])) ?>"><?= esc($proposal['freelancer_email']) ?></a></h3>
                    <p><?= nl2br(esc($proposal['cover_letter'])) ?></p>
                    <p><strong>Bid:</strong> <?= esc((string) $proposal['bid_amount']) ?></p>
                    <p><strong>Duration:</strong> <?= esc((string) $proposal['estimated_duration_weeks']) ?> weeks</p>
                    <p><strong>Status:</strong> <?= esc($proposal['status']) ?></p>
                    <form method="post" action="<?= esc(app_url('decide-proposal')) ?>" class="actions">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="proposal_id" value="<?= esc($proposal['id']) ?>">
                        <button type="submit" name="status" value="shortlisted">Shortlist</button>
                        <button type="submit" name="status" value="accepted">Accept</button>
                        <button type="submit" name="status" value="rejected">Reject</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (!$proposals): ?>
                <div class="notice">No proposals yet.</div>
            <?php endif; ?>
        </section>
        <?php
    }
} elseif ($page === 'my-applications') {
    $user = require_role('freelancer');
    $applications = my_applications_for_user($user);
    ?>
    <section class="grid cards">
        <?php foreach ($applications as $application): ?>
            <div class="job-card">
                <h3><?= esc($application['name']) ?></h3>
                <p class="muted"><?= esc($application['email']) ?></p>
                <p>Status: <?= esc($application['status']) ?></p>
            </div>
        <?php endforeach; ?>
        <?php if (!$applications): ?>
            <div class="notice">No applications found yet.</div>
        <?php endif; ?>
    </section>
    <?php
} elseif ($page === 'my-proposals') {
    $user = require_role('freelancer');
    $proposals = my_proposals_for_user($user['id']);
    ?>
    <section class="grid cards">
        <?php foreach ($proposals as $proposal): ?>
            <div class="job-card">
                <h3><?= esc($proposal['title']) ?></h3>
                <p class="muted"><?= esc($proposal['company_name']) ?></p>
                <p>Bid: <?= esc((string) $proposal['bid_amount']) ?></p>
                <p>Status: <?= esc($proposal['status']) ?></p>
            </div>
        <?php endforeach; ?>
        <?php if (!$proposals): ?>
            <div class="notice">No proposals yet.</div>
        <?php endif; ?>
    </section>
    <?php
} elseif ($page === 'my-contracts') {
    $user = require_login();
    $contracts = my_contracts_for_user($user['id']);
    ?>
    <section class="grid cards">
        <?php foreach ($contracts as $contract): ?>
            <div class="job-card">
                <h3><?= esc($contract['title']) ?></h3>
                <p class="muted"><?= esc($contract['company_name']) ?></p>
                <p>Status: <?= esc($contract['status']) ?></p>
                <a class="btn" href="<?= esc(app_url('contract-detail', ['id' => $contract['id']])) ?>">Open contract</a>
            </div>
        <?php endforeach; ?>
        <?php if (!$contracts): ?>
            <div class="notice">No contracts yet.</div>
        <?php endif; ?>
    </section>
    <?php
} elseif ($page === 'profile') {
    $user = require_login();
    $profile = find_profile($user) ?? [];
    $completion = profile_completion_for_user($user, $profile);
    ?>
    <section class="grid grid-centered">
        <div class="span-8 form-card">
            <h2>Edit profile</h2>
            <p class="muted">Completion: <?= esc((string) $completion) ?>%</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                <?php if ($user['role'] === 'client'): ?>
                    <label>Company name<input type="text" name="company_name" value="<?= esc((string) ($profile['company_name'] ?? '')) ?>"></label>
                    <label>Website<input type="url" name="website" value="<?= esc((string) ($profile['website'] ?? '')) ?>"></label>
                    <label>Bio<textarea name="bio"><?= esc((string) ($profile['bio'] ?? '')) ?></textarea></label>
                <?php else: ?>
                    <label>Title<input type="text" name="title" value="<?= esc((string) ($profile['title'] ?? '')) ?>"></label>
                    <label>Skills<input type="text" name="skills" value="<?= esc((string) ($profile['skills'] ?? '')) ?>"></label>
                    <label>Location<input type="text" name="location" value="<?= esc((string) ($profile['location'] ?? '')) ?>"></label>
                    <label>Hourly rate(ETB)<input type="number" step="0.01" min="0" name="hourly_rate" value="<?= esc((string) ($profile['hourly_rate'] ?? '')) ?>"></label>
                    <label>Bio<textarea name="bio"><?= esc((string) ($profile['bio'] ?? '')) ?></textarea></label>
                <?php endif; ?>
                <input type="submit" value="Save profile">
            </form>
            <p><a class="btn" href="<?= esc(app_url('public-profile', ['id' => $user['id']])) ?>">View public profile</a></p>
        </div>
    </section>
    <?php
} else {
    $filters = [
        'location' => trim((string) ($_GET['location'] ?? '')),
        'employment_type' => trim((string) ($_GET['employment_type'] ?? '')),
        'experience_level' => trim((string) ($_GET['experience_level'] ?? '')),
    ];
    $currentPage = max(1, (int) ($_GET['pg'] ?? 1));
    $perPage = 9;
    $totalAdverts = count_job_adverts($filters);
    $adverts = list_job_adverts($filters, $currentPage, $perPage);
    $totalPages = max(1, (int) ceil($totalAdverts / $perPage));
    ?>
    <section class="card search-panel">
        <form method="get" action="<?= esc(app_url('home')) ?>">
            <input type="hidden" name="page" value="home">
            <div class="search-grid">
                <div><label>Location<input type="text" name="location" value="<?= esc($filters['location']) ?>" placeholder="City, remote, country"></label></div>
                <div><label>Employment type
                    <select name="employment_type">
                        <option value="" <?= $filters['employment_type'] === '' ? 'selected' : '' ?>>Any</option>
                        <option value="full_time" <?= $filters['employment_type'] === 'full_time' ? 'selected' : '' ?>>Full Time</option>
                        <option value="part_time" <?= $filters['employment_type'] === 'part_time' ? 'selected' : '' ?>>Part Time</option>
                        <option value="contract" <?= $filters['employment_type'] === 'contract' ? 'selected' : '' ?>>Contract</option>
                        <option value="internship" <?= $filters['employment_type'] === 'internship' ? 'selected' : '' ?>>Internship</option>
                    </select>
                </label></div>
                <div><label>Experience level
                    <select name="experience_level">
                        <option value="" <?= $filters['experience_level'] === '' ? 'selected' : '' ?>>Any</option>
                        <option value="entry" <?= $filters['experience_level'] === 'entry' ? 'selected' : '' ?>>Entry</option>
                        <option value="mid" <?= $filters['experience_level'] === 'mid' ? 'selected' : '' ?>>Mid</option>
                        <option value="senior" <?= $filters['experience_level'] === 'senior' ? 'selected' : '' ?>>Senior</option>
                    </select>
                </label></div>
                <div class="search-actions"><input type="submit" value="Search jobs"></div>
            </div>
        </form>
    </section>
    <section class="cards">
        <?php foreach ($adverts as $advert): ?>
            <article class="job-card">
                <h3><?= esc($advert['title']) ?></h3>
                <p><strong>Company:</strong> <?= esc($advert['company_name']) ?></p>
                <p><strong>Type:</strong> <?= esc($advert['job_type']) ?></p>
                <p><strong>Deadline:</strong> <?= esc(format_date($advert['deadline'])) ?></p>
                <p><strong>Skills:</strong> <?= esc(mb_strimwidth($advert['skills'], 0, 80, '...')) ?></p>
                <a class="btn" href="<?= esc(app_url('advert', ['id' => $advert['id']])) ?>">View details</a>
            </article>
        <?php endforeach; ?>
        <?php if (!$adverts): ?>
            <div class="notice">No adverts available yet.</div>
        <?php endif; ?>
    </section>
    <section class="card" style="margin-top: 20px;">
        <div class="actions" style="justify-content: space-between;">
            <?php if ($currentPage > 1): ?>
                <a class="btn" href="<?= esc(app_url('home', array_merge($filters, ['pg' => $currentPage - 1]))) ?>">Previous</a>
            <?php else: ?>
                <span class="pill">Previous</span>
            <?php endif; ?>
            <span class="pill">Page <?= esc((string) $currentPage) ?> of <?= esc((string) $totalPages) ?></span>
            <?php if ($currentPage < $totalPages): ?>
                <a class="btn" href="<?= esc(app_url('home', array_merge($filters, ['pg' => $currentPage + 1]))) ?>">Next</a>
            <?php else: ?>
                <span class="pill">Next</span>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

page_footer();