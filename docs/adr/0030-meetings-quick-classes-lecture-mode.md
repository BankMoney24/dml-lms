# ADR 0030: Meetings, starting a class now, lecture mode, and the classroom on phones

Date: 2026-10-26 · Status: accepted

## Context

The online classroom (ADR 0029) is now running on the school's server, on Jitsi in Docker alongside the LMS. Teachers asked for three things:

* to teach without planning first ("I'm ready now, call the class in");
* to hold video meetings that are not classes: staff meetings, parents' evenings, a call with one family;
* a way to stream a class to many learners without thirty microphones and cameras fighting for a weak mobile connection.

Most learners join from phones, so the classroom page had to work first at phone width.

## Decision

### Starting a class now

* Teachers of a class (and administrators) choose **Start a class now** on Live classes, or on the class's page. They pick a length (30 minutes to 2 hours), an optional title and a style.
* A class session is created there and then (`mode = classroom`, starting at the current minute) with its own unguessable room.
* The learners get a "Live now" notice linking straight to the room, and the teacher goes straight in.
* It needs the classroom to be connected. Otherwise the teacher is told to ask an administrator.

### Lecture mode

* `class_sessions.style` is either **lecture** or **discussion**. Lecture is the default for classes started now and offered first when planning. Classes planned before this update stay "discussion", so their behaviour is unchanged.
* In lecture mode, the teacher's page turns on Jitsi's audio and video moderation, so learners must raise a hand and be let in to speak or show video. It also turns on "follow me", so everyone sees what the teacher shows.
* These are Jitsi's own features, driven through the IFrame API (`toggleModeration`, `setFollowMe`). They work only for a moderator, which the teacher is through the `token_affiliation` module.
* This is the "class streaming" teachers asked for: one teacher, many listeners, little data. Broadcasting to YouTube would need Jibri, which is still out of scope.

### Running the room

* Whoever runs the room sees the time left (in the school's time zone, counted from the server's clock), **+15 / +30 minutes** and **End**.
  * Classes can grow to 8 hours, meetings to 4.
  * Ending asks Jitsi to end the call for everyone (`endConference`), then closes the class or meeting so no one can rejoin with a fresh pass.
  * A class's teacher lands on its register.
* The class page offers the same "Add 30 minutes" and "End the class now" without JavaScript.

### Meetings

* `meetings`, `meeting_invites` and `meeting_events` (migration 000026).
* Staff (anyone who can manage courses or groups) host meetings.
  * A meeting can start **now** (the host goes straight in, invitees are told it is live) or **later** (invitees are told the time).
  * It lasts 15 minutes to 3 hours.
* **Who:** all staff; whole classes (their learners and teachers); a class's parents (active guardian links); and anyone in the school by email.
  * Teachers can pick their own classes; administrators any class.
  * Invitations are resolved to people when they are sent. Addresses that are not school members are refused by name, and there are at most 500 people.
  * The host can invite more until the meeting ends.
* **Who gets in:** only the host and the people invited (everyone else gets 404, as for classes), and only from 15 minutes before the start (30 for the host) until the end.
  * The pass is the same signed token as for classes, for a `dmlm-…` room, expiring 15 minutes after the end.
  * The host runs the room. Other invited staff can too, unless the host unticks "Other staff can run it too".
* **Who came:** join, "still here" and leave signals feed "About N min" per person on the host's meeting page, counted the same way as minutes in class.
* Meetings appear:
  * on `/meetings`, with a sidebar link for staff, and for anyone with a meeting that week (with a count);
  * on the home page ("Meeting now" card);
  * in the calendar feed;
  * on Live classes ("Meetings this week").

  Cancelling tells everyone invited, with the reason.

### The classroom on phones

* One room page for classes and meetings (`live/_room.php`).
* At phone width:
  * a back link replaces the breadcrumbs;
  * the data choice is a three-way switch (Audio / Saver / Video) right under the title;
  * the video fills the rest of the screen (`100svh` less the header);
  * materials and help fold away under it.
* Lists show a full-width **Enter** or **Join** button on rows that are live. The class and meeting pages keep the join button pinned to the bottom of the screen while scrolling.
* The new-meeting form is one column of three numbered steps (what, when, who), with large touch targets.

### Connecting without copying the secret

* `bin/console classroom:connect <school> --domain=… [--app-id=…]` reads the secret from a file or standard input, so it can be piped straight from the server file. It refuses `--secret=` on the command line, because that would be kept in shell history.
* `classroom:check` and the new **Check the connection** button fetch the meeting script from the classroom server and report whether it answers. Only a real join proves the token settings.

## Consequences

* The pages drive Jitsi through its IFrame API. If a later Jitsi release renames a command, lecture mode or "end for everyone" quietly stops working, but the meeting itself still works.
* Without `token_affiliation` and `ENABLE_AUTO_OWNER=0` on the Jitsi server, the first person in becomes moderator, and lecture mode applies only if that person is the teacher. The deployment guide lists both settings.
* This is still not tested against a live Jitsi server. The browser tests use a stand-in for `external_api.js` that records the commands the page sends. The school's first real class and meeting are the rehearsal.
