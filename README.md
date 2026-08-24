# Letter Management — Backend

Laravel 12 (PHP 8.2+) API for the DTEDI letter-management / persuratan
system: letter applications, room booking, Google SSO (UGM domains),
DOCX→PDF generation via Gotenberg, scholarship/academic workflows.

- **Local development setup:** [`SETUP_GUIDE.md`](SETUP_GUIDE.md) — env
  vars, Google OAuth, forgot-password/mail setup, APP_KEY rotation
  procedure, security notes. Start there if you're onboarding.
- **Production deployment:** this app runs natively on a VPS (PHP-FPM +
  nginx, OPcache tuned, a systemd queue worker, cron-driven scheduler —
  no container for the app itself). Server config and the redeploy script
  live in [`letter_management_be_deploy`](https://github.com/JajaTRPL/letter_management_be_deploy),
  not in this repo. The only containerized piece anywhere in this stack
  is [Gotenberg](https://gotenberg.dev/) (DOCX→PDF conversion) — see
  `docker-compose.gotenberg-new.yml` in the project root and
  `config/document_converter.php`.

## Requirements

- PHP ^8.2, Composer
- PostgreSQL (`DB_CONNECTION=pgsql`)
- Node.js + npm (builds the small Vite-managed assets in `resources/`)
- A running [Gotenberg](https://gotenberg.dev/) instance for DOCX→PDF
  conversion (`DOCUMENT_CONVERTER_URL`, see `config/document_converter.php`)

## Queue worker & scheduler

`QUEUE_CONNECTION=database` and the scheduled jobs in `routes/console.php`
(letter retention purge, room-booking reminders, import-batch purge) need
long-running processes that aren't started by `php artisan serve` /
`artisan.` In production these run as a systemd unit and a cron entry
respectively — see `letter_management_be_deploy`. Locally:

```bash
php artisan queue:work --tries=3 --timeout=90 --sleep=3
php artisan schedule:work   # local convenience loop; production uses real cron
```

## Health check

`GET /up` (Laravel's default health route, registered in
`bootstrap/app.php`).
