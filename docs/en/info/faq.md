<h1 align="center">❓ FAQ — Frequently Asked Questions</h1>

<p align="center">
  Here you’ll find answers to the most common questions and issues when working with <b>XC_VM</b>.
</p>

---

## ⚙️ Stream Issues

<details>
<summary><b>❌ My stream doesn’t start on MAIN or LB</b></summary>

---

### 🔍 Diagnostics

Connect to your server console and run the following command:

```bash
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php monitor 291
````

> 🧩 Where `291` is your **stream ID** (replace it with your own).

---

### 📄 What the command does

The **monitor** command tries to start the stream manually and displays an error if it fails.

---

### ⚠️ Possible causes

#### 1️⃣ Missing system libraries

If the output contains an error like:

```
error while loading shared libraries: libxyz.so.1: cannot open shared object file
```

Install the missing library with:

```bash
sudo apt install <library_name>
```

After installation, rerun the test.

> 💬 Let me know if a library needs to be added to the installation script.

---

#### 2️⃣ Error not related to libraries

If the error is of another type — send its output so I can help diagnose it.

---

### 🧾 Summary

1. Run the diagnostic command.
2. Check for any errors.
3. Install missing libraries if necessary.
4. Report any other errors for further analysis.

---

</details>

<details>
<summary><b>❌ Streaming fails with "IP_MISMATCH" or "TOKEN_EXPIRED"</b></summary>

---

These are security features, not bugs:

- **TOKEN_EXPIRED** — session token has a time limit. The user needs to re-authenticate.
- **IP_MISMATCH** — the user's IP changed mid-stream (often detected as credential sharing).

**Relevant settings:**
- `restrict_same_ip` — how strict IP matching is
- `disallow_2nd_ip_con` — block simultaneous connections from different IPs

If this causes issues for legitimate users (e.g., mobile networks frequently rotating IPs), adjust the restriction level in admin panel settings.

---

</details>

---

## 🔐 Login & Access Issues

<details>
<summary><b>❌ I'm locked out — IP keeps getting blocked</b></summary>

---

XC_VM's brute-force guard blocks IPs after too many failed login attempts. This is controlled by:

- `bruteforce_mac_attempts` — attempts per MAC per time window
- `bruteforce_username_attempts` — attempts per username per time window
- `flood_limit` — total requests per window

**To unblock yourself:**

1. **From admin panel:** Tools → IP Management → remove from blocked list.
2. **From CLI:** `sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools flush` — flushes all blocked IPs.
3. **If completely locked out:** Use `console.php tools rescue` to create a rescue access code (see [CLI Tools](en-us/development/cli-tools.md)).

---

</details>

<details>
<summary><b>❌ Forgot admin password / can't log in at all</b></summary>

---

Create a new rescue admin user via CLI:

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools user
```

This outputs a random username and password with full admin privileges. Log in, change the password, and delete the rescue user when done.

If the admin panel URL itself is unknown, create a rescue access code:

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools rescue
```

---

</details>

---

## 🗄 Database & Configuration

<details>
<summary><b>❌ "Couldn't connect to database" on startup</b></summary>

---

The most common issue. Causes:

1. **Wrong credentials in `config.ini`** — check `host`, `port`, `db_user`, `db_pass`, `db_name`
2. **MySQL/MariaDB not running** — `sudo systemctl status mariadb`
3. **Network unreachable** — DB server on another host and port is firewalled
4. **User lacks privileges** — re-grant with `console.php tools mysql`

**Fix:** Edit `/home/xc_vm/config/config.ini`, then run:

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php status
```

---

</details>

<details>
<summary><b>❌ Database migration fails during update</b></summary>

---

Migration `.sql` files from `migrations/` run automatically during updates. If one fails:

- The migration is recorded with `[WARN]` status — it won't retry automatically.
- Common causes: syntax error, table already exists, foreign key conflict, missing ALTER privilege.

**Debug:**

1. Check which migration failed in the console output.
2. Open the file in `migrations/` and inspect the SQL.
3. Fix the issue manually in MySQL, then the next update will continue from where it stopped.

See [Database Migrations](en-us/development/cli-tools.md#database-migrations) for details.

---

</details>

---

## 🌐 SSL & Nginx

<details>
<summary><b>❌ SSL certificate generation fails</b></summary>

---

`console.php certbot` can fail with different error codes:

| Error | Cause | Fix |
|---|---|---|
| Error 3 | Domain is a bare IP address | Certbot requires a domain name, not an IP |
| Error 4 | Dry run failed — port 80/443 in use | Stop conflicting service: `sudo lsof -i :80` |
| Error 0 | Files not found after generation | Check `/home/xc_vm/bin/certbot/logs/xc_vm.log` |
| Error 2 | Unexpected certbot error | Check logs, ensure DNS resolves to your server |

**Also:** Remove stale lock files if certbot was interrupted:

```bash
sudo rm -f /home/xc_vm/bin/certbot/*/.certbot.lock
```

---

</details>

<details>
<summary><b>❌ Nginx won't reload — port conflicts</b></summary>

---

XC_VM runs **two** nginx instances:

1. **nginx** (`bin/nginx/`) — HTTP(S) traffic
2. **nginx_rtmp** (`bin/nginx_rtmp/`) — RTMP streaming

Each can fail if its port is already in use.

**Diagnose:**

```bash
sudo netstat -tlnp | grep -E ':80|:443|:1935'
```

**Fix:** Change the broadcast port in admin panel settings, then regenerate configs:

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools ports
```

---

</details>

---

## 🔄 Updates & Service

<details>
<summary><b>❌ Update download fails or checksum mismatch</b></summary>

---

The update system downloads from GitHub releases. If it fails:

- **Network/firewall** blocks access to GitHub
- **Partial download** — connection dropped mid-way
- **MD5 mismatch** — corrupted file (update is safely aborted)

Updates are never applied if the checksum doesn't match. Re-run the update after fixing network issues:

```bash
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php update update
```

---

</details>

<details>
<summary><b>❌ Service stops unexpectedly or won't stop cleanly</b></summary>

---

The service command uses escalating kill signals. If processes hang:

```bash
# Check for stuck processes
ps -u xc_vm

# Force kill if necessary
sudo killall -9 -u xc_vm

# Restart cleanly
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php service start
```

Common causes: PHP transaction deadlock, infinite loop in stream processing, or network socket timeout waiting for a response.

---

</details>

---

## 🛠 Permissions & System

<details>
<summary><b>❌ Permission denied errors keep reappearing</b></summary>

---

Run the status command — it automatically repairs all known permission issues:

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php status
```

What it fixes:
- PHP-FPM socket permissions (`bin/php/sockets/*`)
- Content directory ownership (`content/streams/`)
- Config file ownership (`config/`)
- Executable bit on `daemons.sh`
- Network interface permissions (`/sys/class/net`)

If permissions break after every restart, check that the `xc_vm` system user exists and owns `/home/xc_vm`.

---

</details>

<details>
<summary><b>❌ Load Balancer shows as offline / can't sync with MAIN</b></summary>

---

LB servers poll MAIN via HTTP and process signals. When sync fails:

1. **Network:** LB can't reach MAIN's HTTP port — check firewall rules
2. **Database:** LB can't connect to MAIN's MySQL — re-grant privileges:
   ```bash
   sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools mysql
   ```
3. **Timeout:** If `last_check_ago` exceeds 180 seconds, server is marked offline

**Debug:** Run on MAIN to check connectivity:

```bash
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php watchdog
```

---

</details>

---

📘 *This page is updated over time. If you discover a new common issue — please suggest it in [Issues](https://github.com/Vateron-Media/XC_VM/issues).*

---
