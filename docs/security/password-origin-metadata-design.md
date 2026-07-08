# Password origin metadata design

## Decision

Passwordless state is inferred from `users.password IS NULL`. The database does not store synthetic `none` or `google_only` methods.

For a non-null local password, the minimal metadata is:

- `password_set_method` nullable internal string
- `password_set_at` nullable timestamp
- `password_set_by_user_id` nullable self-referencing user foreign key
- `password_must_rotate` boolean, default `false`

`password_rotated_at` is not added because `password_set_at` already records the latest credential replacement. A future password-history feature would require a separate append-only table rather than another current-state timestamp.

Stable internal method values:

- `reset_password_otp`
- `super_admin_set`
- `self_service_change`
- `legacy_unknown`
- `temporary_admin` reserved for a future forced-rotation flow
- `system_migration`
- `system_seed`

Values are internal English identifiers. Indonesian labels may be added at an API/UI presentation boundary later.

## Current lifecycle mapping

| Path | Roles | Password result | Metadata after migration | Current risk |
|---|---|---|---|---|
| Super Admin creates Mahasiswa | Mahasiswa | `NULL` | all origin fields null; rotate false | Passwordless, preferred |
| CSV creates/merges Mahasiswa | Mahasiswa | `NULL` or preserves existing password | no new local-password metadata | Merge preserves historical risk if a password already exists |
| Google self-onboarding | Mahasiswa | `NULL` | all origin fields null; rotate false | Passwordless, preferred |
| Google profile completion | Mahasiswa | unchanged | unchanged | Does not create a password |
| Verified forgot-password OTP | all active eligible roles | non-null | `reset_password_otp`, current timestamp, setter null, rotate false | Auditable credential rotation |
| Super Admin creates/updates staff | Tendik/Akademik/Super Admin | non-null | `super_admin_set`, current timestamp, setter admin ID, rotate false | Current permanent-password policy retained |
| Staff self-profile password change | Tendik/Akademik/Super Admin | non-null | `self_service_change`, current timestamp, setter self ID, rotate false | Existing path; tokens/sessions revoked |
| Mahasiswa seeder | development student | `NULL` | no local-password metadata | Matches the passwordless Mahasiswa policy |
| Staff seeders | development/bootstrap staff | non-null | `system_seed`, current timestamp, rotate false | Known default credentials; must not be production defaults |
| Factory/tests | tests | usually non-null | fixture-defined or absent | Not production provenance |
| Manual SQL / historical rows | any | unknown | migration backfills non-null rows as `legacy_unknown` | Must not be treated as safe |

There is no public registration endpoint and no privileged-user self-registration.

## Role policy

### Mahasiswa

- Default is passwordless.
- Super Admin create/import and Google onboarding must not create a local password.
- Verified email OTP reset is the supported local-password creation path.
- Existing non-null passwords without trustworthy metadata are `legacy_unknown`.
- `password_must_rotate` remains false during initial backfill until a separate campaign is approved.

### Tendik, Akademik, and Super Admin

- Accounts remain pre-provisioned.
- Existing Super Admin-set password behavior is retained and classified as `super_admin_set`.
- Self-service staff password changes are classified as `self_service_change`.
- OTP reset is classified as `reset_password_otp`.
- Password changes revoke personal access tokens and database sessions.
- `password_must_rotate` is not set true until an enforcement path is approved and implemented.
- No new privileged-user self-registration is introduced.

## Migration and backfill

Prepared migration:

`2026_06_20_000000_add_password_origin_metadata_to_users_table.php`

The migration is additive and does not change login behavior. When explicitly approved and run:

- `password IS NULL`: metadata remains null and rotation remains false.
- `password IS NOT NULL` with no method: method becomes `legacy_unknown`.
- Historical set time and setter remain null because they cannot be reconstructed safely.
- Rotation remains false until policy and user communication are approved.

Rollback removes only the additive metadata columns and foreign key. It does not change or expose password hashes.

Deployment order is tolerant: application code checks column availability before writing metadata, so code may be deployed before the migration. Audit completeness begins only after the migration is installed.

## Inventory behavior

The A4C command reports:

- method breakdown
- password rows without metadata
- `legacy_unknown`
- `reset_password_otp`
- rotation-required rows

With the opt-in `--policy-breakdown` flag, the same command also reports
count-only all-role, subrole, recent-reset, campaign-eligibility, mass-action
preview, and Super Admin continuity information. The default command output
remains Mahasiswa-only for backward compatibility.

If columns are absent, it reports metadata unavailable and conservatively classifies non-null passwords without metadata as `legacy_unknown`.

## Security and privacy

- No plaintext password, hash, OTP, or reset token is stored in metadata or inventory output.
- Setter identity is a nullable user ID, not copied email/NIP/NIM.
- Metadata fields are hidden from general User JSON serialization.
- The masked inventory command remains the administrative audit surface.
- Current-pattern non-match is never presented as proof of safety.

## Audit events

Future structured security events may include:

- `password_reset_requested`
- `password_reset_verified`
- `password_changed`
- `legacy_password_inventory_run`

Events must exclude password values, hashes, OTPs, bearer tokens, and reset tokens. Event logging is not implemented in A4E because the current activity-log schema is administrator-oriented and stores target identifiers; a dedicated security-event contract should be approved first.

## Time and globalization

- Database timestamps use the application/database standard and should be persisted in UTC in deployed environments.
- Operator/UI display may format timestamps in `Asia/Jakarta`.
- Internal method identifiers remain stable English values; translations belong in presentation code.

## Deferred decisions

- Whether and when to execute the migration.
- Whether to force rotation for `legacy_unknown`.
- Whether future admin-issued staff passwords become `temporary_admin` with enforced rotation.
- Whether to expose a read-only password-status label in Super Admin UI.
- Whether to add a dedicated append-only security-event table.
