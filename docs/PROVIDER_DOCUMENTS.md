# The documents a provider has to upload

Two calls, for a provider who is signing up (not approved yet) and for an approved one alike. Both answer in the provider app's format `{ "status", "message", "data" }`
(or `{ "status": false, "code", "message", "errors" }` when something is refused). Sign in first with the one-time code (`POST /api/v1/auth/provider/otp/request`, then `/verify`) and send
the token as `Authorization: Bearer <token>`.

## 1. Which documents, and which are required: `GET /api/v1/provider/verification`

One row for **every** document an admin asks for, **required and optional alike**. The admin can change which documents there are, whether each is mandatory, and its titles, so the
app should draw this list rather than hard-code it.

| Field | Meaning |
|---|---|
| `key` | What the document is called in an upload (`documents[<key>]`) |
| `title`, `title_ar`, `title_en` | The name: in the request's language, and in both languages |
| `is_mandatory` | **`true`: required** (the provider cannot be approved without it). **`false`: optional** (it may be left out) |
| `status` | `missing` (nothing sent), `pending` (sent, waiting for an admin), `approved`, `rejected`, `expired`, `update_requested` (an admin asked for a new one) |
| `is_done` | `true` only when it is `approved` |
| `can_upload` | `false` only for an `approved` document that nobody asked to update: it stays as it is |
| `review_note` | Why it was rejected, or what the admin wants changed |
| `file_url` | A link to the file the provider sent, which **expires** (documents are private) |
| `uploaded_at`, `expires_at`, `update_requested_at` | When, and until when |
| `description` | A hint for the provider, if the admin wrote one |
| `accepted_formats`, `max_size_kb` | What a file must be: `["pdf","jpg","jpeg","png","webp"]` and `5120` (5 MB): check it before sending |

On a fresh installation there are eight documents: **required** `commercial_registration`, `safety_certification`, `insurance_certificate`; **optional** `business_license`,
`identity_document`, `professional_certification`, `insurance`, `other`.

### Example response (the first two of the eight rows, for a provider who has sent nothing yet)

```json
{
  "status": true,
  "message": "Success",
  "data": [
    {
      "key": "commercial_registration",
      "title": "Commercial registration",
      "title_ar": "السجل التجاري",
      "title_en": "Commercial registration",
      "status": "missing",
      "is_done": false,
      "file_url": null,
      "review_note": null,
      "description": null,
      "is_mandatory": true,
      "uploaded_at": null,
      "expires_at": null,
      "update_requested_at": null,
      "can_upload": true,
      "accepted_formats": ["pdf", "jpg", "jpeg", "png", "webp"],
      "max_size_kb": 5120
    },
    {
      "key": "safety_certification",
      "title": "Safety certification",
      "title_ar": "شهادة السلامة",
      "title_en": "Safety certification",
      "status": "missing",
      "is_done": false,
      "file_url": null,
      "review_note": null,
      "description": null,
      "is_mandatory": true,
      "uploaded_at": null,
      "expires_at": null,
      "update_requested_at": null,
      "can_upload": true,
      "accepted_formats": ["pdf", "jpg", "jpeg", "png", "webp"],
      "max_size_kb": 5120
    }
  ]
}
```

## 2. Sending them all at once: `POST /api/v1/provider/verification`

A **multipart/form-data** request with one file per document, named `documents[<key>]`. Send any of them, from one to all: a document that is not sent is left exactly as it is, so the
required ones and the optional ones can go together or in several goes.

```bash
curl -X POST https://your-host/api/v1/provider/verification \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  -F "documents[commercial_registration]=@commercial_registration.pdf" \
  -F "documents[safety_certification]=@safety_certification.pdf" \
  -F "documents[insurance_certificate]=@insurance_certificate.pdf" \
  -F "documents[business_license]=@business_license.pdf"
```

Each file is held to what a single upload (`POST /provider/verification/{key}`, which still works) is held to: `pdf`, `jpg`, `jpeg`, `png` or `webp`, up to 5 MB (an SVG, HTML or
program file is refused by its real content). A document that is already on file is **replaced**: the old one is kept for the record and marked superseded, and the new one is `pending`
until an admin decides. The reviewers are told once for each document.

**It is all or nothing.** If any file is not allowed, if a key is not one of the documents, or if one of the documents is already `approved` (and nobody asked for an update), then
**nothing is stored**, not even the files that were fine: the provider is never left with part of what they sent and no idea which. If storing one fails half-way, what was stored is
undone.

### Example response: `201 Created` (four documents in one request)

```json
{
  "status": true,
  "message": "4 documents uploaded and sent for review.",
  "data": {
    "uploaded": [
      { "key": "commercial_registration", "status": "pending", "uploaded_at": "2026-11-17T21:15:38Z" },
      { "key": "safety_certification",    "status": "pending", "uploaded_at": "2026-11-17T21:15:38Z" },
      { "key": "insurance_certificate",   "status": "pending", "uploaded_at": "2026-11-17T21:15:38Z" },
      { "key": "business_license",        "status": "pending", "uploaded_at": "2026-11-17T21:15:38Z" }
    ],
    "required_remaining": [],
    "all_required_uploaded": true,
    "documents": [
      {
        "key": "commercial_registration",
        "title": "Commercial registration",
        "title_ar": "السجل التجاري",
        "title_en": "Commercial registration",
        "status": "pending",
        "is_done": false,
        "file_url": "https://your-host/api/v1/provider/verification/files/a63401ac-e2ae-458f-9def-d05c6d4a44d7?expires=1794951038&signature=21748dc1…",
        "review_note": null,
        "description": null,
        "is_mandatory": true,
        "uploaded_at": "2026-11-17T21:15:38Z",
        "expires_at": null,
        "update_requested_at": null,
        "can_upload": true,
        "accepted_formats": ["pdf", "jpg", "jpeg", "png", "webp"],
        "max_size_kb": 5120
      },
      "… the other seven rows, in the same shape as GET /provider/verification …"
    ]
  }
}
```

| Field | Meaning |
|---|---|
| `uploaded` | What was stored, in the order sent. Each is `pending` now |
| `required_remaining` | The **required** documents that still have to be sent: nothing on file, rejected, expired, or an admin asked for an update. `[]` when none |
| `all_required_uploaded` | `true` when `required_remaining` is empty |
| `documents` | The whole list as `GET /provider/verification` gives it, so the app can redraw its screen from this one answer |

With only some of them, `required_remaining` lists what is left:

```json
{ "status": true, "message": "Document uploaded and sent for review.",
  "data": { "uploaded": [ { "key": "insurance_certificate", "status": "pending", "uploaded_at": "2026-11-17T21:20:01Z" } ],
            "required_remaining": ["commercial_registration", "safety_certification"], "all_required_uploaded": false, "documents": [ "…" ] } }
```

### Example errors

**`422`: a file is not allowed, so none of them is stored**

```json
{
  "status": false,
  "code": "VALIDATION_ERROR",
  "message": "The given data was invalid.",
  "errors": {
    "documents.professional_certification": [
      "The documents.professional_certification field must be a file of type: pdf, jpg, jpeg, png, webp."
    ]
  }
}
```

**`422`: a key that is not a document**

```json
{
  "status": false,
  "code": "VALIDATION_ERROR",
  "message": "The given data was invalid.",
  "errors": {
    "documents.passport_of_the_cat": [
      "This is not a document you can upload. GET /provider/verification lists them."
    ]
  }
}
```

**`422`: nothing sent**: `errors.documents`: "Send at least one document, as documents[key]=<file>. GET /provider/verification lists the keys."

**`409`: one of them is already approved** (the message says which, and that nothing was uploaded; `code` is `INVALID_STATUS_TRANSITION`). Send only the documents whose `can_upload` is `true`.

**`401`** without a token, **`403`** with a customer's token.
