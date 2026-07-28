# Peminjaman Ruangan: Initial Submission Contract

## Endpoint

`POST /api/mahasiswa/peminjaman-ruangan/requests`

The request remains `multipart/form-data` and is protected by the existing
`throttle:peminjaman-attachment` limiter.

## Required fields

| Field | Contract |
| --- | --- |
| `idempotency_key` | String, 8-128 characters, matching `[A-Za-z0-9._:-]+`. Generate one new key for one user submission intent and retain it for retries. |
| `room_id` | Active room ID. |
| `activity_name` | Required string, maximum 255 characters. |
| `purpose` | Required string, maximum 5,000 characters. |
| `participant_count` | Integer of at least 1 and no greater than room capacity. |
| `start_at`, `end_at` | ISO timestamp with offset, `Y-m-dTH:i:sP`. |
| `booking_mode` | Optional `single_day` (default) or `consecutive_days`. |
| `occurrence_end_date` | Required for `consecutive_days`; inclusive final occurrence date, within the centrally configured 14-day default maximum. |
| `surat_peminjaman_pdf` | Required PDF, validated MIME `application/pdf`, maximum 5 MiB. |

The server binds the key to the authenticated actor, the submission action,
all booking fields above, the canonical generated occurrence schedule, and the validated PDF content checksum, byte size,
and MIME. The client must not derive a new key for a transport retry.

## Outcomes

- First successful use: `201`, `Idempotent-Replay: false`.
- Same key and identical fields/PDF: `201`, `Idempotent-Replay: true`, with
  the exact stored response body from the first success.
- Same key with changed fields or PDF: `409`, code
  `idempotency_key_reused`.
- Validation failure: `422`; the key is not consumed.
- Infrastructure failure: safe `500`, code `infrastructure_error`, with a
  correlation ID and no raw key, checksum, storage path, or exception detail.

The successful response keeps the existing direct booking envelope under
`data` and adds `data.correlation_id`. Applicant booking projections expose
public reviewer/history-actor names only; internal IDs and cancellation role
snapshots remain staff-only.
