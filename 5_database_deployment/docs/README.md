# ExamPro — PHP + MySQL edition (XAMPP)

ExamPro is an online MCQ examination system with **Admin, Examiner and Student portals**.

## Install on XAMPP (Windows / macOS / Linux)
See `DEPLOYMENT.md` for the full guide. Short version:
1. Install XAMPP with **PHP 8.0+** and enable the `zip` extension.
2. Copy the whole `ExamPro` folder (the five numbered folders) to `C:\\xampp\\htdocs\\ExamPro`.
3. Start **Apache** and **MySQL**, then in phpMyAdmin import `5_database_deployment/database/exampro.sql`.
4. Check `5_database_deployment/config/database.php`.
5. Open **http://localhost/ExamPro/2_frontend_student/** (home page).

## Sample logins (change them!)
| Role | Login | Password |
|---|---|---|
| Admin | `admin` | `admin123` |
| Examiner | `examiner@example.com` | `Examiner@123` |
| Student | `student@example.com` | `Student@123` (already verified, assigned to the sample test) |

Portals: `/ExamPro/1_frontend_admin/admin/`, `/ExamPro/1_frontend_admin/examiner/`, `/ExamPro/2_frontend_student/student/`.
New students register themselves, then an **administrator verifies** them; examiners assign tests to verified students.

## Migrating old data
Your supplied `data.json` was empty, so nothing had to be imported. If you have a populated one:
`C:\xampp\php\php.exe database\migrate_json.php "C:\path\data.json" --dry-run` then again without `--dry-run`.
Old scrypt password hashes cannot be verified by PHP, so those accounts get temporary passwords written to `5_database_deployment/database/migration_credentials.csv` (delete it after use).

## Folder structure
```
ExamPro/
  1_frontend_admin/         admin/ (admin console), examiner/ (sign-in + workspace), assets/js/admin.js + examiner.js
  2_frontend_student/       index.php (home page), student/ (portal), assets/css + shared js (common.js, ux.js, student.js)
  3_backend_auth_users/     api/auth.php (sign-in/out, signup), api/admin.php (user management, admin password)
                            includes/ paths, bootstrap, functions, auth, csrf, layout  (core used by every page and API)
  4_backend_exam_logic/     api/examiner.php, api/student.php; includes/ grading, reports, spreadsheet; samples/
  5_database_deployment/    database/ (exampro.sql, migrate_json.php), config/, uploads/, docs/
```
`includes/paths.php` holds the folder names and `url()` in `functions.php` maps logical paths (`admin/`, `api/auth.php`...) to the right folder.

## How the important parts work
* **Timer:** `exam_attempts.deadline_at` is fixed by the server when the student starts. The browser only displays `deadline - now` (re-synced every 20 s and on every save), so refreshing never resets it. After the deadline (+5 s network grace, `config/app.php`) the server refuses answers and finalizes the attempt; attempts abandoned by closing the browser are finalized the next time anyone views results.
* **Autosave:** every answer is saved immediately; failed saves are retried and the full answer set is re-sent on submit.
* **Submission:** one transaction locks the attempt row, grades, writes `results`, and closes the attempt; repeated or concurrent submits return the stored result.
* **Anti-cheating:** the browser detects full-screen exit, tab hidden and window blur and reports them to `4_backend_exam_logic/api/student.php?action=incident`; the server stores timestamped incidents and auto-submits when the examiner-configured *violation limit* is reached (default 1 = original behaviour). Reloading the page is logged as an informational `exam_resumed` event and is **not** counted. Browser-side monitoring can deter and record misconduct; it cannot completely prevent it (another device, a second monitor, etc.).
* **Scoring:** correct = question marks; wrong = `-marks x fraction` if negative marking is on; total is never below 0; pass mark default 40 %.
* **Access control:** examiner queries always include `examiner_id = <session user>`; other examiners' ids return 404. Students only reach their own assignments/attempts. The answer key is never sent during an exam.
* **Security:** bcrypt (`password_hash`), session-id regeneration at login, separate session namespace per role, CSRF header on all non-GET API calls, PDO prepared statements, output escaping, upload type/size checks (files are parsed from the temp upload and never stored), CSV formula-injection guard, login throttling.

## Question file format
Headers: `Question, Option A, Option B, Option C, Option D, Correct Answer (A-D), Marks (optional)`; aliases such as `Choice A` or `Option 1` work. `.xlsx` and `.csv` supported (UTF-8, `;`/tab delimiters auto-detected).

## Limitations / differences from the original
* Legacy **`.xls`** files are not supported (no library-free reader) — save as `.xlsx` or `.csv`. The original accepted `.xls`.
* The old examiner "Preview / attempt" button (which created a demo result) is now a read-only **Preview**; examiners cannot take their own tests.
* Examiners cannot verify students (the original UI said so too; the admin does).
* Migrated results have no per-question answers (the old JSON never stored them).
* Sessions are one PHP session per browser; roles stay independent, but two different users of the same role in the same browser profile will replace each other.
* **This build was written without access to PHP/MySQL, so it was statically reviewed but not executed.** Run `TESTING_CHECKLIST.md` first and report any failure.
