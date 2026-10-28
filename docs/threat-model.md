# Threat model

Version 1, 27 Oct 2026 (update 32). Review it when a feature handles money, children's data, files from users or a new outside service, and at least once a year.

The method is simple: what we protect, who might attack it and how (grouped by STRIDE), what already stops them, and what risk is left. Controls name the code or ADR that implements them, so each claim can be checked.

## What we protect

| Asset | Why it matters |
|---|---|
| Children's and learners' records: names, emails, progress, scores, attendance, family links | Personal data of minors (Nigeria Data Protection Act 2023). Leaks harm families and the school's licence to operate |
| Accounts, especially owners and admins | An admin can add people, change roles, record payments and refund money |
| Money: Paystack payments, refunds, discount codes | Direct financial loss; fraud against parents |
| Secrets: `APP_KEY`, database password, Paystack secret keys, Jitsi JWT secret, backup key | Each unlocks other assets |
| Certificates | Their value is that anyone can trust a certificate number |
| Availability in term time | Classes, quizzes and payments happen on fixed days |
| The other sites on the same server (Tridax, PrimeERP, Steezi, SellTrove, VentraxERP, mail) | A compromise or outage here must not reach them |

## Trust boundaries

1. **Browser to web server** (HTTPS, Apache): everything a browser sends is untrusted, including form values, uploaded files, the `Host` header and Paystack's return URL.
2. **Web server to database**: one database user per installation, reached on the local machine only.
3. **School to school** (multi-tenant): every school's data sits in the same tables, separated by `tenant_id`.
4. **Platform to outside services**: Paystack (payments and webhooks), the Jitsi classroom (same server, Docker), the email provider, ClamAV when configured.
5. **Learner code**: code written in the Code studio and labs runs in the learner's own browser, not on the server.
6. **Server administrators and the deploy pipeline**: `dmldeploy` can only unpack and switch releases; root is used by people.

## Threats and controls

### Spoofing (pretending to be someone)

| Threat | Controls | Left over |
|---|---|---|
| Password guessing and stuffing | Per-account and per-address throttling (`login_throttles`), generic errors, constant-time path for unknown emails (IAM-007), 12-character minimum | No breach-list check of new passwords |
| Stolen admin password | Two-step sign-in required for owners and admins (`TWO_FACTOR_REQUIRED_ROLES`), TOTP with replay protection, hashed recovery codes (ADR 0006) | The setting must stay on in production `.env` |
| Session theft | Server-side sessions, `HttpOnly`, `Secure`, `SameSite`, ID regenerated at sign-in, `session_version` ends all sessions on password or role change (ADR 0002) | |
| Forged Paystack confirmation | Access only after the server asks Paystack (`verify`) or a webhook passes HMAC-SHA512 with the school's key; amount, currency and reference checked under a row lock (ADR 0012) | |
| Someone joining a class or meeting room they were not invited to | Signed, short-lived JWT per person and room, rooms with unguessable names, Jitsi with `JWT_ALLOW_EMPTY=0` and `ENABLE_AUTO_OWNER=0` (ADR 0029, 0030) | Not yet tested against the live Jitsi server by a full rehearsal |
| A stranger claiming to be a parent | Parent links are made only by staff after checking identity, with the checker recorded (ADR 0016) | Depends on staff following the check |

### Tampering (changing what they should not)

| Threat | Controls | Left over |
|---|---|---|
| Cross-site request forgery | CSRF token on every POST, `SameSite` cookies | |
| One school reading or changing another's data | `tenant_id` from the verified host, never from input; every repository query filters by it; foreign keys include `tenant_id`; isolation tests (ADR 0001) | A new query that forgets the filter. Tests cover each module, not every query |
| Learners changing scores or completion | Quizzes marked on the server; answers never sent to the page; completion rule evaluated on the server (ADR 0032) | |
| Changing prices, refunds or codes | Only `school.manage`; refunds lock the payment row and cannot exceed what was paid; every action audited (ADR 0032) | An admin acting in bad faith (audit log is the control) |
| Malicious uploads | Type checked by content, size limits, stored outside the web root with random names, served with a sandbox CSP and `nosniff`; ClamAV scan when `CLAMD_HOST` is set (ADR 0008) | Without ClamAV configured, files are not virus-scanned (the System page says which) |
| Script injection (XSS) | Output escaped by default (`$v->e`), Markdown renderer without raw HTML, strict CSP with no inline script or style | |
| Learner code attacking the site | Runs in a sandboxed iframe (`allow-scripts` only) inside a Web Worker on a separate empty page | |
| A leaked or misused API key reading learner data | Keys made only by `school.manage`, shown once, stored as SHA-256 hashes; read-only; bound to one school and checked against the host; 120 requests/minute; revocable at once; making and revoking audited; last use shown (ADR 0036) | A key copied into an insecure system exposes learner names, emails, progress and payments until revoked |

### Repudiation (denying what they did)

Audit events for sign-in, role and membership changes, payments, refunds, discount codes, parent links, course publishing and deletion, with who, when and from which address; field values (such as passwords or answers) are never stored in the audit. Payment events keep every Paystack message once. Left over: the audit log is in the same database an administrator of the server could edit.

### Information disclosure

| Threat | Controls | Left over |
|---|---|---|
| Secrets leaking from the database | Paystack keys, Jitsi secret and TOTP secrets sealed with AES-256-GCM using a key derived from `APP_KEY` (`SecretBox`); never shown again or logged | Anyone with both the database and `.env` can open them |
| Secrets in chat, shell history or the repository | `classroom:connect` refuses secrets on the command line; secret scanning in CI; secrets live in `.env` and root-only files | The backup key and an earlier Jitsi secret were pasted in chat: the Jitsi secret was rotated; **the backup key must be rotated** |
| Parents seeing more than allowed | Per-link permissions (progress, results, notices) enforced in the queries; report sheets leave out what a link does not allow (ADR 0016, 0032) | |
| Learners seeing classmates | Learner pages show only their own records; class pages for learners do not list other learners | |
| Account enumeration | Same answer for known and unknown emails on sign-in and password reset | Join form says when an email already has an account here (needed to help people sign in) |
| Backups taken off the server | Encrypted with a separate key before they leave (ADR 0009) | Off-server copy (`REMOTE`) not set up yet |

### Denial of service

| Threat | Controls | Left over |
|---|---|---|
| Floods of sign-ins, codes, uploads, emails | Rate limits (`rate_limits`), upload size caps in PHP and Apache, PHP-FPM pool limits | No network-level protection (no CDN or WAF) in front of the server |
| Heavy pages | Lists are paged or capped (people 50 a page, reports 500 with CSV for more) | |
| The classroom starving the LMS (same server) | Docker memory and CPU limits for Jitsi, low-data mode | Large classes will need a separate Jitsi server |
| A problem in one site taking down the others | Separate virtual hosts, PHP-FPM pools and users; graceful Apache reloads only after `configtest`; `sites-check.sh` before and after every change | One machine: a kernel or disk problem affects every site |

### Elevation of privilege

| Threat | Controls | Left over |
|---|---|---|
| A learner reaching teacher or admin pages | Route permissions (`can:` middleware) and checks again in each use case (`authorise`); tests per role | |
| An admin making themselves owner | Only owners manage owners; nobody changes their own role (tests) | |
| The web app writing outside its folders | Runs as its own user; releases are read-only; `dmldeploy` can only use `dml-deploy` | Root on the shared server can do anything |

## Top risks now, in order

1. **Backup key exposed in chat and no off-server copy.** Rotate the key, store it away from the server, set `REMOTE` (deployment.md → Backups).
2. **One server for six products and mail.** A compromise of any of them is a risk to all. Keep separate users and pools; plan a separate server for the LMS and classroom when the budget allows.
3. **No virus scanning unless ClamAV is installed.** Install ClamAV on the server and set `CLAMD_HOST`.
4. **Live classroom not rehearsed.** Run one class and one meeting with two real devices before the first real class.
5. **No email provider yet.** Password reset and invitations rely on one-time passwords handed out by staff until SPF/DKIM and a provider are set up.

## Out of scope for now

Physical security of the data centre, the security of learners' own devices, and Paystack's and the email provider's own systems.
