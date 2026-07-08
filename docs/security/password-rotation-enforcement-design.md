# Password rotation enforcement design

## Status

Design phase: `A4J_PASSWORD_ROTATION_ENFORCEMENT_DESIGN`

Backend implementation phase:
`A4K_PASSWORD_ROTATION_BACKEND_ENFORCEMENT_IMPLEMENTATION`

The approved backend contract is implemented locally. This document does not
authorize enabling flags for real users, mutating existing user data, running
migrations, changing the frontend, or starting a campaign.

Design snapshot: 2026-06-23, local development environment.

## Selected policy

Use a hybrid policy:

- A verified local email/password login is restricted when
  `password_must_rotate = true`.
- A verified Google login remains available because it does not authenticate
  with the local password.
- Google login does not clear the flag. It returns a non-sensitive warning so
  the frontend can direct the user to replace the local password.
- Local login with a required rotation receives a short-lived,
  rotation-only Sanctum token. It never receives a normal application token.
- Normal protected APIs return HTTP `423 Locked` with the stable code
  `PASSWORD_ROTATION_REQUIRED`.
- Successful password rotation revokes all personal access tokens and database
  sessions and requires a fresh login.

This policy provides meaningful local-credential enforcement without making a
working, verified Google identity unusable. It also gives campus operators a
continuity path during staff and administrator campaigns.

## Current verified architecture

### Repository and local schema state

- Backend branch: `develop`
- Frontend branch: `develop`
- Password-origin migration
  `2026_06_20_000000_add_password_origin_metadata_to_users_table`: ran in
  batch 12
- Pending migrations: the three Peminjaman Ruangan migrations dated
  2026-06-18
- Local count-only inventory: 12 users, all classified `legacy_unknown`
  - Mahasiswa: 0
  - Tendik and Akademik: 9
  - Super Admin: 3
  - `password_must_rotate = true`: 0
- The inventory performed no mutation.

### Backend

- Public local login: `POST /api/login` handled by
  `AuthController::login`.
  - Uses `Auth::attempt`.
  - Returns a generic 401 for invalid credentials.
  - Rejects suspended users.
  - Rejects the exact current Mahasiswa NIM/date-of-birth legacy password.
  - Deletes prior personal access tokens and issues a wildcard Sanctum token.
  - Does not inspect `password_must_rotate`.
- Public Google login: `POST /api/auth/google` handled by
  `GoogleAuthController::login`.
  - Verifies the Google ID token and audience.
  - Requires a verified UGM-domain email.
  - Blocks suspended users.
  - Uses the role already stored in the database.
  - Allows student self-onboarding only for the configured student domain.
  - Deletes prior personal access tokens and issues a wildcard Sanctum token.
  - Does not inspect `password_must_rotate`.
- Protected routes use `auth:sanctum`, `check_status`, and then
  `profile_complete`.
- `CheckUserStatus` runs a defense-in-depth suspended-account check and revokes
  all personal access tokens when it blocks a suspended user.
- `EnsureProfileComplete` is the current forced-flow pattern. It returns 403
  and allows a path-based set of completion, logout, profile, and academic
  option endpoints.
- `PasswordCredentialService` centralizes password hashing, metadata, and
  revocation of personal access tokens plus rows in the configured database
  session table.
- OTP reset is a three-step public flow:
  - `POST /api/forgot-password`
  - `POST /api/verify-token`
  - `POST /api/reset-password`
- OTP reset uses a generic request response, hashes the OTP, limits attempts,
  exchanges it for a short-lived hashed reset token, applies a strong password
  rule, records `reset_password_otp`, clears rotation, revokes access, and does
  not log the user in.
- Staff self-service password change is currently part of
  `ProfileController::updateProfile`. It records `self_service_change`, clears
  rotation through the credential service default, and revokes access.
- Super Admin can set passwords for Tendik, Akademik, and Super Admin users.
  Mahasiswa password assignment is prohibited. Staff/admin assignment records
  `super_admin_set` and revokes access on update.
- `User` hides all password-origin fields, the password hash, Google ID, and
  remember token from normal serialization.
- Sanctum 4.3.1 supports token abilities and per-token expiry through
  `createToken(name, abilities, expiresAt)`.

### Frontend

- The frontend is a renderer-based TypeScript application, not a router-based
  SPA. `src/main.ts` chooses login or role redirection using local storage.
- `src/login/Login.ts` directly calls local and Google login endpoints, stores
  the full bearer token as `auth_token` in local storage, and redirects by
  role.
- Local login currently converts every non-404 failure into the same
  invalid-credentials message. It does not inspect a machine-readable error
  code.
- `src/shared/api-client.ts` only attaches the bearer token and returns the raw
  response. It has no central 401, 403, or 423 handling.
- `src/mahasiswa/ProfileCompletion.ts` is the closest existing forced-flow
  pattern. It persists completion state, renders a dedicated blocking page,
  and resumes role redirection after completion.
- `src/login/ResetPassword.ts` already contains the strong-password UI and OTP
  reset state machine. Its reset token state must remain separate from any
  rotation-only token.
- `src/akademik/ProfilKaprodi.ts` has an enabled self-service password form.
- `src/tendik/ProfilTendik.ts` explicitly shows password change as unavailable.

## Policy options

| Option | Security | Continuity | Friction | Complexity | Fit |
|---|---|---|---|---|---|
| Local-password-only | Protects the risky local credential | High | Low | Medium | Good |
| All-login | Blocks all access until local password replacement | Low | High | Medium | Poor because Google does not use the local password |
| Hybrid | Restricts local login, allows verified Google, shows warning | High | High | Medium | Recommended |

All-login enforcement creates an avoidable dependency between two independent
credentials. It is especially risky for Super Admin continuity and confusing
for passwordless Mahasiswa. The hybrid policy retains the security property
that a flagged local password cannot produce normal application access.

## Token model

Future token issuance should stop using wildcard abilities for newly issued
tokens:

| Token type | Abilities | Expiry | Normal API access |
|---|---|---|---|
| Local full token | `app:access`, `auth:local` | Existing application policy | Yes when rotation is not required |
| Google full token | `app:access`, `auth:google` | Existing application policy | Yes, including when only the local password requires rotation |
| Rotation token | `password:rotate` | 15 minutes | No |

The middleware must inspect explicit stored abilities. It must not use
`tokenCan('auth:google')` as the only provenance check because a legacy
wildcard token can satisfy any Sanctum ability check.

Fail-closed compatibility rule:

- A legacy wildcard token continues to work when
  `password_must_rotate = false`.
- A legacy wildcard token is not treated as a Google token.
- When `password_must_rotate = true`, a legacy wildcard token is blocked from
  normal APIs and the user must authenticate again.
- A fresh verified Google login receives the explicit Google abilities and can
  continue under the hybrid policy.

The rotation token should be stored only in frontend session storage under a
dedicated key. It must never be stored as `auth_token` or be accepted by the
normal API client.

## Local login flow

```text
POST /api/login
  |
  +-- invalid credentials ------------------------------> 401 generic response
  |
  +-- suspended ----------------------------------------> 403 current response
  |
  +-- exact predictable Mahasiswa legacy password -----> 401 current recovery guidance
  |
  +-- password_must_rotate = false
  |     |
  |     +-- synchronize profile state
  |     +-- issue explicit local full token
  |     +-- return the current normal login contract
  |
  +-- password_must_rotate = true
        |
        +-- do not update last_login_at as a full login
        +-- end the web/session guard state
        +-- revoke existing tokens and database sessions
        +-- issue 15-minute password:rotate token
        +-- return 423 PASSWORD_ROTATION_REQUIRED
        +-- do not return role, profile, or normal user payload
```

Rotation takes precedence over profile completion. After a successful rotation
and fresh login, the existing profile-completion flow runs normally.

No current-password field is required on the rotation form because the local
password was verified immediately before the short-lived token was issued.

## Google login flow

```text
POST /api/auth/google
  |
  +-- invalid/unverified/wrong audience/domain ---------> current rejection
  |
  +-- suspended ----------------------------------------> 403 current response
  |
  +-- verified identity
        |
        +-- issue explicit app:access + auth:google token
        +-- preserve profile-completion behavior
        +-- if password_must_rotate = true:
              return non-blocking local-password warning metadata
              do not clear the flag
```

The warning action should direct all roles to the verified OTP reset flow.
Akademik may also use its existing authenticated password form. Tendik must not
be directed to its currently disabled password UI.

## Rotation endpoint

Recommended routes:

- `GET /api/auth/password-rotation`
  - Requires `auth:sanctum`, `check_status`, and an exact rotation-token check.
  - Returns minimal state only: required, expiry, and password policy.
- `POST /api/auth/password-rotation`
  - Requires the same middleware.
  - Accepts `password` and `password_confirmation`.
- `POST /api/logout`
  - Must be reachable by both full and rotation-only tokens.

The POST operation should:

1. Validate the current strong rule: at least 10 characters with uppercase,
   lowercase, number, and symbol.
2. Reject reuse of the current password.
3. Lock and re-read the user in a database transaction.
4. Confirm `password_must_rotate` is still true.
5. Replace the password through `PasswordCredentialService`.
6. Record:
   - `password_set_method = self_service_change`
   - `password_set_at = now`
   - `password_set_by_user_id = the same user`
   - `password_must_rotate = false`
7. Invalidate outstanding OTP/reset-token state for the account.
8. Revoke all personal access tokens and database sessions, including the
   rotation token.
9. Return success without a new token and require login again.

The service operation should be atomic and idempotent at the policy boundary.
A replay after completion should not change the password again.

## Middleware and route placement

Add `EnsurePasswordRotationSatisfied` after authentication and suspended-status
checking, but before profile completion and role authorization:

```text
auth:sanctum
  -> check_status
  -> password_rotation_satisfied
  -> profile_complete
  -> role middleware
  -> controller
```

Recommended route structure:

```text
Public throttle group
  /login
  /auth/google
  /forgot-password
  /verify-token
  /reset-password

Authenticated base: auth:sanctum, check_status
  /logout
  /auth/password-rotation GET/POST (exact rotation-token middleware)

  Normal application subgroup: password_rotation_satisfied
    /auth/profile-completion

    Profile-complete subgroup: profile_complete
      /profile
      role-scoped application APIs
```

Physical route grouping is preferred over a growing path allowlist. It makes
the rotation surface fail closed when new application routes are added.

Middleware behavior for normal routes:

- Rotation-only token: always return 423.
- Rotation flag false plus a full or legacy wildcard token: continue.
- Rotation flag true plus explicit Google full token: continue.
- Rotation flag true plus local full token or legacy wildcard token: return
  423.
- Suspended users are rejected first by `check_status`.

Recommended protected-API response:

```json
{
  "success": false,
  "code": "PASSWORD_ROTATION_REQUIRED",
  "message": "Untuk keamanan akun, ganti kata sandi sebelum melanjutkan."
}
```

HTTP 423 is recommended because the denial is a temporary account/credential
state, not a role authorization failure. The stable code remains the frontend
decision point.

## API contracts

### Local login requiring rotation

Status: `423 Locked`

```json
{
  "success": false,
  "code": "PASSWORD_ROTATION_REQUIRED",
  "message": "Untuk keamanan akun, Anda perlu mengganti kata sandi sebelum menggunakan sistem.",
  "rotation_token": "<rotation-only bearer token>",
  "expires_in": 900
}
```

Do not include the user object, role, subrole, password method, password hash,
or account inventory classification.

### Rotation status

Status: `200 OK`

```json
{
  "success": true,
  "code": "PASSWORD_ROTATION_REQUIRED",
  "message": "Untuk keamanan akun, Anda perlu mengganti kata sandi sebelum menggunakan sistem.",
  "expires_at": "2026-06-23T12:15:00+00:00",
  "expires_in": 812
}
```

### Successful rotation

Status: `200 OK`

```json
{
  "success": true,
  "message": "Kata sandi berhasil diperbarui. Silakan login kembali."
}
```

### Invalid, expired, or wrong-purpose token

No, forged, or expired bearer token returns the standard API
`401 Unauthorized` response. An authenticated full-app or wrong-purpose token
returns `403 Forbidden`:

```json
{
  "success": false,
  "code": "ROTATION_TOKEN_REQUIRED",
  "message": "Token penggantian kata sandi diperlukan."
}
```

### Google login warning

The existing successful Google login contract may add:

```json
{
  "password_rotation": {
    "required": true,
    "blocking": false,
    "applies_to": "local_password"
  }
}
```

The response must not expose password origin, campaign reason, or setter
identity.

## Frontend design

### Required files

Likely future changes:

- `src/login/Login.ts`
- new `src/login/PasswordRotation.ts`
- `src/main.ts`
- `src/shared/api-client.ts`
- `src/dashboard/DashboardLayout.ts`
- `src/login/ResetPassword.ts` for shared presentation/validation extraction
  only
- tests under `src/login/__tests__` and `src/test`

Existing role profile pages may receive warning links later:

- `src/akademik/ProfilKaprodi.ts`
- `src/tendik/ProfilTendik.ts`

### Login handling

`Login.ts` must inspect `code` before its generic invalid-credential branch.
For `PASSWORD_ROTATION_REQUIRED`:

1. Clear any full authentication state.
2. Store only the rotation token and expiry in session storage.
3. Render the dedicated password rotation page.
4. Show:
   `Untuk keamanan akun, Anda perlu mengganti kata sandi sebelum menggunakan sistem.`

### Rotation page

The page contains:

- new password
- password confirmation
- visible password-policy guidance
- logout/cancel back to login
- an alternate link to `Lupa Kata Sandi`

It does not ask for the current password. It uses a dedicated request helper
that attaches only the rotation token and can call only the rotation status,
rotation POST, and logout endpoints.

On success:

1. Remove rotation state.
2. Clear full authentication state.
3. Show the backend success message.
4. Render login.
5. Require a fresh login.

On token expiry, clear rotation state and return to login with recovery
guidance.

### Bootstrap and guards

`main.ts` should check for a valid rotation-session marker before normal
`auth_token` role redirection.

If a normal API call returns `PASSWORD_ROTATION_REQUIRED` without a rotation
token, the shared client should:

- clear the stale full token and role state
- return to login
- explain that the user must log in again to start rotation or use forgot
  password

It must not invent a rotation token from the protected-API response.

### Google warning

After Google login, a non-blocking banner in `DashboardLayout.ts` should explain
that only the local password needs replacement. The user remains able to use
the application. The primary action should open the verified OTP reset flow.

The warning must not be represented as profile incompleteness and must not
reuse `auth_requires_completion`.

### Reuse boundary

Visual components and the strong-password validator from `ResetPassword.ts`
may be extracted and reused. OTP email, verification, reset-token state, and
the public reset contract must remain separate from the rotation-only flow.

## Super Admin campaign and continuity

The existing inventory command is sufficient for count-only previews. It is
not a campaign executor.

Campaign mutation and UI should be designed separately before implementation.
Recommended future targets:

- Mahasiswa `legacy_unknown`
- Tendik/Akademik `legacy_unknown`
- all `legacy_unknown` excluding Super Admin
- explicitly selected users

Server-side safeguards:

- Select only users with a non-null local password.
- Never mark a passwordless user for local-password rotation.
- Re-run target counts inside the execution transaction.
- Exclude all Super Admins from mass targets by default.
- Require individual selection for every Super Admin.
- Require an active Super Admin continuity anchor that is excluded from the
  current action.
- Record a recently verified recovery method for the anchor in the private
  change/audit record.
- Rotate Secondary Super Admins one at a time before the Primary Super Admin.
- Verify successful fresh login and required administrative access after each
  administrator rotation.
- Abort if the action would leave no active, verified administrator path.
- Do not accept, generate, display, export, or log a password.
- `temporary_admin` remains unusable until a separate secret-delivery and
  immediate-enforcement design is approved.

Recommended preview fields are counts by role/subrole, excluded counts,
continuity warning, campaign reason, and a masked export reference. No hashes,
tokens, full email addresses, NIP, or NIM belong in the preview.

Audit requirements:

- actor user ID
- campaign ID
- target policy
- affected and excluded counts
- target references suitable for private audit, not public logs
- continuity anchor confirmation
- recovery method category and verification timestamp
- execution timestamp and outcome

The current repository has administrator/activity logging, but the existing
password-origin design deferred a dedicated security-event contract. Campaign
execution should not be implemented until the append-only audit destination is
selected.

## Security and privacy requirements

- No automatic local password generation.
- No NIM, birth date, default, shared, or predictable password.
- No plaintext temporary password.
- No password hash, OTP, reset token, or bearer token in logs.
- Rotation tokens are short-lived, ability-limited, independently stored, and
  revoked after use.
- Rotation attempts receive a dedicated rate limit in addition to the API
  throttle.
- Rotation rejects current-password reuse.
- Outstanding OTP/reset state is invalidated after rotation.
- OTP reset invalidates rotation tokens through full access revocation.
- Controllers remain thin. Credential mutation and revocation remain in
  `PasswordCredentialService` or a focused collaborator.
- Middleware, not frontend state, enforces the restriction.
- Campaign previews and exports remain masked and count-oriented.

## OOP boundaries

Recommended responsibilities:

- `AuthController`
  - validate login input
  - delegate credential authentication and response selection
- `PasswordCredentialService`
  - hash and record credential metadata
  - perform required rotation atomically
  - invalidate outstanding reset state
  - revoke tokens and database sessions
- `EnsurePasswordRotationSatisfied`
  - enforce normal-route access using user state and explicit token provenance
- exact rotation-token middleware
  - admit only a token whose stored abilities are the rotation-only contract
- future campaign service
  - preview, continuity validation, execution, and audit orchestration

Do not place campaign selection, password mutation, token provenance, and API
response construction in one controller.

## Internationalization and time

- Stable machine codes and internal identifiers remain English.
- User-facing messages are Indonesian.
- Persist timestamps using the application/database UTC standard.
- Display operator timestamps in `Asia/Jakarta`.
- Do not parse campaign dates using locale-dependent formats.

## Required backend tests

### Login and token behavior

- Valid local login with rotation false returns an explicit local full token.
- Valid local login with rotation true returns 423, a 15-minute rotation-only
  token, and no user/role/full token payload.
- Invalid credentials remain generic and do not reveal whether rotation is
  required.
- Suspended users remain blocked before rotation processing.
- Exact predictable Mahasiswa legacy password remains blocked by the current
  recovery response.
- Rotation login does not update `last_login_at` as a full login.
- Google login with rotation true returns a full explicit Google token and a
  non-blocking warning.
- Google login never clears the flag.
- Legacy wildcard token is blocked when the flag is true.
- Explicit Google token is allowed when the flag is true.

### Middleware and routes

- Rotation-only token cannot access profile, dashboard, role, storage, or
  application endpoints.
- Local full token is blocked after the flag becomes true.
- Protected denial is 423 with `PASSWORD_ROTATION_REQUIRED`.
- Suspended denial still wins over rotation denial.
- Profile completion still works after rotation and fresh login.
- Logout is available with both full and rotation-only tokens.
- New protected routes inherit the middleware through group placement.

### Rotation operation

- Valid rotation token and strong password succeed.
- Weak, mismatched, or current password is rejected.
- Expired, forged, wildcard, full, and replayed tokens are rejected.
- Rotation clears the flag and records `self_service_change`, timestamp, and
  self setter.
- Rotation revokes all personal access tokens and database sessions.
- Rotation invalidates outstanding OTP/reset-token state.
- A concurrent second rotation cannot overwrite the first successful result.
- Success does not issue a full application token.

### Existing recovery and administration

- OTP reset continues to record `reset_password_otp`, clear the flag, revoke
  all access, and require login.
- Super Admin staff password assignment behavior remains attributed.
- Mahasiswa password assignment and automatic password generation remain
  prohibited.
- Responses and captured logs contain no password hash, OTP, reset token,
  bearer token, or excessive PII.

### Future campaign tests

- Preview is count-only and performs no mutation.
- Passwordless users are ineligible.
- Mass targets exclude all Super Admins.
- Individual Super Admin action requires a continuity anchor.
- An action cannot select every active Super Admin.
- Anchor and recovery confirmation are revalidated at execution.
- Campaign audit contains safe identifiers and no secrets.

## Required frontend tests

- Local login handles `PASSWORD_ROTATION_REQUIRED` before the generic error.
- Rotation token is stored separately in session storage.
- Rotation-only state never sets `auth_token`, role, or dashboard state.
- Rotation page validates confirmation and the strong password rule.
- Successful rotation clears all auth state and returns to login.
- Expired rotation state returns to login with recovery guidance.
- Main bootstrap renders rotation before normal role redirection.
- Normal API 423 clears stale full auth and returns to login.
- Rotation token cannot be sent through the normal API client.
- Google login warning is non-blocking.
- Google warning links to the OTP reset flow.
- Profile completion still takes effect after rotation is satisfied.
- No normal dashboard renders from a rotation-only token.

## Rollout order

1. Approve this policy and API contract.
2. Implement backend login token provenance, rotation endpoint, middleware, and
   tests in `A4K_PASSWORD_ROTATION_BACKEND_ENFORCEMENT_IMPLEMENTATION`.
3. Accept the backend contract.
4. Implement the dedicated frontend flow and warning in
   `A4L_PASSWORD_ROTATION_FRONTEND_FLOW_IMPLEMENTATION`.
5. Design campaign persistence, audit, approvals, and continuity UI in
   `A4M_SUPER_ADMIN_ROTATION_CAMPAIGN_DESIGN`.
6. Only after backend, frontend, recovery, tests, communication, and operator
   runbooks are ready may a separate approved phase set rotation flags.

## Remaining approval decisions

The design recommends, but does not authorize:

- hybrid enforcement
- a 15-minute rotation-only Sanctum token
- HTTP 423 for protected API denial
- explicit token provenance abilities
- a separate A4M campaign/audit design before campaign implementation

Product/security/operations must still approve:

- the exact rotation-token lifetime
- rate-limit values
- the audit store for campaign/security events
- the recovery-verification freshness rule for a Super Admin continuity anchor
- whether a Google-warning banner is dismissible
- campaign communication and grace periods

## Non-goals

- No enforcement implementation
- No rotation flag updates
- No migration or rollback
- No database mutation
- No password cleanup or nullification
- No user lockout
- No frontend implementation
- No Peminjaman changes
- No staging or production access
- No commit, push, merge, rebase, reset, stash, or branch switch
