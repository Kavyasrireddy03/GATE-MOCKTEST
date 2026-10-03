# GATE Mock Test Platform

A PHP + MySQL web app for running GATE-style computer-based mock tests. It mimics the real exam interface (question palette, timer, virtual calculator, mark-for-review), scores attempts, emails results, and includes admin tools for managing questions, sets and schedules.

## Features

- **Candidate side:** login and registration, exam schedule (`index.php`), result dashboard and past attempts (`user_dashboard.php`, `my_attempts.php`), and a daily study tracker (`log_study.php`).
- **Exam console** (TCS iON style, like the real GATE exam): exam login (`exam_login.php`), instructions screen, timed exam with question palette and on-screen calculator (`exam.php`, code in `modules/`).
- **Webcam proctoring** (`modules/face_proctor.js`): a reference photo is taken at the start and kept only in the candidate's browser (localStorage). During the exam it warns on no face, multiple faces or a different person. Warnings are logged to the database as text; no images are ever uploaded or stored on the server.
- **Admin tools:** question upload and editing (`admin_upload.php`, `edit.php`), set and marks management (`set.php`), schedule manager with PDF export (`tpdf.php`), random question picker (`random.php`), and Google Drive image uploads via Apps Script (`admin.php`, `up.php`).
- **Automation:** result emails after each attempt, reminder emails (`email.php`), a daily study report by email (`daily_report_cron.php`) and Telegram (`tg.php`).

## Tech stack

PHP 8, MySQL/MariaDB (mysqli and PDO), Tailwind CSS (CDN), jQuery, [PHPMailer](https://github.com/PHPMailer/PHPMailer) and [Dompdf](https://github.com/dompdf/dompdf) (bundled).

## Setup

1. Copy the project to a PHP-enabled web server (Apache with `mod_php`, or PHP-FPM).
2. Create a MySQL database. The app uses these tables: `users`, `subjects`, `questions`, `set_definitions`, `set_time`, `test_attempts`, `attempt_answers`, `test_attempt_questions`, `daily_study_logs`, `proctor_events`.
3. Copy `config.example.php` to `config.php` and fill in your values: database, site URL, timezone, exam branding (name, logos, banner), SMTP, Telegram, Apps Script and CDN links. Every page reads its settings from this one file, and `config.php` is git-ignored.
4. Open `index.php` in the browser.
5. Optional cron jobs (example schedule, adjust to taste):
   ```
   30 8,20 * * *  php /path/to/email.php
   0 22 * * *     php /path/to/daily_report_cron.php
   0 22 * * *     php /path/to/tg.php
   ```

## Disclaimer

This is an independent practice project. It is not affiliated with or endorsed by GATE, the IITs, or TCS iON. Exam names and logos belong to their respective owners.
