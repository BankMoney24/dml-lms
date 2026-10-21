# ADR 0029: The online classroom, and video and audio uploads

Date: 2026-10-21 · Status: accepted

## Context

ADR 0028 made live classes plannable, but "online" still meant a link to someone else's meeting service. That causes four problems:

* teachers juggle accounts and links;
* there is no way to know who actually turned up;
* anyone holding a link can get in;
* the experience is heavy on data for learners on Nigerian mobile networks.

The school's plan (self-hosted Jitsi Meet on `live.dmlschooloftechnology.org`, low-data and audio-first, server-side checks before issuing short-lived signed tokens, recording deferred) asked for a classroom inside the platform. Teachers also wanted to upload their own videos and voice recordings, not only link to YouTube.

## Decision

### The online classroom

* **Self-hosted Jitsi Meet, embedded.** A school connects its own Jitsi server under **School → Classroom**:
  * the server name;
  * the app id;
  * the app secret: write-only, sealed with APP_KEY like the Paystack key, never shown, logged or sent to a browser;
  * whether learners start in data saver.

  A class can then be planned with the place **"Online classroom on this site"** (`class_sessions.mode = classroom`). This becomes the default on the group page once the classroom is connected.
* **Rooms are unguessable.** Each class gets its own room: `dml-` followed by 24 random hex characters. A weekly series gets a different room each week.
* **Passes are made on the server, per person, per class.** `GET /classes/{id}/room` checks that:
  * the person teaches the class's group, or learns in it (others get 404, as everywhere else);
  * the class is in the classroom and not cancelled;
  * it is on now: learners from 15 minutes before the start, teachers from 30 minutes before, until the end.

  Only then does it sign an HS256 JWT in the shape Jitsi's token authentication expects:
  * `aud` = `jitsi`, `iss` = app id, `sub` = domain, `room`;
  * `nbf`, and `exp` 15 minutes after the class ends;
  * `context.user` with the name, an id, and `moderator` / `affiliation` (owner for teachers, member for learners).

  The page is `Cache-Control: private, no-store`. Being signed in is never enough on its own. The Jitsi server must have `allow_empty_token = false` so rooms cannot be created without a pass. Making only teachers moderators needs token-based affiliation on the Jitsi side, for example the community `token_affiliation` module; the settings page says so.
* **Only that page may talk to Jitsi.** The room page sets its own Content-Security-Policy, allowing script and frame from exactly `https://{domain}`. The domain is validated as a bare host name, so it cannot inject into the header. The page also sets a Permissions-Policy letting that origin use the camera, microphone, screen sharing and fullscreen. Every other page keeps `camera=(), microphone=()`. SecurityHeaders now lets a controller set Permissions-Policy, as it already did for CSP.
* **Low data, audio first.** The learner chooses one of three modes beside the class title; the choice is remembered on the device:

  | Mode | What it does |
  |---|---|
  | **Audio only** | Jitsi `startAudioOnly`: no video sent or received. |
  | **Data saver** | 180p, only the speaker's video (`channelLastN` 1). The default when the school ticks "start learners in data saver". |
  | **Full video** | 360p, a few videos at a time. |

  For everyone:
  * learners start muted with their camera off;
  * the toolbar is cut down (teachers also get screen sharing and "mute everyone");
  * the Jitsi pre-join screen and deep linking to the app are off;
  * the class materials sit beside the stage so they can be downloaded.
* **Reconnecting.** On losing the connection the page says so in plain words. **Rejoin** reloads the page, which brings a fresh pass, and the learner stays marked present.
* **Attendance from the classroom.** The page sends three signals to `POST /classes/{id}/signal`:
  * `join` when Jitsi reports the person is in;
  * `here` once a minute;
  * `leave` on leaving, via `sendBeacon` on page hide.

  These are stored in `class_events`. A learner's first `join` marks them present, or late after 10 minutes, exactly like the Join button in ADR 0028; a teacher's mark is never overwritten. Signals are refused outside the class window. A `here` within 40 seconds of the last signal is dropped, so two tabs do not count double.

  **Time in class** is the number of distinct minutes with a join or here signal, between the room opening and the end. It is robust to phones that drop off without saying goodbye. The register shows it per learner ("Joined online, about 42 min in the classroom"). This separates a planned class from an attended one.
* **Not tested against a live Jitsi server.** The sandbox this was built in cannot reach one. The page's own behaviour was tested in a browser against a stand-in for `external_api.js`:
  * the signals;
  * attendance;
  * the data modes;
  * the CSP letting the frame load;
  * the unreachable-server message.

  The token format follows Jitsi's documentation. The first real class should be a rehearsal (docs/deployment.md, "Online classroom").

### Video and audio uploads

* **Media kinds.**
  * Video: MP4 and WebM.
  * Audio: MP3, M4A, OGG, Opus and WAV.
  * Each is checked by contents (libmagic) like every other upload. Other camera formats such as .mov should be exported as MP4.
  * Media has its own size limit, `MEDIA_MAX_MB` (default 200); other files keep `UPLOAD_MAX_MB`.
  * The limits people are told, and the checks made in the browser before uploading, are capped by PHP's real `upload_max_filesize` / `post_max_size`. That way nobody waits for an upload the server will drop.
  * Media cannot be attached to help messages.
* **Video and audio lessons.**
  * A video lesson takes an uploaded video, or a YouTube/Vimeo link as before; the upload wins if both are set.
  * The new **audio** lesson type takes a recording (a talk, a podcast, a language exercise).
  * The media is stored as one of the lesson's files, so the existing access rules apply, and `lessons.media_file_id` points at it. Uploading again replaces it.
  * Publishing needs the media.
  * Audio lessons are completed by the learner and can be free previews.
  * Course packages accept `"media": "media/clip.mp4"`.
* **Class materials and recordings.** Teachers attach files to a class (`files.class_session_id`): slides and worksheets before, so learners can download them on a better connection, and the recording after. Ticking "This is the recording of the class" sets `class_sessions.recording_file_id`; the recording must be video or audio, replaces any earlier one, and plays at the top of the class page. Only the class's teachers and learners can open these files.
* **Playing without wasting data.**
  * Players use `preload="none"`: nothing is fetched until play is pressed.
  * The size is shown with a "Download to watch offline" link.
  * Files are served with HTTP Range support (206, `Accept-Ranges`, 416), so seeking works.
  * An open-ended range is answered with at most 4 MB at a time, so stopping playback stops the download and no PHP worker is held for a whole video.
  * The session lock is released before streaming, so a playing video never stalls the learner's other pages.
  * Visitors play free-preview media through `/courses/{slug}/preview/{lesson}/media`, under the same rules as the preview page.
* **Uploads show progress.** Forms marked `data-progress` send by XMLHttpRequest with a progress bar, then show the page the server answers with, so messages and errors appear as usual. Without JavaScript they post normally.
* **Clear refusals.** When clamd refuses a file because of its own `StreamMaxLength`, the person is told that, not "the scanner is unavailable".

## Consequences

* Running the classroom needs a separate Jitsi server, ideally away from the database (4 vCPU and 8 GB RAM for one class of about 30 is the plan's starting point, not a guarantee). It also needs UDP 10000 open, and ideally a TURN relay (coturn on TCP 443) for strict mobile networks.
* Media makes storage grow faster. The per-school quota (`UPLOAD_TENANT_QUOTA_MB`) and the nightly backup both cover these files; watch the disk.
* Larger uploads need the new PHP-FPM pool values:
  * `upload_max_filesize` 210M and `post_max_size` 215M;
  * `max_input_time` and `request_terminate_timeout` 1800s;
  * Apache `LimitRequestBody` 220 MB;
  * with ClamAV, `StreamMaxLength` of at least 210M.
* Automatic recording (Jibri) is deliberately not included; teachers upload recordings instead.
