<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const APP_NAME = 'JobBridge';
const APP_BASE_PATH = '/jobbridge/jobbridge/public';
const APP_ENV = 'local';
const DB_HOST = '127.0.0.1';
const DB_NAME = 'jobbridge';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

// Local-only mail catcher settings for WAMP development.
// MailHog/Mailpit should listen on 127.0.0.1:1025 and display messages at http://localhost:8025.
const MAIL_HOST = '127.0.0.1';
const MAIL_PORT = 1025;
const MAIL_FROM = 'noreply@jobbridge.local';

?>