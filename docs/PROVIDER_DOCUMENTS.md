# The documents a provider has to upload

> There are two views of the same documents and the same rules, so they can never disagree: `GET/POST /provider/verification` (sections 1 and 2 below) and `GET/PUT /provider/document-types` (sections 3 and 4), which answers in **four words** and says outright whether each document is **required or optional**. Section 5 is the application itself, which takes the documents by the same keys.

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

---

## 3. The same screen in four words: `GET /api/v1/provider/document-types`

Every type of document an admin asks for, with its **key**, whether it is **`required` or `optional`**, and where the signed-in provider's own document of that type stands:

| `status` | Meaning | Called, in section 1, |
|---|---|---|
| `not_uploaded` | nothing on file | `missing` |
| `pending` | sent, waiting for an admin to decide | `pending` |
| `need_update` | the provider has to send a new one (see `need_update_reason`) | `rejected`, `expired` or `update_requested` |
| `accepted` | an admin approved it | `approved` |

| Field | Meaning |
|---|---|
| `key` | The document's name, used when sending it (`documents[<key>]`, or a field named after it with the application) |
| `title`, `title_ar`, `title_en`, `description` | What it is called (in the request's language, and in both), and the admin's hint if there is one |
| `requirement` | **`required`** (the provider cannot be approved without it) or **`optional`** |
| `status` | One of the four above |
| `need_update_reason` | Only when `status` is `need_update`: `rejected`, `expired` (it was approved only until a date that has passed) or `update_requested` (an admin asked for a new one) |
| `review_note` | Why it was rejected, or what the admin wants changed |
| `uploaded_at`, `expires_at` | When it was sent, and until when it is good |
| `can_update` | `false` only for an `accepted` document nobody asked to update: it stays as it is |
| `file_url` | A link to the file the provider sent, which **expires** (documents are private) |
| `accepted_formats`, `max_size_kb` | What a file must be |

The admin can switch types on and off, make one required or optional, and add new ones, and this list follows at once: **draw the screen from it, never hard-code it.** A provider who has registered but not applied yet gets every type as `not_uploaded`, which is how they learn what to send with the application (section 5).

### Example response (a provider who sent three documents, after a reviewer rejected one and accepted another: all four states, four of the eight rows)

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
      "description": null,
      "requirement": "required",
      "status": "need_update",
      "need_update_reason": "rejected",
      "review_note": "The scan is not clear. Please send it again.",
      "uploaded_at": "2026-11-18T08:56:06Z",
      "expires_at": null,
      "can_update": true,
      "file_url": "http://127.0.0.1:8122/api/v1/provider/verification/files/0478d5c2-b48e-4091-b0f2…",
      "accepted_formats": ["pdf", "jpg", "jpeg", "png", "webp"],
      "max_size_kb": 5120
    },
    {
      "key": "safety_certification",
      "title": "Safety certification",
      "title_ar": "شهادة السلامة",
      "title_en": "Safety certification",
      "description": null,
      "requirement": "required",
      "status": "accepted",
      "need_update_reason": null,
      "review_note": null,
      "uploaded_at": "2026-11-18T08:56:06Z",
      "expires_at": null,
      "can_update": false,
      "file_url": "http://127.0.0.1:8122/api/v1/provider/verification/files/bd30ff73-9ac3-4c9a-9489…",
      "accepted_formats": ["pdf", "jpg", "jpeg", "png", "webp"],
      "max_size_kb": 5120
    },
    {
      "key": "business_license",
      "title": "Business licence",
      "title_ar": "رخصة النشاط التجاري",
      "title_en": "Business licence",
      "description": null,
      "requirement": "optional",
      "status": "pending",
      "need_update_reason": null,
      "review_note": null,
      "uploaded_at": "2026-11-18T08:56:06Z",
      "expires_at": null,
      "can_update": true,
      "file_url": "http://127.0.0.1:8122/api/v1/provider/verification/files/b8283247-1265-487d-9482…",
      "accepted_formats": ["pdf", "jpg", "jpeg", "png", "webp"],
      "max_size_kb": 5120
    },
    {
      "key": "insurance_certificate",
      "title": "Insurance certificate",
      "title_ar": "شهادة التأمين",
      "title_en": "Insurance certificate",
      "description": null,
      "requirement": "required",
      "status": "not_uploaded",
      "need_update_reason": null,
      "review_note": null,
      "uploaded_at": null,
      "expires_at": null,
      "can_update": true,
      "file_url": null,
      "accepted_formats": ["pdf", "jpg", "jpeg", "png", "webp"],
      "max_size_kb": 5120
    }
  ]
}
```

## 4. Updating one or all: `PUT /api/v1/provider/document-types`

`PUT`, `PATCH` and `POST` are the same call. Send a **multipart/form-data** request with one file per document, named `documents[<key>]`: **one**, **some** or **all** of them. A document not sent is left exactly as it is. Use `POST` for a form upload: a `PUT` or `PATCH` with files is read by PHP 8.4 and later only, so `POST` is the one that works on every server.

```bash
curl -X POST https://your-host/api/v1/provider/document-types \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  -F "documents[commercial_registration]=@commercial_registration.pdf" \
  -F "documents[safety_certification]=@safety_certification.pdf" \
  -F "documents[business_license]=@business_license.pdf"
```

It is **all or nothing**, exactly as in section 2: a file that is not allowed, a key that is not a document, or a document that is already `accepted` (and nobody asked to update) stores **none** of them. A document that needs updating, or is waiting, is replaced; the old version is kept for the record, the new one is `pending`, and the reviewers are told once for each.

### Example response: `200 OK` (three documents in one request; the list trimmed to one row)

```json
{
  "status": true,
  "message": "3 documents updated and sent for review.",
  "data": {
    "updated": [
      {
        "key": "commercial_registration",
        "status": "pending",
        "updated_at": "2026-11-18T08:56:06Z"
      },
      {
        "key": "safety_certification",
        "status": "pending",
        "updated_at": "2026-11-18T08:56:06Z"
      },
      {
        "key": "business_license",
        "status": "pending",
        "updated_at": "2026-11-18T08:56:06Z"
      }
    ],
    "required_remaining": ["insurance_certificate"],
    "all_required_provided": false,
    "documents": [
      {
        "key": "commercial_registration",
        "title": "Commercial registration",
        "title_ar": "السجل التجاري",
        "title_en": "Commercial registration",
        "description": null,
        "requirement": "required",
        "status": "pending",
        "need_update_reason": null,
        "review_note": null,
        "uploaded_at": "2026-11-18T08:56:06Z",
        "expires_at": null,
        "can_update": true,
        "file_url": "http://127.0.0.1:8122/api/v1/provider/verification/files/0478d5c2-b48e-4091-b0…",
        "accepted_formats": ["pdf", "jpg", "jpeg", "png", "webp"],
        "max_size_kb": 5120
      },
      "… the other seven rows, as GET returns them …"
    ]
  }
}
```

`updated` is what was stored (each `pending` now), `required_remaining` the **required** documents still to send (`not_uploaded` or `need_update`: one that is `pending` is not), `all_required_provided` is `true` when that is empty, and `documents` is the whole list as section 3 gives it.

### Example errors

**`409`: one of them is already accepted** (the whole request is refused; send only the documents whose `can_update` is `true`)

```json
{
  "status": false,
  "code": "INVALID_STATUS_TRANSITION",
  "message": "Already accepted, and so not replaced until an admin asks for an update: safety_certification. Nothing was uploaded.",
  "errors": {}
}
```

**`422`: a file is not allowed** (nothing is stored, not even the good file sent with it)

```json
{
  "status": false,
  "code": "VALIDATION_ERROR",
  "message": "The given data was invalid.",
  "errors": {
    "documents.other": [
      "The documents.other field must be a file of type: pdf, jpg, jpeg, png, webp."
    ]
  }
}
```

**`422`: a key that is not a document** names it under `documents.<key>`; **`422`: nothing sent** says `Send at least one document, as documents[key]=<file>`. **`401`** without a token, **`403`** with a customer's token.

## 5. Applying with the documents: `POST /api/v1/provider/apply`

The documents that go with an application are **dynamic** too: whatever an admin has switched on is a key the application takes, and each type's requirement decides the rule:

- a **required** type must be sent, unless a document of that type is already on file (an earlier attempt, or a rejected application being sent again);
- an **optional** type may be sent, and is **kept if it is** (it used to be ignored);
- a type an admin has switched off is neither asked for nor read, and a brand-new type an admin adds is taken at once.

Send each document under its key **either** as a form field named after it (`commercial_registration=@file`, the way applications have always been sent) **or** inside `documents` (`documents[commercial_registration]=@file`, the way section 4 speaks). Both mean the same, and they can be mixed; if one key is sent both ways, the one inside `documents` is used. A key inside `documents` that is not an active document type is refused and named, so a typo is told and not silently dropped. Each file is held to what any upload is held to (`pdf`, `jpg`, `jpeg`, `png` or `webp`, up to the size limit), and one bad file refuses the whole application. The same rules apply to `POST /provider/apply/resubmit` and to the account-level `POST /account/provider-request`.

```bash
curl -X POST https://your-host/api/v1/provider/apply \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  -F "business_name=Blue Hole Dive Club" -F "business_type=Diving center" -F "category_ids[]=1" \
  -F "city=Dahab" -F "area=Blue Hole" -F "marina_name=Dahab Marina" -F "phone=+966551112233" -F "email=info@bluehole.sa" \
  -F "documents[commercial_registration]=@commercial_registration.pdf" \
  -F "documents[safety_certification]=@safety_certification.pdf" \
  -F "documents[insurance_certificate]=@insurance_certificate.pdf" \
  -F "documents[business_license]=@business_license.pdf"
```

### Example response: `201 Created` (the three required documents and one optional one)

```json
{
  "status": true,
  "message": "Application submitted",
  "data": {
    "application_id": 24,
    "status": "pending",
    "submitted_at": "2026-11-18T09:05:26Z"
  }
}
```

### Example errors: `422` (a required document is missing, and a key that is not a document; nothing is kept)

```json
{
  "status": false,
  "code": "VALIDATION_ERROR",
  "message": "The given data was invalid.",
  "errors": {
    "insurance_certificate": [
      "The insurance certificate field is required."
    ],
    "documents.passport_of_the_cat": [
      "This is not a document you can send with an application. GET /provider/document-types lists them."
    ]
  }
}
```

The flow for an app: **register** → `GET /provider/document-types` (which documents, required or optional) → `POST /provider/apply` with those documents → `GET /provider/document-types` again (each is `pending`) → later `PUT /provider/document-types` to replace any that comes back `need_update`.
