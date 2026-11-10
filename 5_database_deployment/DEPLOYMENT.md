# ExamPro deployment guide

## Layout
Upload/copy the **whole `ExamPro` folder** so the five numbered folders stay side by side. They depend on each other.

| Folder | Role |
|---|---|
| `1_frontend_admin` | Admin console and examiner pages |
| `2_frontend_student` | Home page, student portal, shared CSS/JS |
| `3_backend_auth_users` | Authentication, user management, core includes |
| `4_backend_exam_logic` | Exams, grading, results, spreadsheet import/export |
| `5_database_deployment` | SQL, config, uploads/sessions, docs |

Entry point: `https://your-host/ExamPro/2_frontend_student/`

## Local (XAMPP)
1. Enable `extension=zip` in php.ini. PHP 8.0+ required.
2. Copy to `htdocs/ExamPro`, start Apache + MySQL.
3. phpMyAdmin -> Import `5_database_deployment/database/exampro.sql`.
4. Edit `5_database_deployment/config/database.php` if your MySQL user/password differ.

## Production checklist
- Create a dedicated MySQL user (not root) and put its credentials in `config/database.php`.
- Serve over HTTPS (session cookie becomes `Secure` automatically).
- Change the seeded passwords (admin can use Settings & help -> Change password; reset examiner/student passwords from the console).
- Keep `config/app.php` `debug` = false.
- Apache must honour `.htaccess` (`AllowOverride All`): the `includes/`, `config/`, `database/` and `uploads/` folders contain `Require all denied`. On nginx add equivalent `deny all` rules for those four paths.
- Make `5_database_deployment/uploads/` writable by the web server (PHP sessions are stored in `uploads/_sessions`).
- Delete `database/migration_credentials.csv` after any data migration.
- If hosted under a different URL prefix, set `base_path` in `config/app.php`.
