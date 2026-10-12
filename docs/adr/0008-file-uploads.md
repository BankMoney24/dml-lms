# ADR 0008: File uploads

Date: 2026-10-12 · Status: accepted

## Context

Teachers need to give learners PDFs, handouts, example configuration files and scripts; learners need to hand in screenshots, documents and archives (PRD CRS-001, ASM-003). Uploads are the most common way a web application gets compromised, the server is shared with other sites, and many learners pay for every megabyte.

## Decision

* **Storage outside the web root**, under `STORAGE_PATH/uploads/<tenant>/<yyyy>/<mm>/<random>`. The stored name is 128 random bits with no extension; the original name lives only in the database. Folders are 0750, files 0640, owned by the PHP pool user. The web server cannot serve them directly.
* **Every download goes through `/files/{id}`**, which checks the tenant and the relationship: lesson files for course managers and enrolled learners of a published course; submission files for the learner who sent them and course managers. Anyone else gets 404.
* **Type by content, not by name.** An allow-list of extensions, each with the types libmagic (`finfo`) must detect; text kinds must also be valid UTF-8 without NUL bytes. SVG, HTML-as-HTML, executables and anything unknown are refused.
* **Nothing uploaded is ever executed or rendered as the site.** PDFs and photos open in the browser; text and code (including `.html`, `.php`, `.js`) are served as `text/plain; charset=utf-8` with `X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox; default-src 'none'`; everything else is an attachment. Filenames go out in both an ASCII fallback and RFC 5987 form.
* **Limits:** `UPLOAD_MAX_MB` per file (default 10), 3 files per submission, 20 per lesson, `UPLOAD_TENANT_QUOTA_MB` per school (default 5 GB). PHP and Apache limits sit just above these; a body over PHP's limit gets a plain 413 page instead of a confusing CSRF error. File inputs warn in the browser before sending a file that is too big.
* **Optional virus scanning with ClamAV** over clamd's INSTREAM protocol on TCP (the pool cannot run programs: `proc_open` is disabled). When configured it **fails closed**.
* **Two-step storage.** Validation and scanning happen before any transaction; moving and recording happen inside it, and moved files are removed again if the transaction fails. Deleting a lesson or a lesson file deletes the bytes.
* Course packages may list `attachments`; they go through the same checks. `course:sync-files` adds them to courses imported earlier.

## Consequences

* Uploads must be backed up separately from the database.
* Without ClamAV, files are stored as `unscanned`; teachers should still be careful opening learner files.
* The PHP pool's `request_terminate_timeout` was raised to 300 s so slow connections can finish an upload.
