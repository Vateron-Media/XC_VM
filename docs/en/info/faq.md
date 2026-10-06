# FAQ - Frequently Asked Questions

Here you will find answers to the most common questions and issues when working with XC_VM.

---

## Stream Issues

<details>
<summary><b>❌ My stream doesn’t start on MAIN or LB</b></summary>

---

### Diagnostics

Connect to your server console and run the following command:

```bash
sudo -u xc_vm /home/xc_vm/console.php monitor 291
````

> 🧩 Where `291` is your **stream ID** (replace it with your own).

---

### What the command does

The **monitor** command tries to start the stream manually and displays an error if it fails.

---

### Possible causes

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

### Summary

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

## Login & Access Issues

<details>
<summary><b>❌ I'm locked out — IP keeps getting blocked</b></summary>

---

XC_VM's brute-force guard blocks an IP address after too many refused requests. This is controlled by:

- `bruteforce_mac_attempts` — an address is blocked when it fails with this many **different** MAC addresses inside `bruteforce_frequency` seconds (5 by default; it is not a number of attempts per MAC). It counts MAG portal handshakes for a MAC the panel does not know and `get_profile` requests that do not verify the device. Block note: `BRUTEFORCE MAC ATTACK`.
- `bruteforce_username_attempts` — an address is blocked when it tries this many **different** unknown usernames, tokens or activation codes inside `bruteforce_frequency` seconds (10 by default), or this many **different** wrong passwords for one username. The same wrong password sent again counts once, so a device left with an old password is not blocked by this count (the flood limit still applies to tight loops); a correct sign-in is never counted, and the username's letter case makes no difference. Block note: `BRUTEFORCE USER ATTACK`.
- `flood_limit` — refused requests in a row, each within `flood_seconds` of the one before. Block note: `FLOOD ATTACK`.

A limit that is 0, empty or not set switches that count off; the other two keep counting.

**Typical causes for a legitimate user:**

- **An app that retries in a tight loop.** Refused sign-ins on `player_api.php` / `panel_api.php` count toward `flood_limit`, as they do on `get.php`, `xmltv.php` and `enigma2.php`: a wrong password, an unknown token or activation code, or a code locked to another device. On `player_api.php`, expired, banned or disabled lines, and activation codes that have run out or been suspended, are answered with their status and are **not** counted.
- **Someone trying many passwords for a line from one address.** Unblock the address and correct the password saved on the device.
- **Several set-top boxes set up from one address before their MACs are registered.** Each unknown MAC counts toward `bruteforce_mac_attempts`, so the address can be blocked. Register the devices first, add the address to `flood_ips_exclude`, or set `bruteforce_mac_attempts` to 0.

An address listed in `flood_ips_exclude` is exempt from all three limits.

**To unblock yourself:**

1. **From admin panel:** Tools → IP Management → remove from blocked list.
2. **From CLI:** `sudo /home/xc_vm/console.php tools flush` — flushes all blocked IPs.
3. **If completely locked out:** Use `console.php tools rescue` to create a rescue access code (see [CLI Tools](../guides/cli-tools.md)).

---

</details>

<details>
<summary><b>❌ Set-top box gets blocked after a reset/firmware change (its serial or device_id changed, but the panel has the old one)</b></summary>

---

A box that used to work starts getting its **IP blocked** after a **factory reset, firmware change/update, hardware swap** (or moving the MAC to a different box): it now reports a **different serial number (`sn`) or `device_id`** than what the panel has stored. On `get_profile` the server blocks the IP and the portal returns 404.

This is **not a bug** — it's the portal's anti-clone protection, **MAGSCAN**. It requires a serial number and compares the posted `sn` against the stored `mag_devices.sn`:

- **No serial number** in the request → ban (`[MS] No Serial Number`).
- **Posted `sn` ≠ the device's stored `sn`** → ban (`[MS] Invalid Serial Number`).

In both cases the IP is written to the `blocked_ips` table (and from there into iptables) and the device gets a 404. If the device has the **`lock_device`** flag set, `device_id`, `device_id2` and `hw_version` are checked too — a mismatch fails verification and the device shows "your device is not active" (without an IP ban).

**How to fix (for a legitimate box whose data genuinely changed):**

1. **Reset the binding in the panel:** open that MAG device in admin and **clear its stored serial number / `device_id`** (or delete and re-add the device). The "serial already recorded" condition then no longer triggers, and the next connection binds the new values.
2. **Unblock the IP.** Easiest way — **via the web panel**: open **Tools → IP Management** (`/<admin-code>/ips`), which lists the blocked IPs — remove the one you need (or clear the whole list). CLI / manual options if you can't reach the panel:
   - CLI (clear all blocks): `sudo /home/xc_vm/console.php tools flush`;
   - Manually, per IP: `sudo iptables -D INPUT -s <IP> -j DROP && sudo rm -f /home/xc_vm/tmp/flood/block_<IP>`.

> ⚠️ The `enable_debug_stalker` setting bypasses the `lock_device` / image checks but **NOT** the MAGSCAN serial hard-ban (which runs earlier) — you still have to clear the stored `sn` in the panel.

---

</details>

<details>
<summary><b>❌ Forgot admin password / can't log in at all</b></summary>

---

Create a new rescue admin user via CLI:

```bash
sudo /home/xc_vm/console.php tools user
```

This outputs a random username and password with full admin privileges. Log in, change the password, and delete the rescue user when done.

If the admin panel URL itself is unknown, create a rescue access code:

```bash
sudo /home/xc_vm/console.php tools rescue
```

---

</details>

---

<details>
<summary><b>❌ Viewers are asked to sign in to the web player again after the update</b></summary>

---

The web players keep their sign-in in a session cookie of their own (`PLAYERSESSID`), separate from the admin and reseller panels' (`PHPSESSID`). A sign-in from before the update is not carried over, so every viewer signs in once. An administrator signed in to both the panel and the player has two separate sessions: signing out of one leaves the other.

---

</details>

<details>
<summary><b>❌ A playlist or EPG download answers HTTP 429</b></summary>

---

**Max Simultaneous Downloads** (`max_simultaneous_downloads`, default 2) is the number of playlist downloads, and separately the number of XMLTV/EPG downloads, one line may have running at the same moment on one server. A request over the limit is answered with HTTP 429 and an empty body and can simply be retried: it is admitted as soon as one of the running downloads ends. 0 turns the limit off; restreamer lines are never limited. Each server (main and every load balancer) counts its own.

A download counts until the server has finished sending it or has given up on the client. A client that stops receiving without closing the connection (app suspended, coverage lost) keeps its download counted for up to 20 minutes (nginx's `send_timeout`). If lines get 429 with nothing visibly downloading, raise the setting or set it to 0.

---

</details>

## Database & Configuration

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
sudo /home/xc_vm/console.php status
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

See [Database Migrations](../guides/database-migrations.md) for details.

---

</details>

---

## SSL & Nginx

<details>
<summary><b>❌ SSL certificate generation fails</b></summary>

---

`console.php certbot` can fail with different error codes:

| Error | Cause | Fix |
|---|---|---|
| Error 3 | No host name in the server's domain list: IP addresses, names with an underscore and wildcards are left out of the request | Add a domain name that points to the server (an entry written as a URL, `http://name/`, is requested by its host name) |
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
sudo /home/xc_vm/console.php tools ports
```

---

</details>

---

## Updates & Service

<details>
<summary><b>❌ The update prints "Panel account names used more than once"</b></summary>

---

No two panel accounts (administrators, resellers) may share a username; names are compared without case and trailing spaces, so `Admin` and `admin` are one name. When accounts share a name, the database step `068_unique_panel_account_names` fails and prints every such name on an `[ERR]` line; it changes nothing and the panel works as before. Rename all accounts of each listed name but one (Users page), then run the update again:

```bash
sudo -u xc_vm /home/xc_vm/console.php update update
```

---

</details>

<details>
<summary><b>❌ Panel Logs shows "has held its cron lock since"</b></summary>

---

A cron runs once at a time on each server: while one run holds its lock, the next ones exit. The line appears once a cron has run for an hour. It is harmless for a long backup, clean-up or TMDb scan. If the cron is hung (no progress, usually after a database or network interruption on a node), end it with `sudo kill -9 <pid>` (the PID is in the line); the next cron minute takes over. Nothing else ends it, and that cron does not run on that server meanwhile. `cron:servers` and `cron:streams` are the exception: a run of either that lasts more than ten minutes is ended by the next one, and Panel Logs says so.

---

</details>

<details>
<summary><b>❌ Update download fails or checksum mismatch</b></summary>

---

The update system downloads from GitHub releases. If it fails:

- **Network/firewall** blocks access to GitHub
- **Partial download** — connection dropped mid-way
- **MD5 mismatch** — corrupted file (update is safely aborted)

Updates are never applied if the checksum doesn't match. Re-run the update after fixing network issues:

```bash
sudo -u xc_vm /home/xc_vm/console.php update update
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
sudo /home/xc_vm/console.php service start
```

Common causes: PHP transaction deadlock, infinite loop in stream processing, or network socket timeout waiting for a response.

---

</details>

---

## Permissions & System

<details>
<summary><b>❌ Permission denied errors keep reappearing</b></summary>

---

Run the status command — it automatically repairs all known permission issues:

```bash
sudo /home/xc_vm/console.php status
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
   sudo /home/xc_vm/console.php tools mysql
   ```
3. **Timeout:** If `last_check_ago` exceeds 180 seconds, server is marked offline

**Debug:** Run on MAIN to check connectivity:

```bash
sudo -u xc_vm /home/xc_vm/console.php watchdog
```

---

</details>

---

📘 *This page is updated over time. If you discover a new common issue — please suggest it in [Issues](https://github.com/Vateron-Media/XC_VM/issues).*

---
