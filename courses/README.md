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
