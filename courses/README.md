# Course packages

Each folder here is a complete course that can be imported into any school (tenant):

```bash
php bin/console course:packages                       # what is available
php bin/console course:import <tenant-slug> <name>    # imported as a draft
php bin/console course:import <tenant-slug> <name> --publish --author=teacher@example.org
```

Importing creates a new, independent copy: teachers can edit it afterwards like any course built by hand. Importing twice creates two copies.

## Format

```
my-course/
  course.json
  lessons/01-first-lesson.md
  lessons/…
```

`course.json` holds the course details, the modules, and for each lesson its type and title. Reading, video and assignment lessons point to a Markdown `file` (or give an inline `body`); quizzes hold their questions inline. See `app/Modules/Courses/CoursePackageImporter.php` for the full schema; the import runs through the same validation as the teaching screens and is all-or-nothing.

Lesson text uses the platform's Markdown subset: `##` headings, lists, `**bold**`, `*italic*`, `` `code` ``, fenced code blocks with a language, links, tables, and callouts written as `> **Tip:** …` (also Note, Warning, Important).

## Packages

| Folder | Course | Level |
|---|---|---|
| `linux-server-administration` | Linux Server Administration: 8 modules, 21 readings, 7 module quizzes, 3 reviewed assignments, a 20-question final exam | Advanced, about 40 hours |
| `ss2-science-practicals` | SS2 Science Practicals: Ohm's law, the simple pendulum, titration, osmosis and straight-line graphs; 5 readings, 5 labs, 5 quizzes | School subject, about 9 hours |
| `networking-foundations` | Networking Foundations: devices, addressing, static routes, DNS, firewall rules and troubleshooting; 7 readings, 5 labs (network builder, DNS, firewall and more), 5 quizzes, optional Cisco Packet Tracer assignment | Skills, about 12 hours |
| `practical-tech-skills` | Practical Tech Skills: subnetting, a first JavaScript function and phishing; 3 readings, 3 labs, 3 quizzes | Skills, about 6 hours |
| `everyday-digital-skills` | Everyday Digital Skills: your phone, money on your phone, selling on WhatsApp and Google Maps, email and account safety; 10 readings, 1 lab, 4 quizzes, 2 optional assignments; free and open to everyone | Everyday learning, about 6 hours |

Lab lessons use `"type": "lab"` with `"lab": "<key>"` (see `bin/console lab:list`) and an optional `"intro"`. A course can say `"track": "school"` (curriculum subject), `"track": "skills"` or `"track": "everyday"` (informal learning for life and work); learners can filter the course list by it. `"access": "open"` lets anyone read the readings without an account, and `"required": false` on a lesson makes it optional for finishing the course.

