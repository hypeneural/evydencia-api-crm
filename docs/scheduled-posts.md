# Scheduled Posts API – Front-End Integration Handbook

Use this document as a ready-to-drop “prompt” inside VibeCoding/Cursor or to brief engineers integrating the Status Scheduler UI (`https://gestao.fotosdenatal.com/`) with the backend (`https://api.evydencia.com.br/`).

---

## 1. High-Level Architecture

- **Base URL:** `https://api.evydencia.com.br`
- **Auth:** `X-API-Key: <key>` header on every request (optionally `Trace-Id`).
- **Module Goal:** Manage WhatsApp Status posts (text/image/video) that can be published *now* or at a future time. A worker endpoint handles batch dispatch through Z-API.
- **Time Zone:** All scheduling operates in `America/Sao_Paulo` (UTC-3 or UTC-2 depending on DST). Input/output format: `YYYY-MM-DD HH:MM:SS`.
- **Media hosting:** Uploaded media is stored under `/status-media/{image|video}/<filename>`; the API returns full URLs that can be used directly in the scheduling payload.
- **Video Limit:** 10 MB (enforced server side); images default to 5 MB (configurable via `.env`).

---

## 2. End-to-End Flow at a Glance

1. **User selects media (optional)**  
   ➔ Front uploads via `POST /v1/scheduled-posts/media/upload` → get `url`.

2. **User configures type/message/datetime/caption**  
   ➔ Front sends `POST /v1/scheduled-posts` with the `url` from step 1 if media.

3. **Server stores record**  
   - If `scheduled_datetime <= now`, backend tries immediate send and may populate `messageId/zaapId`.
   - Otherwise, record waits in queue until scheduled, visible via list endpoints.

4. **Worker**  
   - Cron or manual trigger hits `POST /worker/dispatch` to send pending posts.
   - Each dispatch attempt logs status and updates the record fields.

---

## 3. Data Model & Validation Rules

| Field                | Type / Example                        | Rules / Notes                                                                 |
|----------------------|----------------------------------------|-------------------------------------------------------------------------------|
| `id`                 | `number`                               | Auto-increment primary key                                                   |
| `type`               | `"text"|"image"|"video"`               | Required, drives other required fields                                       |
| `message`            | `string`                               | Required when `type="text"`                                                  |
| `image_url`          | `string / null`                        | Required when `type="image"` (must be valid URL)                             |
| `video_url`          | `string / null`                        | Required when `type="video"` (must be valid URL)                             |
| `caption`            | `string / null`                        | Optional (use to annotate images/videos)                                     |
| `scheduled_datetime` | `"YYYY-MM-DD HH:MM:SS"` (São Paulo TZ) | Required on create; immediate dispatch if <= now                             |
| `zaapId`             | `string / null`                        | Set after successful Z-API send                                              |
| `messageId`          | `string / null`                        | Set after successful Z-API send; required in `mark-sent` endpoint            |
| `created_at`         | `string (ISO)`                         | Assigned by backend                                                          |
| `updated_at`         | `string (ISO)`                         | Updated automatically                                                        |

**Backend enforcement summary**
- `message`, `image_url`, or `video_url` must be present depending on `type`.
- `scheduled_datetime` must parse as valid date/time.
- `messageId` mandatory for `POST /{id}/mark-sent`.
- Uploads: Z-API limit 10 MB for video; default 5 MB for images (config via `.env`).  
  Allowed MIME: `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `video/mp4`, `video/mpeg`, `video/quicktime`, `video/x-msvideo`.

---

## 4. Media Upload Endpoint (must run before scheduling images/videos)

### 4.1 Request
```
POST https://api.evydencia.com.br/v1/scheduled-posts/media/upload
Headers:
  X-API-Key: <key>
  Trace-Id: <optional>
Body (multipart/form-data):
  type=image|video
  media=<file>
```

### 4.2 Success Response (`201`)
```json
{
  "success": true,
  "data": {
    "type": "image",
    "url": "https://api.evydencia.com.br/status-media/image/4a1...cd.png",
    "relative_path": "image/4a1...cd.png",
    "mime_type": "image/png",
    "size": 1345
  },
  "meta": { "source": "api" },
  "links": { "self": "https://api.evydencia.com.br/v1/scheduled-posts/media/upload", "next": null, "prev": null },
  "trace_id": "b1d0fce8d5..."
}
```

### 4.3 Failure Response (`422`)
```json
{
  "success": false,
  "error": {
    "code": "unprocessable_entity",
    "message": "Parametros invalidos",
    "errors": [
      { "field": "media", "message": "Arquivo excede o tamanho máximo permitido (10.00 MB)." }
    ]
  },
  "trace_id": "..."
}
```

> **Front rule:** Always upload first, stash the returned `url`, then include it in the main scheduling payload.

---

## 5. REST Endpoints (CRUD + helpers)

All endpoints share base path `/v1/scheduled-posts` and the success/error envelope:
```json
// success
{ "success": true, "data": ..., "meta": ..., "links": ..., "trace_id": "..." }

// error
{ "success": false, "error": { "code": "...", "message": "...", "errors": [...] }, "trace_id": "..." }
```

### 5.1 List – `GET /v1/scheduled-posts`

**Use case**  
Populate the main dashboard grid with pagination + filters.

**Request**
```
GET /v1/scheduled-posts?page=1&per_page=15&sort[field]=scheduled_datetime&sort[direction]=desc
Headers:
  X-API-Key: <key>
  Trace-Id: <optional>
```

**Filters**
- `filters[type]=image`
- `filters[scheduled_datetime_gte]=2025-10-01 00:00:00`
- `filters[scheduled_datetime_lte]=2025-10-31 23:59:59`
- `filters[message_id_state]=null` (`null` vs `not_null`)
- `search=<text>` (searches `message` or `caption`)
type": "image",
      "message": null,
      "image_url": "https://api.evydencia.com.br/status-media/image/4a1.png",
      "video_url": null,
      "caption": "Oferta Black Friday",
      "scheduled_datetime": "2025-10-08 17:57:00",
      "zaapId": null,
      "messageId": null,
      "created_at": "2025-10-07T18:15:20.000000Z",
      "updated_at": "2025-10-07T18:15:20.000000Z",
      "has_media": true
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 15,
    "count": 1,
    "total": 8,
    "total_pages": 1,
    "source": "api"
  },
  "total": 8,
  "max_updated_at": "2025-10-07 18:15:20",
  "links": { "self": "...", "next": null, "prev": null },
  "trace_id": "..."
}
```

### 5.2 Create – `POST /v1/scheduled-posts`

**Use case**  
Create new schedules (text/image/video). If the datetime ≤ now, backend attempts immediate send.

**Request**
```
POST /v1/scheduled-posts
Headers:
  X-API-Key: <key>
  Content-Type: application/json
Body: { ... }
```

**Body example (image)**
```json
{
  "type": "image",
  "message": null,
  "image_url": "https://api.evydencia.com.br/status-media/image/launch.png",
  "video_url": null,
  "caption": "Lançamento coleção 2025",
  "scheduled_datetime": "2025-10-20 09:30:00"
}
```

**Immediate post (text)**
```json
{
  "type": "text",
  "message": "Já começou a Black Friday!",
  "image_url": null,
  "video_url": null,
  "caption": null,
  "scheduled_datetime": "2025-10-08 09:45:00" // assume now <= value
}
```
Backend tries to dispatch automatically; if successful you may already see `messageId` in response.

**Responses**
- `200` success envelope.
- `422` validation errors (missing required field, bad datetime, etc.).
- `502` when Z-API fails during immediate send.

### 5.3 Retrieve – `GET /v1/scheduled-posts/{id}`

**Use case**  
Load details for edit screen or detail modal.

**Request**
```
GET /v1/scheduled-posts/123
```

**Status**
- `200` with resource.
- `404` if not found.

### 5.4 Update – `PUT /v1/scheduled-posts/{id}` or `PATCH`

**Use case**  
Modify existing schedule (reschedule time, change caption, swap media link).

Send only changed fields.

### 5.5 Delete – `DELETE /v1/scheduled-posts/{id}`

Response:
```json
{ "success": true, "data": [], "meta": { "source": "api" }, "links": {...}, "trace_id": "..." }
```

### 5.6 Mark as Sent – `POST /v1/scheduled-posts/{id}/mark-sent`

**Body**
```json
{
  "messageId": "3EB0...==",
  "zaapId": "ZXCV-123",
  "caption": null // ignored if sent
}
```

Use when Z-API confirms send via webhook and you need to update the record manually.

### 5.7 Ready Queue – `GET /v1/scheduled-posts/ready`

**Use case**  
Show “pending” badge or feed for upcoming posts.

Returns up to `limit` (default 50) ready-to-send entries.

### 5.8 Worker Dispatch – `POST /v1/scheduled-posts/worker/dispatch`

**Body (optional)**
```json
{ "limit": 25 }
```
**Key response fields**
- `summary.limit` – limit used
- `summary.processed` – total items scanned
- `summary.sent | failed | skipped`
- `items[]` – per item breakdown (status `sent | failed | skipped`, plus `messageId`, `zaapId`, `provider_status`, `error`).

Run this via cron (`*/5 * * * * curl -X POST ...`) or a backend job queue.

### 5.9 Re-upload – `POST /v1/scheduled-posts/media/upload`

Described in Section 4; always required when a new media file is picked.

---

## 6. HTTP Status & Error Reference

| Status | Scenario                                           | Body shape                                                                      |
|--------|----------------------------------------------------|----------------------------------------------------------------------------------|
| 200    | Successful GET/PUT/PATCH/DELETE/worker POST        | `{"success":true,"data":...,"meta":...,"trace_id":...}`                         |
| 201    | Successful media upload (file stored)              | Same success structure with HTTP 201                                            |
| 400    | Malformed request (rare, mostly OpenAPI validation)| `{"success":false,"error":{"code":"error","message":"..."}}`                     |
| 401    | Missing/invalid API key                            | `{"success":false,"error":{"code":"unauthorized","message":"Invalid API key."}}` |
| 404    | Resource not found                                 | `{"success":false,"error":{"code":"not_found","message":"..."}}`                 |
| 422    | Validation failure                                 | `{"success":false,"error":{"code":"unprocessable_entity","errors":[...]}}`      |
| 500    | Internal failure                                   | `{"success":false,"error":{"code":"internal_error","message":"..."}}`            |
| 502    | Downstream Z-API/CRM failure bubbled up            | `{"success":false,"error":{"code":"bad_gateway","message":"CRM error (status X)."}}` |

- `trace_id` header + JSON field -> correlate with server logs.
- For 422, iterate `error.errors[]` to render field-level messages.
- Worker responses embed `provider_status` when Z-API responds with non-2xx.

---

## 7. Front-End Implementation Blueprint

### 7.1 Upload UI
- Drag & drop or file picker triggers `media/upload`.
- Show spinner; disable “Schedule” button until `url` is received.
- Check file size before upload (optional) to avoid wasting bandwidth.
- Reset upload state when user removes the file.

### 7.2 Schedule Form
- Fields: type (radio), message, caption, scheduled_datetime (date+time pickers).
- Auto-populate `scheduled_datetime` with nearest quarter-hour (or `now` for immediate).
- Convert user input to `"YYYY-MM-DD HH:MM:SS"` in São Paulo timezone before sending.
- On submit, include relevant media URLs; send `POST /v1/scheduled-posts`.
- Show success toast; optionally navigate back to list.

### 7.3 Listing and Monitoring
- Use `GET /v1/scheduled-posts` to populate the grid.
- Add filters for type/date range/status (pending vs sent).
- Provide action buttons: edit, delete, “Mark sent” (for manual override).

### 7.4 Manual Dispatch Trigger
- If staff wants to push immediate dispatch, call `POST /worker/dispatch`.
- Surface the summary + per-item details for transparency.

### 7.5 Error UX
- Always inspect `response.success`. If `false`, display `error.message`.
- For 422 responses, list each `error.errors[i].message` near the relevant field.
- Upload-specific errors should highlight the file input.
- Anticipate HTTP 413 (Payload Too Large) from proxies; show friendly message.

### 7.6 Auth & CORS
- Store API key securely (env var in front-end build system).
- Apply `X-API-Key` header on every request (including uploads).
- `Trace-Id` optional; can reuse per session to ease debugging.

---

## 8. Handy cURL Snippets

```bash
# Upload image
curl -X POST \
  -H "X-API-Key: <key>" \
  -F "type=image" \
  -F "media=@/path/to/image.png" \
  https://api.evydencia.com.br/v1/scheduled-posts/media/upload

# Create post
curl -X POST \
  -H "X-API-Key: <key>" \
  -H "Content-Type: application/json" \
  -d '{
        "type":"image",
        "image_url":"https://api.evydencia.com.br/status-media/image/launch.png",
        "caption":"Lançamento hoje!",
        "scheduled_datetime":"2025-10-20 09:30:00"
      }' \
  https://api.evydencia.com.br/v1/scheduled-posts

# Dispatch worker (limit 10)
curl -X POST \
  -H "X-API-Key: <key>" \
  -H "Content-Type: application/json" \
  -d '{"limit":10}' \
  https://api.evydencia.com.br/v1/scheduled-posts/worker/dispatch
```

---

**Response (200)**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "
## 9. Environment Variables (for reference)

`config/settings.php` pulls from `.env`:
```
SCHEDULED_POSTS_STORAGE_PATH=/api.evydencia.com.br/public/status-media
SCHEDULED_POSTS_BASE_URL=https://api.evydencia.com.br/status-media
SCHEDULED_POSTS_IMAGE_MAX_SIZE_MB=5    # optional override
SCHEDULED_POSTS_VIDEO_MAX_SIZE_MB=10   # optional override
SCHEDULED_POSTS_IMAGE_MIME_TYPES=image/jpeg,image/png,image/gif,image/webp
SCHEDULED_POSTS_VIDEO_MIME_TYPES=video/mp4,video/mpeg,video/quicktime,video/x-msvideo
```

Ensure the directory exists and is writable by the web server.

---

## 10. Quick Reference Checklist

- [ ] Upload media first → stash `url`.
- [ ] Fill scheduling payload with correct fields per `type`.
- [ ] Convert datetime to São Paulo format.
- [ ] Handle success/error envelopes uniformly.
- [ ] Show `trace_id` in dev tools to speed debugging.
- [ ] Provide manual “Dispatch now” (worker) button for admins.
- [ ] Clean up or re-upload media when editing posts (if file changed).
- [ ] Respect 10 MB video limit; warn user if file too large.

---

This handbook reflects the API state as of **October 2025**. Keep it accessible alongside your design docs so engineers and AI copilots can ship integrations quickly and consistently. Updated endpoints or validation rules should be mirrored here to avoid drift. Happy shipping! 🚀

Keep this sheet handy while implementing the UI or automation. It captures the latest backend behavior (October 2025) and should remain valid unless new fields/endpoints are introduced. For questions or adjustments, check the backend repository modules referenced in this document. Cheers! 🚀
