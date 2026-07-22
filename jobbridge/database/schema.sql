CREATE DATABASE IF NOT EXISTS jobbridge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jobbridge;

CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    email varchar(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('client', 'freelancer') NOT NULL DEFAULT 'freelancer',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS pending_users (
    id CHAR(36) PRIMARY KEY,
    email varchar(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('client', 'freelancer') NOT NULL DEFAULT 'freelancer',
    verification_code VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS tokens (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    token VARCHAR(255) NOT NULL,
    token_type ENUM('password_reset', 'email_verification', 'other') NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_tokens_user_type (user_id, token_type),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS client_profiles (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE,
    company_name VARCHAR(255) DEFAULT '',
    website VARCHAR(255) DEFAULT '',
    bio TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_client_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS freelancer_profiles (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE,
    title VARCHAR(255) DEFAULT '',
    bio TEXT,
    skills VARCHAR(255) DEFAULT '',
    location VARCHAR(255) DEFAULT '',
    hourly_rate DECIMAL(10,2) DEFAULT NULL,
    average_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    total_reviews INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_freelancer_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS job_adverts (
    id CHAR(36) PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    employment_type VARCHAR(50) NOT NULL,
    experience_level VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    job_type VARCHAR(50) NOT NULL,
    location VARCHAR(255) DEFAULT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    deadline DATE NOT NULL,
    skills VARCHAR(255) NOT NULL,
    created_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_job_adverts_filter (is_published, employment_type, experience_level, job_type),
    FULLTEXT KEY ft_job_adverts_search (title, company_name, description, skills, location),
    CONSTRAINT fk_job_adverts_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS job_applications (
    id CHAR(36) PRIMARY KEY,
    job_advert_id CHAR(36) NOT NULL,
    name VARCHAR(50) NOT NULL,
    email varchar(191) NOT NULL,
    portfolio_url VARCHAR(255) DEFAULT NULL,
    cv_path VARCHAR(255) NOT NULL,
    status ENUM('applied', 'reviewing', 'shortlisted', 'rejected', 'accepted') NOT NULL DEFAULT 'applied',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_applications_job_ (job_advert_id, email),
    CONSTRAINT fk_job_applications_job FOREIGN KEY (job_advert_id) REFERENCES job_adverts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS proposals (
    id CHAR(36) PRIMARY KEY,
    freelancer_id CHAR(36) NOT NULL,
    job_advert_id CHAR(36) NOT NULL,
    cover_letter TEXT NOT NULL,
    bid_amount DECIMAL(10,2) NOT NULL,
    estimated_duration_weeks INT NOT NULL,
    status ENUM('submitted', 'shortlisted', 'accepted', 'rejected') NOT NULL DEFAULT 'submitted',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY unique_freelancer_proposal_per_job (freelancer_id, job_advert_id),
    CONSTRAINT fk_proposals_freelancer FOREIGN KEY (freelancer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_proposals_job FOREIGN KEY (job_advert_id) REFERENCES job_adverts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS contracts (
    id CHAR(36) PRIMARY KEY,
    proposal_id CHAR(36) NOT NULL UNIQUE,
    client_id CHAR(36) NOT NULL,
    freelancer_id CHAR(36) NOT NULL,
    job_advert_id CHAR(36) NOT NULL,
    status ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_contracts_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_freelancer FOREIGN KEY (freelancer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_job FOREIGN KEY (job_advert_id) REFERENCES job_adverts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS milestones (
    id CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('pending', 'submitted', 'approved') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_milestones_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS conversations (
    id CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_conversations_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id CHAR(36) PRIMARY KEY,
    conversation_id CHAR(36) NOT NULL,
    sender_id CHAR(36) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS reviews (
    id CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL UNIQUE,
    reviewer_id CHAR(36) NOT NULL,
    rating TINYINT NOT NULL,
    feedback TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_reviews_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE DATABASE IF NOT EXISTS jobbridge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jobbridge;

CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    email varchar(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('client', 'freelancer') NOT NULL DEFAULT 'freelancer',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS pending_users (
    id CHAR(36) PRIMARY KEY,
    email varchar(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('client', 'freelancer') NOT NULL DEFAULT 'freelancer',
    verification_code VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS tokens (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    token VARCHAR(255) NOT NULL,
    token_type ENUM('password_reset', 'email_verification', 'other') NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_tokens_user_type (user_id, token_type),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS client_profiles (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE,
    company_name VARCHAR(255) DEFAULT '',
    website VARCHAR(255) DEFAULT '',
    bio TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_client_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS freelancer_profiles (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE,
    title VARCHAR(255) DEFAULT '',
    bio TEXT,
    skills VARCHAR(255) DEFAULT '',
    location VARCHAR(255) DEFAULT '',
    hourly_rate DECIMAL(10,2) DEFAULT NULL,
    average_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    total_reviews INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_freelancer_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS job_adverts (
    id CHAR(36) PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    employment_type VARCHAR(50) NOT NULL,
    experience_level VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    job_type VARCHAR(50) NOT NULL,
    location VARCHAR(255) DEFAULT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    deadline DATE NOT NULL,
    skills VARCHAR(255) NOT NULL,
    created_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_job_adverts_filter (is_published, employment_type, experience_level, job_type),
    FULLTEXT KEY ft_job_adverts_search (title, company_name, description, skills, location),
    CONSTRAINT fk_job_adverts_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS job_applications (
    id CHAR(36) PRIMARY KEY,
    job_advert_id CHAR(36) NOT NULL,
    name VARCHAR(50) NOT NULL,
    email varchar(191) NOT NULL,
    portfolio_url VARCHAR(255) DEFAULT NULL,
    cv_path VARCHAR(255) NOT NULL,
    status ENUM('applied', 'reviewing', 'shortlisted', 'rejected', 'accepted') NOT NULL DEFAULT 'applied',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_applications_job_email (job_advert_id, email),
    CONSTRAINT fk_job_applications_job FOREIGN KEY (job_advert_id) REFERENCES job_adverts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS proposals (
    id CHAR(36) PRIMARY KEY,
    freelancer_id CHAR(36) NOT NULL,
    job_advert_id CHAR(36) NOT NULL,
    cover_letter TEXT NOT NULL,
    bid_amount DECIMAL(10,2) NOT NULL,
    estimated_duration_weeks INT NOT NULL,
    status ENUM('submitted', 'shortlisted', 'accepted', 'rejected') NOT NULL DEFAULT 'submitted',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY unique_freelancer_proposal_per_job (freelancer_id, job_advert_id),
    CONSTRAINT fk_proposals_freelancer FOREIGN KEY (freelancer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_proposals_job FOREIGN KEY (job_advert_id) REFERENCES job_adverts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS contracts (
    id CHAR(36) PRIMARY KEY,
    proposal_id CHAR(36) NOT NULL UNIQUE,
    client_id CHAR(36) NOT NULL,
    freelancer_id CHAR(36) NOT NULL,
    job_advert_id CHAR(36) NOT NULL,
    status ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_contracts_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_freelancer FOREIGN KEY (freelancer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_job FOREIGN KEY (job_advert_id) REFERENCES job_adverts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS milestones (
    id CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('pending', 'submitted', 'approved') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_milestones_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS conversations (
    id CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_conversations_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id CHAR(36) PRIMARY KEY,
    conversation_id CHAR(36) NOT NULL,
    sender_id CHAR(36) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS reviews (
    id CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL UNIQUE,
    reviewer_id CHAR(36) NOT NULL,
    rating TINYINT NOT NULL,
    feedback TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_reviews_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
);
