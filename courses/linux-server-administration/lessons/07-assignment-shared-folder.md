## The task

Your school's web team has three people who must all be able to edit the files of one project, and nobody else on the server should be able to see them. Set this up on your practice server.

1. Create a group called `webteam`.
2. Create two users, `chidi` and `amaka`, each with a home folder and a password. Add both, and yourself, to `webteam`.
3. Create the folder `/srv/project` owned by `root` and the group `webteam`, so that:
   - members of `webteam` can create, edit and delete files in it;
   - files anyone creates inside it automatically belong to `webteam`;
   - users outside the group cannot list or open anything in it.
4. Prove it works:
   - as `chidi`, create `/srv/project/plan.txt` with some text;
   - as `amaka`, add a line to that file;
   - as a user who is **not** in the group, try `ls /srv/project` and show the error.

> **Tip:** `sudo -iu chidi` opens a shell as Chidi without needing his password. Type `exit` to come back.

## What to submit

Paste the following into your answer, each in its own code block (put three backticks on the line before and after):

1. Every command you ran, in order.
2. The output of `ls -ld /srv/project` and `ls -l /srv/project`.
3. The output of `id chidi` and `id amaka`.
4. The error message from the user outside the group.
5. Two or three sentences explaining **why** you chose the permission number you used, and what the `s` in the listing does.

## How it will be judged

| Criterion | What your teacher looks for |
|---|---|
| Correct access | Group members can create and edit; outsiders are refused |
| Least privilege | No `777`, no world access, nothing done as root that did not need root |
| Group inheritance | New files belong to `webteam` (setgid on the folder) |
| Evidence | Output shows each requirement, not just the commands |
| Explanation | You can say what each permission bit does |

If your teacher asks for changes, read the feedback, fix your server, and submit again with the new output.
