# IAP Portal

Industrial Awareness Program portal built with PHP and MySQL for student onboarding, session registration, psychometric assessment, and admin management.

## Setup

1. Start Apache and MySQL in XAMPP.
2. Open phpMyAdmin and import `final_database.sql`.
3. Update credentials in `config/db.php`:
   - `$db_host`
   - `$db_user`
   - `$db_pass`
   - `$db_name`
4. Place project in XAMPP htdocs path (example: `htdocs/IAP_VER8`).
5. Run in browser: `http://localhost/IAP_VER8/index.php`.

## Database

- Single connection file: `config/db.php`
- Single setup SQL file: `final_database.sql`
- Core tables included:
  - `iap_users_details`
  - `IAP_students`
  - `sessions`
  - `iap_student_sessions`
  - `iap_session_registrations`
  - `iap_session_suggestions`
  - `iap_psychometric_scores`
  - `psychometric_questions`

## Structure

- `config/` - centralized configuration (`db.php`)
- `Admin/` - admin pages and dashboard
- `Student/` - student auth and guard files
- `assets/` - static files (reserved for images/css/js)

## Security Notes

- DB credentials are centralized and no longer duplicated across files.
- Prepared statements are used in critical auth/register flows.
- Session-based access checks remain active for protected pages.
