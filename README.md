# Feedback Board

A self-hosted customer feedback portal built with **PHP 8.3+, SQLite, PDO, HTML and CSS**. Collect ideas, let customers vote and comment, publish a roadmap, and manage the product queue from a private admin area.

## Features
- Public feedback board with search and status filters
- Upvotes with duplicate-vote protection per browser session
- Comments and basic posting rate limit
- Public roadmap: Planned, In Progress and Completed
- Admin dashboard to create roadmap items, moderate comments, update status and delete submissions
- CSV export and JSON endpoint at `/api/feedback`
- SQLite schema initialized automatically
- CSRF protection, prepared statements, output escaping, hardened sessions and security headers
- Responsive, server-rendered interface with no JavaScript, Node.js, frontend framework or external font dependency

## Requirements
PHP 8.3+ with PDO SQLite enabled, plus a writable `storage/` directory.

## Run locally
```bash
cp .env.example .env
export FEEDBACK_ADMIN_PASSWORD='use-a-long-random-password'
php -S 127.0.0.1:8000 -t public public/router.php
```
Open http://127.0.0.1:8000. The admin login is at `/admin/login`.

## Routes
| Route | Purpose |
|---|---|
| `/` | Public feedback board |
| `/roadmap` | Public roadmap |
| `/feedback/{id}` | Suggestion detail, voting and comments |
| `/submit` | Submit an idea |
| `/admin` | Admin dashboard |
| `/admin/login` | Admin sign-in |
| `/admin/export` | Download CSV |
| `/api/feedback` | JSON feedback API |

## Deploy
**Render:** Connect this repository as a Docker web service using the included `render.yaml`. Set a strong `FEEDBACK_ADMIN_PASSWORD`. The SQLite file is stored on the persistent disk at `/var/data/feedback.sqlite`.

**Docker:**
```bash
docker build -t feedback-board .
docker run --rm -p 8080:8080 -e FEEDBACK_ADMIN_PASSWORD='change-this-password' -v feedback-data:/app/storage feedback-board
``

## Configuration
| Variable | Default | Description |
|---|---|---|
| `FEEDBACK_ADMIN_PASSWORD` | unset | Required admin password |
| `FEEDBACK_DB_PATH` | `storage/feedback.sqlite` | SQLite database path |
| `FEEDBACK_APP_NAME` | Feedback Board | Brand shown in UI |

## Security notes
- Admin login is disabled until a password is configured.
- Set `FEEDBACK_ADMIN_PASSWORD` to a strong, unique secret. A bcrypt/Argon2 hash is also accepted.
- Writes are protected by CSRF tokens; SQL uses prepared statements; output is escaped.
- Votes are limited to one per suggestion per browser session. This is lightweight abuse prevention, not identity-grade voting.
- Use HTTPS, persistent storage and regular backups in production.

## Tests
`php tests/smoke.php` runs repository smoke checks. GitHub Actions runs PHP lint and smoke checks on pushes and pull requests. Confirm the workflow result in the Actions tab after the first push.

## License
MIT
