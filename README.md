<<<<<<< HEAD
# Research AI — Backend Completed

The original project has a frontend-first structure and a Phase-1 PHP/MySQL foundation. The supplied database currently contains `users`, `security_events`, and `research_papers`; the backend below extends that foundation without changing the visual design. fileciteturn0file0L27-L41 fileciteturn0file0L103-L121

## What is now implemented

### Authentication & security
- CSRF token endpoint
- Secure PHP sessions
- Login with password verification, failed-attempt tracking and temporary lockout
- Signup with validation and optional JPG/PNG/WebP profile image
- Logout with audit logging
- Forgot-password token generation and reset-password flow
- Password change for authenticated users
- Security-event audit logging
- No plain-text passwords

### User
- Dashboard API with user data, statistics and recent papers
- Profile GET/update including bio, location and profile image
- Settings GET/save
- Notifications list/read-all/read-one
- Bookmark add/remove/list
- Research history add/list

### Research
- Research-paper list/search/filter/pagination
- Paper detail
- Admin paper create/update/delete
- Research-domain endpoint
- Dataset/model/conference explorer endpoints

### AI-ready services
- Research-idea generator with database persistence
- AI chat with conversation/message persistence
- Optional OpenAI Responses API integration through environment variables
- Safe local fallback when no AI key is configured

### Contact
- Contact form API with server-side validation and database persistence

## New database tables

`user_settings`, `password_reset_tokens`, `contact_messages`, `notifications`, `bookmarks`, `research_ideas`, `chat_conversations`, `chat_messages`, `research_history`, `datasets`, `ai_models`, `conferences`.

The existing `research_papers` table already has a primary key, unique DOI, year index and timestamp indexes. fileciteturn0file0L146-L153

## Installation

1. Start Apache and MariaDB/MySQL in XAMPP/WAMP.
2. Import `database/research_ai_complete.sql`.
3. If your MySQL/MariaDB port is not `3307`, change `DB_PORT`.
4. Copy `backend/config/.env.example` to your server environment (do not expose secrets to the browser).
5. If you want real AI chat, set `OPENAI_API_KEY` and optionally `OPENAI_MODEL`.
6. Ensure PHP extensions `pdo_mysql`, `fileinfo`, `mbstring`, and `curl` are enabled.
7. Make `uploads/profile_images` writable by the web server.
8. Open the project through Apache, not by double-clicking the HTML files. Example: `http://localhost/Research_Project/pages/login.html`.

## API endpoints

| Area | Endpoint | Methods |
|---|---|---|
| CSRF | `backend/auth/csrf.php` | GET |
| Auth | `backend/auth/login.php` | POST |
| Auth | `backend/auth/signup.php` | POST |
| Auth | `backend/auth/logout.php` | POST |
| Auth | `backend/auth/forgot-password.php` | POST |
| Auth | `backend/auth/reset-password.php` | POST |
| User | `backend/pages/dashboard.php` | GET |
| User | `backend/pages/profile.php` | GET, POST |
| User | `backend/user/settings.php` | GET, POST |
| User | `backend/user/change-password.php` | POST |
| User | `backend/user/notifications.php` | GET, POST |
| User | `backend/user/bookmarks.php` | GET, POST, DELETE |
| User | `backend/user/history.php` | GET, POST |
| Papers | `backend/papers/list.php` | GET |
| Papers | `backend/papers/detail.php` | GET |
| Papers | `backend/admin/papers.php` | POST, PUT, DELETE |
| Content | `backend/content/domains.php` | GET |
| Content | `backend/content/explorer.php?type=datasets` | GET |
| Content | `backend/content/explorer.php?type=models` | GET |
| Content | `backend/content/explorer.php?type=conferences` | GET |
| AI | `backend/ai/ideas.php` | GET, POST, DELETE |
| AI | `backend/ai/chat.php` | GET, POST, DELETE |
| Contact | `backend/contact/submit.php` | POST |
| Health | `backend/public/health.php` | GET |

## Important integration note

The existing login and signup pages already request a CSRF token and submit to the PHP auth endpoints. The profile page already has a profile form, while the contact/settings/AI pages are currently primarily UI/demo flows. Those pages should call the new endpoints instead of using `setTimeout()` demo responses.

No existing CSS/design files were intentionally redesigned.

## Security notes

- Database credentials stay server-side.
- Prepared statements are used.
- CSRF is required for state-changing authenticated operations.
- Uploaded profile files are MIME-checked, size-limited and renamed.
- PHP execution is blocked in the uploads directory.
- Authentication APIs return generic account errors where appropriate.
- Development password-reset responses may expose a reset token to make local testing possible. In production, configure an email delivery system and set `APP_ENV=production`; the token is then not returned in JSON.

## Original schema reference

The supplied dump targets MariaDB 10.4.32 / PHP 8.2.12 and uses the `research_ai` database. fileciteturn0file0L5-L8
=======
# Research_Project
Research AI is an AI-powered research project designed to support data analysis, intelligent insights, and evidence-based decision-making. It integrates advanced computational techniques and machine learning approaches to streamline research workflows, improve analytical accuracy, and generate meaningful research outcomes.
>>>>>>> f7762b84ce8c5a21daedb665b23d408a98025b0f
