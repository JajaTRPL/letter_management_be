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

Open `.env` and replace the placeholder values:

```env
GOOGLE_CLIENT_ID=<ask team lead for the shared Client ID>
GOOGLE_CLIENT_SECRET=<ask team lead for the shared Client Secret>
```

> ⚠️ **These credentials are shared across the team** — everyone uses the SAME values. Ask the team lead (Jaja) for the real values via private message.

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
7. If user exists → login; if new → auto-create as `mahasiswa` with `pending_profile`

### Required ENV Variables

| Variable | Where | Example | Shared? |
|----------|-------|---------|---------|
| `GOOGLE_CLIENT_ID` | Backend `.env` | `1080XXXXX-XXXXX.apps.googleusercontent.com` | ✅ Shared — same for all developers |
| `GOOGLE_CLIENT_SECRET` | Backend `.env` | `GOCSPX-XXXXX` | ✅ Shared — same for all developers |
| `GOOGLE_REDIRECT_URI` | Backend `.env` | _(leave empty — not used in GIS token flow)_ | ✅ Shared |
| `VITE_GOOGLE_CLIENT_ID` | Frontend `.env` | Same as `GOOGLE_CLIENT_ID` | ✅ Shared |

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
6. Add these **Authorized JavaScript origins**:
   - `http://localhost:5173` (Vite dev server)
   - `http://localhost:8000` (Laravel dev server)
   - `http://localhost` (general)
7. Add these **Authorized redirect URIs** (optional for GIS token flow):
   - `http://localhost:5173`
   - `http://localhost:8000/api/auth/google/callback`
8. Navigate to **APIs & Services → OAuth consent screen**
9. Add **Test users** (your `@mail.ugm.ac.id` or `@ugm.ac.id` emails)

### Domain Restrictions (Backend-Enforced)

The backend only allows these email domains:
- `@mail.ugm.ac.id` (student emails)
- `@ugm.ac.id` (staff emails)

This is enforced in `GoogleAuthController.php` line 18:
```php
private const ALLOWED_DOMAINS = ['mail.ugm.ac.id', 'ugm.ac.id'];
```

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
| `GOOGLE_CLIENT_ID` | _ask team lead_ | Shared OAuth Client ID |
| `GOOGLE_CLIENT_SECRET` | _ask team lead_ | Shared OAuth Client Secret |
| `VITE_GOOGLE_CLIENT_ID` | Same as `GOOGLE_CLIENT_ID` | Frontend uses this |
| `TEMPLATE_BEASISWA_ID` | `1wnQYvwVO45M3LDDLEitsfjMFgkwj9S7f` | Google Docs template |

### Personal Values (Must Change Per Developer)

| Variable | Default | What to Change |
|----------|---------|----------------|
| `DB_USERNAME` | `postgres` | Your PostgreSQL username |
| `DB_PASSWORD` | `123` | Your PostgreSQL password |
| `APP_KEY` | _empty in `.env.example`_ | Run `php artisan key:generate` (required) — must be unique per developer/environment, never reused |

---

## ⚠️ Security Notes

> Onboarding-era shortcuts have been cleaned up (2026-05-28). The notes below reflect the current state, not historical risks.

### Current Posture
- No `.env`, `.env.*` (other than `.env.example`), private keys, or DB dumps are tracked.
- Backend `.env.example` ships with empty `APP_KEY=` and empty `DB_PASSWORD=` — both must be filled locally.
- Frontend `.env.example` ships only with a placeholder `VITE_GOOGLE_CLIENT_ID` (public OAuth Client ID; not a secret by Google's design).
- No real Google OAuth **Client Secret** was found in any tracked file — only the literal placeholder string `YOUR_GOOGLE_CLIENT_SECRET_HERE`. If the team's Google Client Secret was shared outside Git (e.g., private message) and is considered compromised, rotate it in Google Cloud Console independently — that rotation is not required by anything in git tracked content.

### Historical Cleanup Completed (2026-05-28)
- [x] Removed `.env.shared` from tracked tree (commit `90875b0`); replaced with safe `.env.example`.
- [x] Broadened backend `.gitignore` to `.env`, `.env.*`, `!.env.example` so future variants stay out.
- [x] Removed frontend `.env` from tracked tree; added safe `.env.example`.
- [ ] Purge `.env.shared` from backend git history with `git filter-repo` (planned; blocked on `git-filter-repo` installation). The leaked `APP_KEY` value still lives in commits from `58e1cf1` through `feb0207`; treat as compromised and follow [APP_KEY Rotation](#-app_key-rotation-procedure) until the rewrite lands.
- [x] Generated unique `APP_KEY` per developer is now standard (no committed shared key).

### Outstanding (Operational, Not in Git)
- [ ] If the team's Google OAuth Client Secret was distributed via private channels and is considered exposed, revoke and recreate it in Google Cloud Console.
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
