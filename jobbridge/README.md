# JobBridge Project Overview

## What This Workspace Contains

This workspace contains a single, standalone JobBridge project located in the `jobbridge/` folder. The project is a complete marketplace web application that runs independently inside that folder.

The rest of the workspace includes older reference material from a prior implementation; those files are kept only as references for structure and behavior. The runnable project and its database schema live under `jobbridge/`.

## High-Level Purpose

JobBridge is a job marketplace web app where:

- clients create and manage job adverts,
- freelancers browse jobs and submit applications or proposals,
- both sides can enter contracts,
- contracts support messages, milestones, completion, and reviews,
- users can register, verify their accounts, log in, and reset passwords,
- users can edit public profiles that are visible to others.

The PHP rewrite keeps the same core business flow as the original app, but uses a simpler custom front controller and direct SQL queries instead of Django models, views, and templates.

## Main Project Structure

```text
jobbridge/
  app/
    auth.php
    config.php
    db.php
    site.php
    helpers.php
  database/
    schema.sql
  media/
    cvs/
  public/
    assets/
      css/
        style.css
      js/
        main.js
    index.php
  README.md
```

## What Each Folder And File Does

### `jobbridge/app/`

This folder contains the shared application logic.

The page and query helpers that used to live in the front controller were moved into `site.php` so the app is easier to maintain.

#### `config.php`

Holds the application constants and environment settings. It defines things like:

- app name,
- base URL path,
- database host, name, user, password,
- character set,
- mail settings for local development.

This file is the central place for configuration. If the project is moved to another machine or the database changes, this is one of the first files to update.

#### `site.php`

Provides the shared page helpers and reusable query logic.

Important responsibilities:

- renders the common page header and footer,
- keeps flash-message output in one place,
- holds shared query helpers for adverts, contracts, proposals, profiles, and search,
- reduces the size of the front controller without changing the app URLs.

Why it matters:

- this file is the main shared application layer for page-level behavior,
- it keeps `public/index.php` focused on routing and page output,
- if you want to adjust cross-page logic, this is one of the first files to inspect.

#### `db.php`

Provides a centralized database connector and query helper.

Important behavior:

- builds the database DSN and returns a reusable connection,
- configures the connection for reliable error reporting and associative fetches,
- exposes `run_query()` as the main helper used throughout the application for executing prepared statements.

Why it matters:

- all database access goes through this file,
- SQL-related issues and query parameter mistakes will surface here first.

#### `helpers.php`

Contains reusable utility functions used across the app.

Important functions include:

- `app_url()` for building internal links,
- `esc()` for HTML escaping,
- `redirect_to()` for HTTP redirects,
- `flash()` and `get_flash_messages()` for session-based alerts,
- `csrf_token()` and `verify_csrf()` for request protection,
- `current_user()`, `login_user()`, `logout_user()`, `require_login()`, and `require_role()` for session auth,
- `format_date()` for readable date output,
- `uuid()` for generating record IDs,
- `send_email()` for local mail delivery (configured for development),
- `store_uploaded_cv_file()` for saving uploaded CV files under `media/cvs/`.

Why it matters:

- this file holds most of the common app behavior,
- it keeps the router from becoming even larger,
- auth, flash messages, CSRF, and email all depend on it.

#### `auth.php`

Handles user account logic.

Important responsibilities:

- registration and login checks,
- pending user verification,
- account verification codes,
- password reset token creation and validation,
- profile creation and lookup,
- profile completion calculations,
- creating the final user record from a pending registration.

Why it matters:

- this is the user account workflow layer,
- it connects registration, verification, password reset, and profile setup,
- if something about accounts breaks, this is one of the first files to inspect.

### `jobbridge/public/`

This folder is the web root and contains the front controller and static assets.

#### `index.php`

The front controller is the single entry point for the app. It loads the shared app layer, routes requests to the appropriate page, handles form submissions, and renders the page-specific templates. The heavier shared logic now lives in `app/site.php`, while this file focuses on dispatch and page output. It implements the primary pages, including:

- home and search,
- authentication (login/register/verify/forgot/reset),
- advert CRUD (create, update, delete, view),
- applications and proposals,
- contract listing and detail (including messages, milestones, and reviews),
- profile edit and public profile views.

The file contains the request flow and the page-specific markup. The reusable SQL helpers were moved out so the file stays thinner and easier to navigate.

Why it matters:

- this is the main entry point for the whole app,
- nearly all page behavior lives here,
- if the app feels broken or the wrong page is rendering, this is the first place to inspect.

### `jobbridge/public/assets/css/style.css`

Contains the full visual design for the standalone application.

What it controls:

- theme colors and panel styling,
- header and navigation appearance,
- card and panel layout,
- form controls and buttons,
- tables and list styling,
- alerts and flash messages,
- section widths and responsive behavior,
- small page transitions and alignment rules.

Why it matters:

- this file defines the app's look and feel,
- layout and alignment fixes (header vs. profile widths) are implemented here,
- the home page filters and profile sections rely on these layout rules.

### `jobbridge/public/assets/js/main.js`

Contains the small amount of client-side JavaScript.

Current purpose:

- toggles password field visibility on login, register, and reset forms.

Why it matters:

- it improves form usability,
- it is intentionally small because most behavior is handled server-side in PHP.

### `jobbridge/database/schema.sql`

Defines the MySQL schema used by the project.

It includes tables for:

- users,
- pending users,
- verification and reset tokens,
- client profiles,
- freelancer profiles,
- job adverts,
- job applications,
- proposals,
- contracts,
- conversations,
- messages,
- milestones,
- reviews.

Why it matters:

- this is the database blueprint,
- importing it creates the tables the app expects,
- if the database is missing or mismatched, the app will fail here first.

### `jobbridge/media/cvs/`

This is the upload destination for CV files.

Why it matters:

- it gives the app a writable location for uploaded freelancer CVs,
- forms and application handling depend on it when file storage is used.

### `jobbridge/README.md`

Contains the setup instructions for the PHP rewrite.

It explains:

- how to install or run it under WAMP,
- how to import the SQL schema,
- how to configure the database connection,
- how to open the app in the browser.

Why it matters:

- it is the quick start guide for the PHP project,
- it is the best file to check first if you want to run the app locally.

## How The App Works

### Request Flow

1. A browser opens the project web root (for example `jobbridge/public/index.php`).
2. The front controller loads the account and helper logic.
3. The requested page is determined from the query string.
4. The router handles GET pages and POST submissions.
5. Database queries use the centralized query helper in `app/db.php`.
6. HTML is rendered directly by the front controller and page fragments.
7. CSS and JavaScript from `public/assets/` are loaded by the page header.

### Data Flow

- Login, register, and password reset use session and token logic in `auth.php`.
- Job adverts, contracts, proposals, and messages are stored in MySQL.
- Profile data comes from the client/freelancer profile tables.
- Flash messages are stored in the session and displayed in the page header.
- Mail sending is configured for local development and can be captured by local mailcatchers.

## Important Notes

- The project is designed to run locally with a MySQL database.
- The main runtime entry is `jobbridge/public/index.php`, which loads the shared logic from `jobbridge/app/site.php`.
- Database access is centralized in `jobbridge/app/db.php`.
- Shared behavior is centralized in `jobbridge/app/helpers.php`.
- Shared page and query helpers are centralized in `jobbridge/app/site.php`.
- Account logic is centralized in `jobbridge/app/auth.php`.
- The stylesheet controls visual alignment and theming.

## In Short

The project you now have is a standalone JobBridge web application that mirrors the original project's marketplace functionality and runs independently inside the `jobbridge/` folder.

If you want to understand the app quickly, start in this order:

1. `jobbridge/README.md`
2. `jobbridge/public/index.php`
3. `jobbridge/app/site.php`
4. `jobbridge/app/auth.php`
5. `jobbridge/app/helpers.php`
6. `jobbridge/app/db.php`
7. `jobbridge/database/schema.sql`
8. `jobbridge/public/assets/css/style.css`
