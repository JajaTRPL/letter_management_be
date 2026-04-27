# 🚀 Developer Setup Guide — UGM Letter Management

> **Last Updated:** 2026-04-27
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
copy .env.shared .env
```

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
DB_PASSWORD=123          # ← Change this to YOUR PostgreSQL password
```

| Variable      | Shared or Personal? | Notes                                |
|---------------|---------------------|--------------------------------------|
| `DB_DATABASE` | **Shared**          | Use `letter-message`                 |
| `DB_USERNAME` | **Personal**        | Your PostgreSQL username             |
| `DB_PASSWORD` | **Personal**        | Your PostgreSQL password             |

### 4. Install Dependencies & Migrate

```bash
composer install
php artisan key:generate    # Only if APP_KEY is missing
php artisan migrate
php artisan db:seed --class=SuperAdminSeeder
php artisan db:seed --class=AcademicStructureSeeder
php artisan db:seed --class=FacultySeeder
php artisan serve
```

---

## 🎨 Frontend Setup (Vite + TypeScript)

### 1. Install & Configure

```bash
cd Letter_Management_fe
npm install
```

### 2. Environment File

The `.env` file is already committed. It contains:

```env
VITE_GOOGLE_CLIENT_ID=<same Client ID as backend>
```

> ⚠️ The `VITE_GOOGLE_CLIENT_ID` must match the `GOOGLE_CLIENT_ID` in the backend `.env`. They are the SAME value.

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
| `APP_KEY` | _pre-set_ | Run `php artisan key:generate` if empty |

---

## ⚠️ Security Notes

> These are **temporary decisions** for team onboarding. Cleanup will be done later.

### Current Risks
- `.env.shared` contains database credentials (local dev only — acceptable)
- Google OAuth Client ID is shared openly (low risk — it's a public identifier)
- Google OAuth Client Secret is shared via private message (moderate risk)
- `APP_KEY` is committed (acceptable for dev — must regenerate per developer)

### Future Cleanup Checklist
- [ ] Remove `.env.shared` from repo after all developers are set up
- [ ] Add `.env.shared` to `.gitignore`
- [ ] Rotate Google OAuth credentials
- [ ] Generate unique `APP_KEY` per developer
- [ ] Set up proper secret management (e.g., environment-specific configs)
- [ ] Revoke and recreate `GOOGLE_CLIENT_SECRET` after cleanup

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
