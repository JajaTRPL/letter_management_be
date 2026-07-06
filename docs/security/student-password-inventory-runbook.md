# Student legacy password inventory runbook

## Purpose

This runbook inventories historical Mahasiswa local-password risk without changing database state. It checks only the known legacy pattern:

`current NIM stripped of separators + current tanggal_lahir formatted DDMMYYYY`

A non-match is not proof of safety. If NIM or birth date changed after the password was created, a legacy password may be undetectable.

## Safety requirements

- Run in the target application environment, not from a developer workstation.
- Use an approved dedicated read-only database account or read replica.
- Run staging before production.
- Obtain operator/security approval before using `--authorized-read-only`.
- Do not copy raw database rows, password hashes, reset tokens, OTPs, full emails, or full NIMs into tickets.
- The command has no cleanup mode. PostgreSQL/MySQL targets use a database-enforced read-only transaction; SQLite verification uses a transaction rollback guard.
- Masking is always enabled. There is no unmasked option.

The current repository has only one generic database connection configuration. It does not contain a separate staging, production, replica, or read-only connection. Operators must provide target-environment read-only credentials through approved deployment/secret management.

## Local verification

```powershell
php artisan users:student-password-inventory --dry-run --environment-label=local --include-status-breakdown
```

Add `--policy-breakdown` when the review needs count-only all-role campaign and
continuity information:

```powershell
php artisan users:student-password-inventory `
  --dry-run `
  --environment-label=local `
  --policy-breakdown
```

The command name remains backward compatible. Without `--policy-breakdown`,
the report retains its Mahasiswa-only scope and output structure.

## Staging inventory

Run inside the staging application environment with `APP_ENV=staging` and read-only database credentials:

```powershell
php artisan users:student-password-inventory `
  --dry-run `
  --environment-label=staging `
  --authorized-read-only `
  --include-status-breakdown `
  --policy-breakdown `
  --show-samples=5 `
  --include-google-linked `
  --export=reports/student-password-inventory/staging-inventory.json
```

Review the masked report and confirm:

- `database_read_only_enforced` and `transaction_rollback_guard` reflect the active driver safeguards
- `database_mutations_performed` is `false`
- no raw PII or hashes appear
- counts reconcile with the expected Mahasiswa population
- `unknown_non_current_pattern` is treated as unknown, not safe

## Production inventory

Run only after staging review, inside the production application environment with `APP_ENV=production` and approved read-only credentials:

```powershell
php artisan users:student-password-inventory `
  --dry-run `
  --environment-label=production `
  --authorized-read-only `
  --json `
  --show-samples=0 `
  --include-status-breakdown `
  --policy-breakdown `
  --export=reports/student-password-inventory/production-inventory.json
```

Keep the exported JSON in private storage and transfer it only through approved secure channels.

## Inventory categories

- Total Mahasiswa
- Password null / not null
- Google-linked accounts with a local password
- Passwordless accounts without Google identity
- Local-password accounts with or without current NIM and birth date
- Exact current-pattern matches
- Unknown/non-current-pattern passwords
- Suspended/inactive and pending/incomplete accounts with local passwords
- Local-password status breakdown
- Password-set method breakdown
- Local passwords with missing metadata
- `legacy_unknown` and `reset_password_otp` counts
- Password rotation-required count

With `--policy-breakdown`, the report also contains count-only sections for:

- all-user password and metadata state
- password methods and rotation flags by top-level role
- Tendik specialization: Persuratan, Sarpras, Kepala Lab, Laboran, and unknown
- Akademik subrole: Kaprodi, Sekprodi, Kadep, Sekdep, and unknown
- Super Admin type: Primary, Secondary, and unknown
- `reset_password_otp` total, last 7 days, and last 30 days using
  `password_set_at`
- warning-campaign and future local-login rotation eligibility counts
- Super Admin continuity exclusions and break-glass warning
- count-only previews for `mahasiswa-legacy-unknown`,
  `staff-legacy-unknown`, and `all-legacy-unknown`

Policy breakdown does not select or output password values, names, emails,
NIMs, OTPs, or reset tokens. Super Admin rows are excluded from the selectable
count of the all-role preview because they require individual continuity-anchor
verification. The preview has no execute mode.

The A4E migration prepares `password_set_at`, `password_set_method`, `password_set_by_user_id`, and `password_must_rotate`. Until that migration is applied in a target environment, the command degrades safely and treats non-null passwords without metadata as `legacy_unknown`. `password_reset_tokens` remains transient and is not used as password-origin proof.

## Remediation decision

No remediation is performed by the inventory command.

1. Strict cleanup: nullify all Mahasiswa local passwords not proven to originate from the hardened reset flow. Safest, but potentially disruptive.
2. Targeted cleanup: nullify only exact current-pattern matches. Less disruptive, but leaves undetectable legacy risk.
3. Metadata-first: add password-origin metadata, then expire unknown older credentials through a communicated reset campaign. Best long-term auditability; requires migration approval.
4. Detection-only: retain the current login block for exact current-pattern passwords. Least disruptive and highest residual risk.

Recommended decision:

- Few local-password accounts: prepare an approved strict cleanup and communication plan.
- Many legitimate local-password accounts: use metadata-first design and a forced reset campaign.
- Do not classify non-matches as safe.

## Future cleanup command design

Proposed command:

```powershell
php artisan users:cleanup-student-legacy-passwords --dry-run
```

Future options:

- `--only-current-pattern`
- `--all-student-local-passwords`
- `--older-than=<approved A2 deployment timestamp>`
- `--exclude-recent-reset-password`
- `--confirm=<change-ticket-specific confirmation>`

Any future execute mode must:

- require explicit confirmation and an approved change ticket
- affect only `role=mahasiswa`
- set only selected passwords to null
- revoke affected personal access tokens and server-side sessions
- invalidate active password-reset state
- write a masked audit record
- preserve users, roles, NIM, Program Studi, status, and Google identity
- support a reviewed dry-run manifest before execution
