# ADR 0017: Help requests

Date: 2026-10-13 · Status: accepted

## Context

PRD SUP-001 asks for in-product help: anyone in a school (learners, teachers, guardians) should be able to ask for help, attach a screenshot or document, and see the answer, without email back-and-forth or support staff seeing more than they need.

## Decision

* **One thread per request.** A request has a category (account, course, payment, technical, other), a subject and a conversation of messages. Status is `open` (waiting for the school), `answered` (the school replied last) or `resolved`. A staff reply sets `answered`; a reply from the person who asked sets `open`, including on a resolved request, so a problem that returns is not lost. The person who asked or staff can mark it resolved.
* **Who answers.** School owners and admins (`support.manage`) work the **Help desk** tab at `/school/support`, filtered by status with counts. Teachers do not see other people's requests. Requests stay inside the school (tenant-scoped keys); platform staff are not involved in this version.
* **Who sees what.** The person who asked sees their own requests at `/help`; anyone else gets "not found". Attachments (up to 3 per message, the usual type, size and virus checks) are stored with `files.support_message_id` and open only for the person who asked and support staff.
* **Limits.** At most 5 unresolved requests per person and 5,000 characters per message, to stop flooding.
* **Notifications** (ADR 0015): new requests and replies tell support staff; staff replies tell the person who asked. Opening, replying, resolving and reopening are audited.

## Consequences

* Assignment to a named staff member, canned answers, response-time targets and email notifications can be added later without changing the data model much.
* Platform-level support (for school owners asking DML Technologies) would need a separate queue outside the tenant.
