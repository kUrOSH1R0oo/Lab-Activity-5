# To-do List (COMP016 Lab Activity 5)

The Lab 4 to-do list, refactored to store tasks in a **MySQL database** through **PHP Data Objects (PDO)** instead of `$_SESSION`. Tasks now survive cache clears, logouts and server restarts.

## Run with Docker (PHP + MySQL)

```bash
docker compose up --build
```

Open <http://localhost:1337>.

Compose starts two containers: `todo` (PHP 8.3) and `db` (MySQL 8.4). On the first start MySQL runs `task_db.sql` automatically, which creates the `task_db` database, the `tasks` table and three sample rows. Data lives in the `db_data` volume.

| Command | Effect |
| --- | --- |
| `docker compose down` | Stop the containers. Tasks are kept. |
| `docker compose down -v` | Stop and delete the database volume. `task_db.sql` runs again on the next start. |

To use another web port, set `PORT` (see `.env.example`):

```bash
# Linux, macOS, WSL
PORT=3000 docker compose up --build

# PowerShell
$env:PORT=3000; docker compose up --build
```

To browse the database with HeidiSQL, connect to host `127.0.0.1`, port `3307`, user `root`, password `root_password`.

## Run with Laragon (as in the lab handout)

1. Start Apache and MySQL in Laragon.
2. Open HeidiSQL, then **File > Run SQL file...** and choose `task_db.sql`. (Or paste its contents into a Query tab and press F9.)
3. Copy `index.php`, `db.php`, `style.css` and `script.js` into `C:\laragon\www\`.
4. Open <http://localhost/>.

`db.php` defaults to Laragon's settings (`localhost`, user `root`, empty password). Docker overrides them with environment variables.

## Files

| File | Purpose |
| --- | --- |
| `task_db.sql` | Database schema (`task_db`, `tasks`), sample data and the CRUD statements for reference |
| `db.php` | PDO connection module with error handling |
| `index.php` | Main page: CRUD actions, validation, server-rendered list |
| `style.css` | External stylesheet |
| `script.js` | External script: filters, search, inline edit, delete confirmation, toasts, shortcuts |
| `Dockerfile`, `docker-compose.yml` | PHP and MySQL containers |

## Database

```sql
CREATE TABLE tasks (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    task_name        VARCHAR(255) NOT NULL,
    task_description TEXT NOT NULL,
    status           ENUM('pending', 'completed') DEFAULT 'pending',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    priority         ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium'
);
```

The five columns come from the lab handout. `priority` is an extra column that keeps Lab 4's priority feature.

## How it maps to the lab requirements

| Requirement | Where |
| --- | --- |
| Database and table setup | `task_db.sql` |
| PDO connection module with error handling | `db.php` (`try`/`catch (PDOException)`, exceptions enabled) |
| Create: `INSERT` | `add_task()` in `index.php` |
| Read: `SELECT` rendered in the page | `SELECT * FROM tasks` near the end of section 5, `foreach ($tasks as $task)` in the template |
| Update: toggle status, edit task | `toggle_task()` (`IF(status='pending', 'completed', 'pending')`), `update_task()` |
| Delete | `delete_task()`, `clear_done()` |
| Prepared statements for every query | Every query uses `prepare()` and `execute()`; PDO emulation is off, so MySQL prepares them itself |
| State-changing actions use POST | Every change is a `method="post"` form, followed by a redirect (Post/Redirect/Get) |
| Sanitized output (XSS) | `e()` wraps `htmlspecialchars()` on all database output |
| External CSS and JavaScript | `style.css`, `script.js` (delete confirmation dialog, inline edit, filters) |

## Design decisions

- **Sessions only for security and messages.** `$_SESSION` no longer holds tasks. It keeps a per-session CSRF token and one-time flash messages.
- **CSRF token.** Each form carries the token and the server verifies it.
- **Server-side validation.** Name (100 characters) and description (1000) are trimmed and checked in PHP. The browser checks first for speed.
- **Works without JavaScript.** Add, complete and delete still work; JavaScript adds the polish.
- **Container hardening.** The PHP container runs as a non-root user with production settings. The app connects to MySQL as a limited user, not `root`.
- **Credentials.** The values in `docker-compose.yml` are local development defaults. Do not reuse them anywhere public.
