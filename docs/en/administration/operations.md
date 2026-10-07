# Operations

Tools for running a panel day to day: a maintenance switch for planned work, metrics and a health check for your monitoring, alerts, expiry reminders for subscribers, and off-site copies of the backups.

## Maintenance mode

**Settings → General → Maintenance** closes the panel to its clients while you work on it (a migration, a restore, a large update):

- **Client apps** get a maintenance answer: `player_api` answers `user_info.status` `Maintenance` with the message, which apps show; playlists, XMLTV, Enigma2 and the Ministra portal answer HTTP `503` with the message and, when an end time is set, `Retry-After`. Load balancers answer the same: the switch reaches them with their settings.
- **Resellers** cannot sign in: the login page shows the message instead of the form, a reseller already signed in is signed out to it at their next page, and the Reseller API answers `503` with `{"status":"STATUS_FAILURE","error":"<message>"}`.
- **Admins** work as usual, and **viewers already watching** keep watching: streams are not cut.

**Ends At** turns it off by itself at that time (the panel's time zone); left empty, it stays on until switched off. **Message** is what clients and resellers read; empty, it is "The service is under maintenance. Please try again later." While it is on, the dashboard shows a banner with the end time.

The Admin API's `edit_settings` sets the same keys: `maintenance_mode` (0/1), `maintenance_until` (a Unix time, 0 for no end) and `maintenance_message`.

## Metrics and health check

MAIN answers two monitoring endpoints on its HTTP port (load balancers do not):

- **`/healthz`** needs no credentials and answers only `200 ok` or `503 fail`: `fail` when the database does not answer or a check of the dashboard's **Service Status** card is red (a server down, a failed migration, a full disk, an overdue backup, ...). Point an uptime monitor at it.
- **`/metrics`** answers [Prometheus text format](https://prometheus.io/docs/instrumenting/exposition_formats/) to the token of **Settings → API → Metrics Token** (generate one there; empty, `/metrics` answers 404). Send it as `Authorization: Bearer <token>`; it is not accepted in the URL, where browsers, proxies and tools keep it. A wrong or missing token answers 401.

```yaml
# prometheus.yml
scrape_configs:
  - job_name: xcvm
    scheme: http
    metrics_path: /metrics
    authorization:
      credentials: <the Metrics Token>
    static_configs:
      - targets: ['main.example.com:80']
```

| Metric | Labels | Value |
| --- | --- | --- |
| `xcvm_up` | | 1 |
| `xcvm_server_online` | `server`, `name` | 1 when the enabled server answers MAIN |
| `xcvm_server_cpu_percent`, `xcvm_server_memory_percent` | `server`, `name` | as the server last reported |
| `xcvm_server_disk_free_bytes`, `xcvm_server_disk_total_bytes` | `server`, `name` | the server's panel disk |
| `xcvm_server_network_bytes_per_second` | `server`, `name`, `direction` (`in`/`out`) | as the server last reported |
| `xcvm_server_connections` | `server`, `name` | viewer connections |
| `xcvm_server_streams` | `server`, `name`, `state` (`running`/`down`) | streams on the server |
| `xcvm_lines` | `state` (`active`/`expired`/`disabled`) | lines |
| `xcvm_last_run_age_seconds` | `job` (`root_cron`/`backup`/`cache`) | seconds since it last ran |
| `xcvm_status_check` | `check` (`servers`, `clock`, `schema`, `crons`, `fanout`, `cluster`, `disk`, `backups`, `certs`, `cache`) | -1 off, 0 ok, 1 warning, 2 failing |

With the cluster API on, the load balancers enrolled in it, as **Servers → Cluster Nodes** shows them:

| Metric | Labels | Value |
| --- | --- | --- |
| `xcvm_cluster_node` | `server`, `name`, `state` (`active`/`quarantined`/…), `health` (`ok`/`suspect`/`offline`/`unknown`), `mode` | 1 |
| `xcvm_cluster_node_last_seen_seconds` | `server`, `name` | seconds since MAIN last heard the node |
| `xcvm_cluster_node_clock_offset_seconds` | `server`, `name` | the node's clock less MAIN's |
| `xcvm_cluster_node_lane_lag_seconds` | `server`, `name`, `lane` (`p0`/`p1`) | how long the node's events on that lane have been delayed; 0 when they are not |
| `xcvm_cluster_node_unreachable_urls` | `server`, `name` | MAIN addresses the node cannot reach |
| `xcvm_cluster_node_streams_local` | `server`, `name` | 1 when the node reads its streams on itself, 0 when not (only nodes that report it) |
| `xcvm_cluster_node_commands_queued` | `server`, `name` | commands MAIN queued for the node and it has not acknowledged |
| `xcvm_cluster_command_latency_seconds` | `stage` (`deliver`/`ack`), `quantile` (`0.5`/`0.99`) | seconds from queueing a command to its delivery or acknowledgement, over the last window |

Both are computed at most every 15 seconds; a faster scrape gets the same answer. Per-stream metrics are left out: with thousands of streams they would swamp Prometheus.

## Alerts

**Alerts** (the profile menu, for admins with the *settings* permission) sends a message when something goes wrong on the panel and another when it is over. `cron:alerts` checks every minute on MAIN.

### Channels

Add one or more channels, then press **Test** on each: a test message must arrive.

- **Telegram**: create a bot with [@BotFather](https://t.me/BotFather) and paste its **Bot Token**. The **Chat ID** is where the alerts go: your own user ID (write to the bot once first), a group's ID (negative; add the bot to the group) or `@channelname` (make the bot an admin of the channel).
- **Webhook**: a JSON `POST` to the **URL** you give, for your own tools (a chat app's incoming webhook, an incident manager, a script). With a **Signing Secret**, each request carries `X-XCVM-Signature: sha256=<HMAC-SHA256 of the body with the secret>`; compare it before you trust the body. `X-XCVM-Event` names the event: `alert` here, `lines.expiring` for [expiry reminders](#expiry-reminders).

    ```json
    {"event": "alert", "state": "fired", "rule": "server_down", "title": "[Panel] A server is down (1)",
     "text": "- LB-2", "items": [{"subject": "2", "label": "LB-2"}], "panel": "Panel", "time": 1800000000}
    ```

    `state` is `fired`, `resolved` or `test`.
- **E-mail (SMTP)**: your mail server, its port and security (`SSL/TLS` on 465, `STARTTLS` on 587, none), the account, the sender and one or more recipients.

Secrets (the bot token, the signing secret, the SMTP password) are never shown again once saved; leave the field empty to keep the saved one.

### Rules

| Rule | Fires when | Default |
| --- | --- | --- |
| A server is down | an enabled server has not answered MAIN for 2 minutes | on |
| A stream is down | a stream has been down on an enabled server for 5 minutes | off |
| CPU use is high | a server's CPU stays at 90 % or more for 10 minutes | on |
| Memory use is high | a server's memory stays at 90 % or more for 10 minutes | on |
| Disk use is high | a server's disk is 90 % full or more | on |
| A Service Status check is failing | a check of the dashboard's **Service Status** card turns red (a server down is the first rule's) | on |
| A load balancer needs attention | for 5 minutes, a load balancer of the cluster API is quarantined, its events are delayed, it is in mode 2 but not reading its streams on itself, its clock is off, it cannot reach MAIN at an address, or its relay proxy is down (one message per load balancer and problem; one that stopped answering is the first rule's) | on |

The threshold and the minutes can be changed per rule, and each rule can go to some channels only (none chosen: every enabled channel). A subject that fired is not announced again for 15 minutes, so a server that flaps gives one message, not one a minute. The subjects of one rule that fire in the same minute go out as one message, which names 20 at most. A rule switched off sends no *resolved* message for what it reported.

**Latest Messages** lists what was sent, and how each channel answered.

## Expiry reminders

**Settings → General → Expiry Reminders** reminds subscribers, and their reseller, before a line ends. `cron:reminders` runs once a day on MAIN (09:00).

- **Days Before Expiry**: such as `7,3,1`. An enabled line that is not a trial gets one reminder at the smallest of these days its time left fits: a line two days from its end gets the 3-day reminder, and the 1-day one the next day. Renewing the line (a new expiry date) starts over.
- **E-mail the Owner**: each reseller or admin with an e-mail address on their profile gets one list of their lines that expire soon. It goes out through the first enabled e-mail channel of [Alerts](#alerts).
- **Message on MAG Devices**: the line's MAG device shows the **Subscriber Message** on screen.
- **Telegram to the Subscriber**: the bot of the Telegram channel you choose writes the **Subscriber Message** to the subscriber. See below.
- **Webhook for Billing**: every enabled webhook channel gets one `POST` listing the lines:

    ```json
    {"event": "lines.expiring", "lines": [{"id": 12, "username": "alice", "owner_id": 5, "exp_date": 1800172800, "days": 3}],
     "panel": "Panel", "time": 1800000000}
    ```

**Subscriber Message** may use `{date}` (the end date) and `{line}` (the line's username).

### Telegram

A bot can only write to someone who has written to it first, so the subscriber starts the conversation:

1. Choose the Telegram channel whose bot sends the reminders. It can be the alerts' own channel. A channel used only for reminders must still be enabled, so give the alert rules their channels to keep alerts off it.
2. Put the subscriber's Telegram username (`@name`) in the **Telegram** field of their line, MAG device or Enigma2 device. Resellers can fill it in too.
3. The subscriber opens the bot in Telegram and sends `/start`. `/stop` ends the reminders; `/start` again resumes them.

The bot answers `/start` the same way whoever sends it, so it does not tell anyone which usernames the panel knows. Only private chats are read: a `/start` in a group is ignored. `cron:alerts` reads the bot's messages every minute, so do not connect the same bot to another program that reads its updates.

## Off-site backups

The **Backups** page copies each backup [cron:backups](backup-strategy.md) makes, and each day's recovery bundle, to one or more **Off-site Copies** targets, as soon as it is made:

- **S3-compatible** storage: AWS S3, Backblaze B2, Wasabi, MinIO and others. Give the **Endpoint** (empty: AWS), the **Region**, the **Bucket**, an optional **Folder**, and an access key that may write, list and delete in it. Tick **Path-style URLs** for MinIO and other servers that do not serve the bucket as a host name.
- **SFTP**: a server, a user with a password or an RSA private key in PEM form, and an absolute **Folder**. The panel keeps the server's host key fingerprint the first time it connects and refuses any other key after; clear the fingerprint to accept a server whose key really changed.

**Keep** is how many of the newest backups, and apart from them of the newest bundles, the target keeps (`All`: none is deleted). Press **Test** after saving: it writes, lists and deletes a small file. An upload that fails is recorded beside the backup, and the dashboard's **Backups** row turns yellow.

### Recovery bundle

A database backup does not hold what a new MAIN needs besides: `config/config.ini` (the database credentials), the modules' configuration and, when the cluster API is set up, the cluster's keys (see [Cluster keys](backup-strategy.md#cluster-keys-main-replacement)). Set a **Passphrase** of 20 characters or more under **Recovery Bundle**, and `cron:backups` writes one bundle a day, `backups/recovery_<date>.bundle`, encrypted with it (Argon2id and XSalsa20-Poly1305), keeps the last 7 and copies it to the targets.

The passphrase is kept in `config/backup_bundle.pass`, outside the database, so no backup holds it. **Keep a copy of it off this machine**: without it the bundles cannot be opened. To open one on the new MAIN:

```bash
sudo /home/xc_vm/console.php backup:open-bundle recovery_2026-10-07_04-00-00.bundle /root/restore < passphrase.txt
```

The passphrase is read from standard input, so it is in no process list or shell history. The directory must not exist; it is created with the bundle's files. A cluster key export in it is imported with `cluster:import-keys` and the same passphrase.

### Restore test

Every Sunday at 05:00 `cron:backup_verify` restores the newest backup into the scratch database `xc_vm_migrate`, checks that its tables and settings came back, and empties the scratch database again. **Run now** starts it at once. The result shows on the Backups page, and a failed test turns the dashboard's **Backups** row yellow. While `xc_vm_migrate` holds tables (a [migration](backup-strategy.md#tools-migration) in progress), the test is skipped and leaves them alone.
