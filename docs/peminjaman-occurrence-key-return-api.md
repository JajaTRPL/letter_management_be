# Peminjaman Ruangan: Occurrence, Key, and Return Contract

## Booking schedule

Initial submission keeps one booking as the applicant journey and accepts
`booking_mode` (`single_day` or `consecutive_days`) plus
`occurrence_end_date` for a consecutive range. Every inclusive calendar date
becomes one occurrence with the same daily clock. A daily end clock at or
before the start clock ends on the following date, so an overnight booking is
still one occurrence. The centrally configured maximum is
`ROOM_BOOKING_MAXIMUM_CONSECUTIVE_DAYS` (default 14).

Existing bookings without an occurrence are represented by one compatible
occurrence derived from their stored schedule.

## Operational policy

`return_due_at` is occurrence `end_at` plus
`ROOM_BOOKING_RETURN_GRACE_MINUTES`. The default of 30 minutes is a
**provisional DTEDI department policy that must be confirmed before production
rollout**. Timeliness uses the physical `key_received_at`, not evidence upload
or staff processing time.

Operational occurrence state is derived separately from the stored booking
approval status. It does not extend the approval status enum.

## Mutations

All mutation references below are public UUID references. Every mutation
requires an 8-128 character `idempotency_key` and expected versions, returns
`Idempotent-Replay: false` on its original success and `true` for an exact
replay, and rejects reuse with different canonical data.

| Actor | Endpoint | Important fields |
| --- | --- | --- |
| Sarpras or scoped Laboran | `POST /api/tendik/peminjaman-ruangan/operations/{occurrence}/issue-key` | `expected_occurrence_version`, optional `note` |
| Mahasiswa owner | `POST /api/mahasiswa/peminjaman-ruangan/occurrences/{occurrence}/return` | multipart `expected_occurrence_version`, `evidence` (JPG/PNG/WebP, max 5 MiB) |
| Mahasiswa owner | `POST /api/mahasiswa/peminjaman-ruangan/occurrences/{occurrence}/return/withdraw` | `expected_occurrence_version`, `expected_return_version` |
| Sarpras or scoped Laboran | `POST /api/tendik/peminjaman-ruangan/operations/{occurrence}/return/{accept,revise,reject}` | both expected versions; note required for revise/reject; accept supports `key_received_at` and requires `received_time_change_reason` when staff changes it from now |

Kepala Lab receives read-only oversight for its laboratory scope. SuperAdmin is
not a normal key issuer or receiver.

Evidence is stored privately under a server-generated name and is only served
through authenticated preview/download routes. Public and applicant JSON never
contains its disk, path, checksum, internal actor ID, or private audit metadata.

## Stable event hooks

Occurrence payloads expose recipient-scoped hooks for usage start, usage end,
return due, and overdue. Immutable workflow events are recorded for occurrence
creation, key issue, return submission/resubmission/withdrawal, revision,
acceptance, rejection, and an adjusted physical key-received time. Event
metadata contains public subject references and role/recipient context, not
private evidence data.
