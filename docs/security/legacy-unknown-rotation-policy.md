# Legacy unknown password rotation policy

## Status and scope

This document defines policy only. It does not authorize a migration, password
nullification, rotation flag update, account lockout, or production/staging
operation.

`legacy_unknown` means that a user has a non-null local password but the system
cannot reliably prove how, when, or by whom that credential was set. It is not a
safe classification. A password that does not match the current Mahasiswa NIM
and birth-date pattern may still have been derived from historical identity
data.

Passwordless state is represented only by `users.password IS NULL`. A
passwordless user must not be classified as `legacy_unknown`.

## Current behavior boundary

The application currently records password-origin metadata but does not enforce
`password_must_rotate`.

- Local login verifies email and password, rejects suspended users, and blocks
  a Mahasiswa password that exactly matches the current NIM plus current birth
  date pattern.
- The exact-pattern block is a narrow compensating control. It does not prove
  that other `legacy_unknown` passwords are safe.
- Verified forgot-password OTP replacement records `reset_password_otp`, clears
  the rotation flag, and revokes personal access tokens and database sessions.
- Super Admin password assignment is prohibited for Mahasiswa and records
  `super_admin_set` for Tendik, Akademik, and Super Admin accounts.
- Staff self-service replacement records `self_service_change` and revokes
  access.
- Google login verifies the Google identity and uses the role stored in the
  local database. It does not verify or use the local password.
- No middleware, token ability, API response, or frontend component currently
  restricts access based on `password_must_rotate`.

Rotation flags must not be enabled until the enforcement API, middleware, UI,
recovery flow, tests, communication, and operator runbook are ready together.

## Role-based policy

### Mahasiswa

- Provisioning model: passwordless by default through Google onboarding, Super
  Admin creation, or CSV import.
- Local password policy: allowed only after verified forgot-password OTP.
- `legacy_unknown` risk: high. A historical student password may be derived from
  identity data even when the current known pattern does not match.
- Recommended treatment: after an approved staging/production inventory,
  remove or disable the unknown local credential and require Google UGM or
  verified OTP reset to establish a new local credential.
- Immediate bulk action: prohibited until inventory, communication, recovery,
  dry-run review, and change approval are complete.
- Recovery: Google UGM when available; otherwise verified email OTP.
- Break-glass: not normally required, but help-desk handling is required for a
  student who cannot access either Google UGM or the registered email account.

### Tendik

- Provisioning model: pre-provisioned by Super Admin. Supported subroles are
  Persuratan, Sarpras, Kepala Lab, and Laboran.
- Local password policy: allowed.
- `legacy_unknown` risk: medium to high because the credential remains a valid
  login path with unknown provenance.
- Immediate invalidation risk: high for operational staff and laboratory
  workflows.
- Recommended treatment: communicated, role-scoped rotation campaign with a
  grace period. Enforce on local-password login only after recovery is tested.
- Recovery: verified OTP reset. Google may be a continuity path for a
  pre-provisioned UGM email, but operators must verify the real account and
  Google configuration before relying on it.
- UI requirement: Tendik currently lacks an enabled self-service password
  change experience and must receive either dedicated rotation UI or clear OTP
  reset guidance before enforcement.

### Akademik Prodi

This class covers `kaprodi` and `sekprodi`.

- Provisioning model: pre-provisioned by Super Admin with Program Studi scope.
- Local password policy: allowed.
- `legacy_unknown` risk: medium to high.
- Immediate invalidation risk: high around approval and academic service
  deadlines.
- Recommended treatment: communicated rotation campaign with deadline-aware
  scheduling, followed by local-login enforcement.
- Recovery: verified OTP reset; verified Google login may provide continuity.
- UI requirement: an existing profile password form can be adapted, but forced
  rotation still needs explicit state, routing, and failure handling.

### Akademik Departemen

This class covers `kadep` and `sekdep`.

- Provisioning model: pre-provisioned by Super Admin with Departemen scope.
- Local password policy: allowed.
- `legacy_unknown` risk: medium to high.
- Immediate invalidation risk: high because department approval continuity may
  depend on a small number of accounts.
- Recommended treatment: the same campaign model as Akademik Prodi, with
  operator confirmation that another authorized approver remains available.
- Recovery: verified OTP reset; verified Google login may provide continuity.

### Super Admin

- Provisioning model: Primary Super Admin-controlled.
- Local password policy: allowed and required as one supported continuity path.
- `legacy_unknown` risk: critical because compromise grants administrative
  control.
- Immediate mass invalidation risk: critical because it can remove every
  recovery and administration path.
- Recommended treatment: staged, individually verified rotation. Rotate
  secondary administrators first, verify successful access and recovery, then
  rotate the Primary Super Admin while another verified administrative path is
  available.
- Recovery: verified Google login, working OTP email delivery, or an authorized
  manual break-glass procedure. A path is not considered available merely
  because the code supports it; it must be tested in the target environment.
- Break-glass: mandatory before any Super Admin enforcement or password
  invalidation.

### Other roles

The current user validation surface permits only `mahasiswa`, `tendik`,
`akademik`, and `super_admin` as top-level roles. New roles must receive an
explicit provisioning, recovery, and continuity classification before they are
included in a rotation campaign.

## Policy options

| Option | Security | Disruption | Complexity | Auditability | Practicality |
|---|---|---|---|---|---|
| A. Immediate rotation for all roles | High after enforcement is complete | High | High | High | Unsafe before UI and recovery readiness |
| B. Role-scoped strictness | High and risk-proportionate | Medium | High | High | Recommended target policy |
| C. Soft campaign with deadline | Medium during grace period, high after enforcement | Low to medium | Medium | High if notices and acknowledgements are retained | Recommended rollout mechanism for staff |
| D. Inventory and monitor only | Low | Low | Low | Medium | Appropriate only as a temporary local/design state |

### Recommendation

- Local/development: use Option D. Keep the current rows unchanged, exercise
  future enforcement with disposable test fixtures, and do not infer
  production policy from local seed data.
- Staging/production: approve Option B as the target policy and use Option C as
  the deployment sequence for Tendik and Akademik. Mahasiswa receive the
  strictest treatment after inventory. Super Admin rotation is staged
  individually under the break-glass controls below.
- Option A must not be used as a first deployment.
- Option D is not an acceptable long-term staging/production disposition.

## Enforcement design for a future approved phase

### Local-password login

After the password is successfully verified and account status checks pass:

1. If `password_must_rotate` is false, issue the normal application token.
2. If it is true, do not issue a normal application token. Issue a short-lived
   token limited to password rotation and logout, or return a dedicated
   rotation challenge that can obtain such a token.
3. Middleware must reject that limited token on every other protected route.
4. A dedicated password-change endpoint must accept a strong new password and
   confirmation, update metadata to `self_service_change`, clear
   `password_must_rotate`, revoke all old tokens and sessions, and require a new
   login.
5. Forgot-password OTP must remain available as an alternative. Successful OTP
   reset already records `reset_password_otp` and clears the flag.

The existing general profile endpoint is not an adequate forced-rotation
endpoint because it also handles non-password profile fields and currently sits
behind normal authenticated/profile-complete middleware.

### Google login

`password_must_rotate` should not block a verified Google login. Google is an
independent credential and may be the user's recovery path. The response should
expose a non-sensitive local-password rotation status so the UI can warn the
user and offer password reset/change, while the risky local-password path
remains restricted by local-login enforcement.

This decision does not classify the local password as safe and does not clear
the flag. Only a successful verified credential replacement clears it.

### Super Admin-selected rotation

A future Primary Super Admin action may mark selected users for rotation, but it
must:

- be explicit and role-filtered
- show counts and exclusions before confirmation
- never accept or display a password
- write an audit event with actor, target, policy reason, and timestamp
- revoke existing access only according to an approved campaign rule
- exclude a required Super Admin continuity anchor
- reject any action that would leave no verified Super Admin recovery path

No such action is currently implemented.

## Super Admin break-glass policy

Before changing any Super Admin credential or rotation flag in staging or
production, the authorized operator must record:

1. The count of active Primary and Secondary Super Admin accounts.
2. The identity of the continuity anchor in the private change record.
3. At least one successfully tested recovery method for that anchor:
   verified Google login, verified OTP delivery/reset, or an approved manual
   operator procedure.
4. Confirmation that email delivery, Google client configuration, and target
   account status are working in the target environment.
5. A rollback/abort condition if the newly rotated administrator cannot log in.

Rotate one administrator at a time. Verify normal login and required
administrative access before proceeding to the next account.

If a temporary administrator password is exceptionally approved, it must be
generated through an authorized secret-handling process, communicated
out-of-band, classified as `temporary_admin`, marked for immediate rotation,
and never written to logs, chat, tickets, or command output. No default,
NIM/birth-date-derived, or shared password is permitted.

## Inventory requirements

The current A4C inventory is Mahasiswa-only. It already reports:

- Mahasiswa password null/non-null counts
- local password and Google-link state
- exact current-pattern and unknown-pattern classifications
- local-password status breakdown
- password method breakdown
- missing metadata
- aggregate `legacy_unknown`, `reset_password_otp`, and
  `password_must_rotate` counts
- masked samples with no password hashes or plaintext values
- explicit no-mutation safety state

It does not currently report:

- `legacy_unknown` and `password_must_rotate` grouped across all roles and
  Akademik/Tendik subroles
- Super Admin `legacy_unknown` counts and Primary/Secondary breakdown
- recent `reset_password_otp` replacements using an approved time window
- campaign eligibility and policy reason
- break-glass exclusions
- counts selected for a proposed mass action

The opt-in A4I `--policy-breakdown` report addresses these count-only gaps while
preserving the original Mahasiswa-only default output. It reports all-role and
subrole counts, recent OTP-reset windows, campaign eligibility, Super Admin
continuity exclusions, and proposed target previews. It remains read-only,
masked, and has no mass mutation mode.

## Staging and production operator sequence

1. Obtain product/academic administration, security, and operations approval
   for role treatment, grace period, and campaign timing.
2. Deploy and verify the read-only A4I inventory enhancement.
3. Run inventory in staging with approved read-only credentials.
4. Verify Google and OTP recovery for every role category; separately verify
   the Super Admin continuity anchor.
5. Implement and test the A4J enforcement API, middleware, UI, notifications,
   audit events, and last-Super-Admin safeguards.
6. Communicate the campaign scope, deadline, recovery path, and support contact.
7. Perform an A4K local/staging dry run and review counts and exclusions.
8. Start with a small non-Super-Admin staff cohort.
9. Verify successful rotations and recovery before expanding the cohort.
10. Handle Super Admins individually under the break-glass procedure.
11. Apply the approved Mahasiswa action only after its inventory and
    communication gate passes.
12. Re-run read-only inventory and retain the masked before/after evidence.

## Approval gates and prohibited actions

Separate explicit approval is required for:

- setting `password_must_rotate = true`
- nullifying or disabling a password
- revoking access as a campaign operation
- sending user notifications
- enabling rotation enforcement
- any staging or production execution

The following are prohibited:

- mass-nullifying all Super Admin passwords
- marking every Super Admin for rotation without a verified continuity anchor
- treating non-matching identity patterns as proof of safety
- storing passwordless state as `legacy_unknown`
- exposing passwords, hashes, OTPs, reset tokens, or unmasked account lists
- using default, shared, or identity-derived passwords
- enabling flags before the enforcement and recovery paths are deployable

## Decisions still required

- Whether the local 12 `legacy_unknown` development accounts should ever be
  rotated, or remain disposable local fixtures.
- Whether Mahasiswa remediation uses password nullification or forced OTP reset
  after approved target-environment inventory.
- Grace period and enforcement dates for Tendik and Akademik.
- Whether the target organization accepts Google as a full continuity path for
  staff and administrators.
- Which Super Admin account is the continuity anchor for each environment.
- Whether the rotation status is visible in Super Admin UI and to end users.
- Whether a dedicated append-only security event store is required.
