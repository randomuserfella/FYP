# ProcraTrack

A dual-role web application for tracking and reducing student procrastination, built with PHP, MySQL, and Bootstrap.

---

## System Requirements

| Requirement | Version |
|---|---|
| PHP | 8.0 or higher |
| MySQL / MariaDB | 5.7 / 10.4 or higher |
| XAMPP | 8.x recommended |
| Browser | Chrome, Edge, or Firefox (latest) |

---

## Local Setup (XAMPP)

### 1. Copy files
Place the `procratrack/` folder inside your XAMPP `htdocs` directory:
```
C:\xampp\htdocs\procratrack\
```

### 2. Start XAMPP
Start both **Apache** and **MySQL** in the XAMPP Control Panel.

### 3. Create the database
1. Open `http://localhost/phpmyadmin`
2. Create a new database named **`procratrack`**
3. Select the database, go to **Import**, and import `database_full.sql`

### 4. Configure database credentials
Edit `config/db_credentials.php` with your local settings:
```php
$host     = 'localhost';
$db_name  = 'procratrack';
$username = 'root';
$password = '';   // XAMPP default is empty
```

### 5. Open the app
Visit: `http://localhost/procratrack/`

---

## Telegram Bot Setup (for Notifications)

1. Open Telegram and message `@BotFather`
2. Send `/newbot` and follow the prompts — copy your **Bot Token**
3. Copy `config/secrets.example.php` to `config/secrets.php` and set `TELEGRAM_BOT_TOKEN`
4. Set the webhook URL using Cloudflare Tunnel (see below):
   ```
   https://your-tunnel-url.trycloudflare.com/procratrack/telegram_webhook.php
   ```
5. Students link their Telegram by going to **Notifications → Telegram** in the app

---

## Cloudflare Tunnel (for public URL on XAMPP)

Used to expose localhost to Telegram webhooks and for demo purposes.

1. Download `cloudflared.exe` from https://github.com/cloudflare/cloudflared/releases
2. Run: `cloudflared tunnel --url http://localhost:80`
3. Copy the generated `*.trycloudflare.com` URL
4. Update `config/push_config.php` → set `APP_URL` to your tunnel URL

---

---

## Ngrok (alternative to Cloudflare Tunnel)

1. Install ngrok from https://ngrok.com/download and add your authtoken: `ngrok config add-authtoken <TOKEN>`
2. Windows: run `start_procratrack.bat`, which starts Apache and ngrok, registers the Telegram webhook, and updates `APP_URL` automatically.
3. Manual: run `ngrok http 80`, then `php ngrok_setup.php` from the project folder.
4. The free ngrok URL changes on every restart, so rerun the script each time.

---

## Automated Reminders (Cron / Task Scheduler)

`send_reminders.php` sends deadline reminders via Telegram and email. It must be run on a schedule.

### Windows (Task Scheduler)
1. Open Task Scheduler → Create Basic Task
2. Trigger: **Daily**, repeat every **1 hour**
3. Action: **Start a program**
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\procratrack\send_reminders.php`

### Linux / macOS (cron)
```bash
# Run every hour
0 * * * * /usr/bin/php /path/to/procratrack/send_reminders.php
```

---

## Email Notifications Setup

Set `MAIL_USERNAME` and `MAIL_PASSWORD` (a Gmail App Password) in `config/secrets.php`.
> For Gmail, generate an **App Password** under Google Account → Security → 2-Step Verification → App Passwords.

---

## Test Accounts (for FYP demo)

Create accounts via the Register page. Use any of these test institution keys during registration:
- `alpha` — Test University Alpha
- `beta` — Test University Beta

Register one account as **Student** and one as **Lecturer** (same institution) to demonstrate the dual-role monitoring feature.

---

## Key Features

| Feature | Description |
|---|---|
| Task Manager | Add, filter, prioritise, and complete tasks with deadlines |
| Habit Tracker | Daily habit check-ins with 7-day grid and streak counter |
| Focus Timer | Pomodoro, Deep Work, Quick Sprint, and Custom timer modes |
| Smart Insights | Procrastination risk score, XP level, charts, and contextual tips |
| Calendar | FullCalendar with drag-drop, recurring events, and time slots |
| Chat | Class room + group + cross-institution DM with invite system |
| Telegram Bot | Add tasks and view pending tasks directly in Telegram |
| Notifications | Multi-channel: Telegram + Email, 5-tier deadline reminders |
| Lecturer Dashboard | Monitor students' tasks, habits, and focus — scoped to own institution |

---

## File Structure

```
procratrack/
├── config/
│   ├── db.php              # PDO connection
│   ├── db_credentials.php  # Edit this with your DB settings
│   ├── csrf.php            # CSRF protection helper
│   └── tab_session.php     # Per-tab session isolation
├── includes/
│   ├── header.php          # Student nav + sidebar
│   ├── lecturer_header.php # Lecturer nav
│   └── notifier.php        # Telegram + email send functions
├── database_full.sql       # Full DB schema — import this first
├── send_reminders.php      # Run via cron/Task Scheduler hourly
├── telegram_webhook.php    # Telegram bot handler
└── README.md               # This file
```

---

## Secrets and configuration

Credentials are **not** stored in the repository.

1. Copy `config/secrets.example.php` to `config/secrets.php` (git-ignored).
2. Fill in only what you need: Telegram token, Gmail App Password, VAPID keys.
3. Any value left blank simply disables that integration; the core app (tasks, habits, timer, calendar, insights) still runs.

Environment variables with the same names (e.g. `TELEGRAM_BOT_TOKEN`) take priority over `secrets.php`.
