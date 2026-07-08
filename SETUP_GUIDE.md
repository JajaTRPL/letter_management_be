# 🚀 Developer Setup Guide — UGM Letter Management

> **Last Updated:** 2026-05-28
> This guide is for the initial team onboarding. Follow each section carefully.

---

## 📋 Quick Start

```bash
# 1. Clone both repos
git clone https://github.com/JajaTRPL/letter_management_be.git
git clone https://github.com/JajaTRPL/Letter_Management_fe.git

# 2. Switch to develop branch
cd letter_management_be && git checkout develop
cd ../Letter_Management_fe && git checkout develop
```

---

## 🔧 Backend Setup (Laravel)

### 1. Copy Environment File

```bash
cd letter_management_be
copy .env.example .env
```

> ⚠️ **Never commit `.env` (or any `.env.*` variant except `.env.example`).** The repo's `.gitignore` blocks them; do not bypass it. If you need to share a new env key with the team, add it to `.env.example` with a blank/placeholder value and document it here.

### 2. Replace Google OAuth Credentials

Open `.env` and set the Google Client ID (the only value used at runtime):

```env
GOOGLE_CLIENT_ID=<ask team lead for the shared Client ID>
```

> ⚠️ **The Client ID is shared across the team** — everyone uses the SAME value. Ask the team lead (Jaja) for the real value via private message.
>
> `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI` are loaded by `config/services.php` but **not used** by the current GIS ID-token flow. Leave them blank in `.env` unless you add an Authorization Code flow in the future.

### 3. Database Setup

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=letter-message
DB_USERNAME=postgres
DB_PASSWORD=your-local-db-password    # Fill with your local PostgreSQL password
```

| Variable      | Shared or Personal? | Notes                                |
|---------------|---------------------|--------------------------------------|
| `DB_DATABASE` | **Shared**          | Use `letter-message`                 |
| `DB_USERNAME` | **Personal**        | Your PostgreSQL username             |
| `DB_PASSWORD` | **Personal**        | Your PostgreSQL password             |

### 4. Install Dependencies & Migrate

```bash
composer install
php artisan key:generate    # Required — generates a unique APP_KEY for your environment
php artisan migrate
php artisan db:seed --class=SuperAdminSeeder
php artisan db:seed --class=AcademicStructureSeeder
php artisan db:seed --class=FacultySeeder
php artisan serve
```

> 🔑 **APP_KEY must be unique per environment.** `.env.example` ships with `APP_KEY=` empty on purpose. Do not paste an APP_KEY from another machine, an old `.env`, or any historical commit — generate a fresh one with `php artisan key:generate`. If an environment ever ran with a shared/committed APP_KEY, treat it as compromised and rotate (see [APP_KEY Rotation](#-app_key-rotation-procedure) below).

---

## 🎨 Frontend Setup (Vite + TypeScript)

### 1. Install & Configure

```bash
cd Letter_Management_fe
npm install
```

### 2. Environment File

```bash
cd Letter_Management_fe
copy .env.example .env
```

Then edit `.env`:

```env
VITE_GOOGLE_CLIENT_ID=<same Client ID as backend>
```

> ⚠️ The `VITE_GOOGLE_CLIENT_ID` must match the `GOOGLE_CLIENT_ID` in the backend `.env`. They are the SAME value.
>
> ℹ️ `VITE_GOOGLE_CLIENT_ID` is a public OAuth Client ID — Vite inlines it into the public client bundle at build time, so it is visible to anyone in the browser. That is intended by Google's OAuth design. It is **not** a secret. We still keep it out of the repo so each environment can point at its own Cloud project.

### 3. Run Development Server

```bash
npm run dev
```

---

## 🔐 Google OAuth (UGM SSO) Setup

### Architecture Overview

```
┌──────────────────────┐     credential (ID token)     ┌──────────────────────┐
│   Frontend (Vite)    │ ──────────────────────────────►│   Backend (Laravel)  │
│                      │                                │                      │
│  google.accounts.id  │                                │  GoogleAuthController│
│  .initialize({       │                                │  .verifyIdToken()    │
│    client_id: ...    │                                │  → tokeninfo API     │
│  })                  │                                │  → aud check         │
│                      │◄──────────────────────────────│  → domain check      │
│  Stores: token,      │     { token, user, status }   │  → user upsert       │
│  role, status        │                                │                      │
└──────────────────────┘                                └──────────────────────┘
```

### How It Works

1. **Frontend** loads Google Identity Services (GIS) library
2. User clicks "Masuk dengan Google" → Google One Tap prompt
3. Google returns an **ID token** (credential) to the frontend callback
4. Frontend sends the credential via `POST /api/auth/google`
5. **Backend** verifies the token via `https://oauth2.googleapis.com/tokeninfo`
6. Backend checks:
   - `aud` matches `GOOGLE_CLIENT_ID` (prevents token reuse)
   - Email domain is `@mail.ugm.ac.id` or `@ugm.ac.id`
   - Email is verified by Google
7. If user exists → login using the pre-provisioned role
8. If user is unknown:
   - `@mail.ugm.ac.id` → auto-create as `mahasiswa` with `pending_profile`
   - `@ugm.ac.id` → reject and direct the user to Super Admin

> **Self-registration clarification:** "if new" applies only to
> `@mail.ugm.ac.id`. An unknown `@ugm.ac.id` staff account is rejected with
> "Akun belum terdaftar. Silakan hubungi Super Admin." Existing accounts from
> either UGM domain continue to log in using their pre-provisioned role.

### Required ENV Variables

| Variable | Where | Example | Actually Used? | Shared? |
|----------|-------|---------|----------------|---------|
| `GOOGLE_CLIENT_ID` | Backend `.env` | `your-client-id.apps.googleusercontent.com` | ✅ Yes — `aud` check in `verifyIdToken()` | ✅ Shared |
| `GOOGLE_CLIENT_SECRET` | Backend `.env` | _(leave empty)_ | ❌ Not used by GIS token flow | ✅ Shared |
| `GOOGLE_REDIRECT_URI` | Backend `.env` | _(leave empty)_ | ❌ Not used — no callback route exists | ✅ Shared |
| `VITE_GOOGLE_CLIENT_ID` | Frontend `.env` | Same as `GOOGLE_CLIENT_ID` | ✅ Yes — passed to `google.accounts.id.initialize()` | ✅ Shared |

### Code Locations

| Component | File | Purpose |
|-----------|------|---------|
| Backend Controller | `app/Http/Controllers/GoogleAuthController.php` | Token verification, user upsert, domain check |
| Backend Config | `config/services.php` → `google` section | Reads `GOOGLE_CLIENT_ID` from `.env` |
| Backend Route | `routes/api.php` → `POST /api/auth/google` | Public endpoint for Google login |
| Backend Route | `routes/api.php` → `POST /api/auth/complete-profile` | Profile completion (authenticated) |
| Frontend Login | `src/login/Login.ts` | GIS initialization, credential callback |
| Frontend Config | `.env` → `VITE_GOOGLE_CLIENT_ID` | Passed to `google.accounts.id.initialize()` |
| Frontend Status | `src/shared/user-status.ts` | `UserStatus.PENDING_PROFILE` constant |
| Frontend Completion | `src/mahasiswa/ProfileCompletion.ts` | Profile completion form for new Google users |

---

## 🌐 Google Cloud Console Setup

> Only the **project owner** needs to do this. Developers just use the shared credentials.

### If You Need to Create Your Own OAuth Client:

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Select or create a project
3. Navigate to **APIs & Services → Credentials**
4. Click **Create Credentials → OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Add these **Authorized JavaScript origins** (required for GIS to work):
   - `http://localhost:5173` (Vite dev server — **required**)
   - `http://localhost:8000` (Laravel dev server — recommended)
   - `http://localhost` (general — optional)
7. **Authorized redirect URIs** are not used by the GIS ID-token flow. You may leave this section empty or add:
   - `http://localhost:5173` (optional, for future use)
8. Navigate to **APIs & Services → OAuth consent screen**
9. Add **Test users** (your `@mail.ugm.ac.id` or `@ugm.ac.id` emails)

### Domain Restrictions (Backend-Enforced)

The backend allows Google login from these email domains:
- `@mail.ugm.ac.id` (student emails; unknown accounts may self-register as Mahasiswa)
- `@ugm.ac.id` (staff emails; account must already be provisioned by Super Admin)

There is no local-part/NIM email regex. Self-registration is determined by the
exact student domain. Both domains remain valid for login to an existing,
pre-provisioned account.

This is enforced in `GoogleAuthController.php`:
```php
private const ALLOWED_DOMAINS = ['mail.ugm.ac.id', 'ugm.ac.id'];
private const STUDENT_SELF_REGISTRATION_DOMAIN = 'mail.ugm.ac.id';
```

---

## ✉️ Forgot Password Email Setup

Forgot-password messages are sent synchronously by the Laravel backend, so a
queue worker is not required for this flow. The default local configuration uses
the `log` mailer; the rendered HTML and plain-text message is written to the
Laravel log and the OTP is never returned to the browser.

For local inbox testing with Mailpit, set:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_SCHEME=null
MAIL_FROM_ADDRESS=no-reply@example.test
MAIL_FROM_NAME="Sistem Persuratan DTEDI"
```

Production and staging SMTP credentials must be supplied through environment
secrets and must not be committed. `PASSWORD_RESET_SIMULATION=false` is the safe
default. The simulation response can activate only when that flag is explicitly
set to `true` and `APP_ENV=local`; never enable it in staging or production.

The reset policy defaults to a 10-minute OTP, five verification attempts, a
60-second resend cooldown, and a separate 10-minute one-time reset token. See
`.env.example` for the available policy variables.

Active accounts originally created through Google login may use the verified
email OTP flow to set a local password. This does not change the account's role,
scope, Google link, or lifecycle rules. Suspended and incomplete accounts do not
receive reset email, and normal profile-completion middleware still applies on
the next login.

The current frontend authenticates API calls with Sanctum bearer tokens, not
session cookies. A successful password reset deletes all personal access tokens
and any database-session rows linked through `sessions.user_id`. The API
middleware stack does not currently expose a supported cookie-authenticated API
flow, so browser QA should verify bearer-token invalidation rather than claim a
separate cookie-auth flow.

---

## 📊 Complete ENV Variable Reference

### Shared Values (Same for Everyone)

| Variable | Value | Notes |
|----------|-------|-------|
| `APP_ENV` | `local` | Development mode |
| `APP_DEBUG` | `true` | Show detailed errors |
| `APP_URL` | `http://localhost:8000` | Backend URL |
| `DB_CONNECTION` | `pgsql` | PostgreSQL |
| `DB_HOST` | `127.0.0.1` | Localhost |
| `DB_PORT` | `5432` | Default PG port |
| `DB_DATABASE` | `letter-message` | Database name |
| `GOOGLE_CLIENT_ID` | _ask team lead_ | Shared OAuth Client ID (only env key used at runtime) |
| `GOOGLE_CLIENT_SECRET` | _(leave empty)_ | Loaded by config but **not used** by GIS token flow |
| `GOOGLE_REDIRECT_URI` | _(leave empty)_ | Loaded by config but **not used** — no callback route |
| `VITE_GOOGLE_CLIENT_ID` | Same as `GOOGLE_CLIENT_ID` | Frontend uses this |
| `TEMPLATE_BEASISWA_ID` | `1QeM5eAy2KaNiAS-q6jiD88rme2iPnomh` | Google Docs template (rotated; old ID deprecated after folder exposure) |

### Personal Values (Must Change Per Developer)

| Variable | Default | What to Change |
|----------|---------|----------------|
| `DB_USERNAME` | `postgres` | Your PostgreSQL username |
| `DB_PASSWORD` | `123` | Your PostgreSQL password |
| `APP_KEY` | _empty in `.env.example`_ | Run `php artisan key:generate` (required) — must be unique per developer/environment, never reused |

---

## ⚠️ Security Notes

> Onboarding-era shortcuts have been cleaned up. The notes below reflect the current state.

### Current Posture
- No `.env`, `.env.*` (other than `.env.example`), private keys, or DB dumps are tracked.
- Backend `.env.example` ships with empty `APP_KEY=`, empty `DB_PASSWORD=`, and placeholder-only Google OAuth keys — all must be filled locally.
- Frontend `.env.example` ships only with a placeholder `VITE_GOOGLE_CLIENT_ID`.
- `.env.example` must **never** contain real credentials. Only placeholders (e.g., `your-google-client-id.apps.googleusercontent.com`) are acceptable.

### Historical Cleanup
- [x] Removed `.env.shared` from tracked tree (commit `90875b0`); replaced with safe `.env.example`. _(2026-05-28)_
- [x] Broadened backend `.gitignore` to `.env`, `.env.*`, `!.env.example` so future variants stay out. _(2026-05-28)_
- [x] Removed frontend `.env` from tracked tree; added safe `.env.example`. _(2026-05-28)_
- [x] Generated unique `APP_KEY` per developer is now standard (no committed shared key). _(2026-05-28)_
- [x] Removed real `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (GOCSPX-…), and `GOOGLE_REDIRECT_URI` from both `.env.example` files; replaced with safe placeholders. _(2026-05-29)_
- [ ] Purge `.env.shared` and old `.env.example` commits containing real secrets from git history with `git filter-repo`. Real `GOOGLE_CLIENT_SECRET` and `GOOGLE_CLIENT_ID` values exist in history and should be treated as compromised until purged.
- [ ] **Rotate the Google OAuth Client Secret** in Google Cloud Console — the real secret was committed to `.env.example` in tracked history.

### Outstanding (Operational, Not in Git)
- [ ] Rotate the Google OAuth Client Secret in Google Cloud Console (required — real secret was in tracked `.env.example`).
- [ ] Purge secrets from git history with `git filter-repo` or BFG.
- [ ] Set up proper secret management for future production deployment (e.g., a vault or environment-specific config service) — out of scope for current local-dev workflow.

---

## 🔑 APP_KEY Rotation Procedure

`APP_KEY` is the symmetric key Laravel uses to encrypt session cookies, signed URLs, and any data passed through the `Crypt` / `encrypted` cast. If a key was ever reused across environments — or was committed to the repo and then pulled into a non-throwaway environment — treat it as compromised and rotate.

### Per-environment rotation steps

For each environment (local, staging, production) that previously used a leaked APP_KEY:

```bash
# 1. Generate a fresh key (does NOT touch .env if you pass --show)
php artisan key:generate --show

# 2. Set APP_KEY in that environment's .env to the printed value
#    (or omit --show to write directly to the local .env)

# 3. Drop any cached config that captured the old key
php artisan config:clear
php artisan optimize:clear

# 4. Restart the PHP process (php-fpm / Laravel Octane / artisan serve)
```

### Expected impact after rotation
- All existing **session cookies** become invalid → every user must sign in again.
- All previously issued **signed URLs** (password-reset links, signed download URLs) stop verifying → reissue if needed.
- Any column written with the `encrypted` cast under the old key cannot be decrypted with the new key. Audit your migrations and casts before rotating in an environment with persisted encrypted data.
- Queue payloads that were encrypted under the old key (e.g. `ShouldBeEncrypted` jobs) cannot be decoded; drain or replay-from-source.

### DB password
The old `.env.shared` carried `DB_PASSWORD=123`, a local-only dev default. Rotate the password on any shared dev/staging database that ever accepted `123`. A throwaway local DB can be left alone but should not be reused with that password in a shared context.

---

## 🆘 Troubleshooting

| Problem | Solution |
|---------|----------|
| Google login shows "client_id not configured" | Ensure `VITE_GOOGLE_CLIENT_ID` in frontend `.env` is set correctly |
| Google login returns 401 "Token tidak valid" | Ensure `GOOGLE_CLIENT_ID` in backend `.env` matches frontend |
| "Hanya email @mail.ugm.ac.id yang diizinkan" | You must use a UGM email address |
| Database connection refused | Check `DB_USERNAME` and `DB_PASSWORD` in your `.env` |
| `php artisan migrate` fails | Ensure PostgreSQL is running and database `letter-message` exists |
| Frontend shows blank page | Run `npm run dev` and check browser console for errors |
