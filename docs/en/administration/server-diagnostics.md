# Server Diagnostics (`server:diagnose`)

The panel marks a proxy/LB node **offline** purely from a stale heartbeat (`enabled` + `status = 1` + a recent `last_check_ago`), which never tells you *why* the node stopped reporting. The `server:diagnose` command answers that question.

```bash
/home/xc_vm/console.php server:diagnose [server_id]
```

The command is **read-only**: it only runs ping/curl/`fsockopen` probes, `SELECT` queries, and `sudo -n iptables -nL`. It never restarts or reconfigures anything.

Implemented by `src/Cli/Commands/ServerDiagnoseCommand.php`. The command ships in **both** MAIN and LB builds — the local mode is the whole point of having it on the node.

---

## Two Modes

The mode is auto-selected from where the command runs (`server_id` in `config.ini` → `is_main` in the `servers` table):

### Mode A — remote probe from the MAIN

```bash
sudo /home/xc_vm/console.php server:diagnose <server_id>
```

Probes the target node from the outside and reads its panel-side state:

| Check | What it tells you |
| --- | --- |
| Enabled / Status / Heartbeat | How the panel currently sees the node (`status = 4` means a failed install/provision) |
| ICMP ping | Is the host alive at all |
| TCP to `http_broadcast_port` | Is nginx reachable, or is the port dropped |
| HTTP `GET /api` | Does PHP actually answer behind nginx |
| Clock offset | `time_offset` vs the panel (skew > 30 s can flap the node online/offline) |
| Signal queue | Unconsumed rows in `signals` for this node (backlog > 120 s = the node's callback loop is stuck) |

The probe combinations map to causes:

- **No ICMP + port closed** — node powered off, network-partitioned, or fully firewalled.
- **ICMP replies but the port is dropped** — the classic: the node's own iptables blocked the main's IP (RootSignals flood/block false-positive), or nginx/the service is down. Run the local mode on the node for the exact cause.
- **Port open but `/api` silent** — nginx is up, PHP is not: check php-fpm on the node.
- **`/api` answers but the heartbeat is stale** — the node reaches the main but its `cron:servers` is not running or cannot write to the panel DB.

### Mode B — local self-diagnosis ON the node

```bash
sudo /home/xc_vm/console.php server:diagnose
```

Run this **on the silent LB/proxy node itself** — the causes usually live there. No argument needed; the node identifies itself from `config.ini`. Checks:

1. **My own panel row** — enabled/status/heartbeat as the main sees them.
2. **Can I reach the MAIN** — DB connectivity (implicitly proven), ICMP, and TCP to the main's broadcast port.
3. **Did I firewall the main?** — scans this node's `iptables INPUT` chain for a `DROP` of the main's IP, plus the flood-block marker file. This is the classic "node went silent for no reason" cause: the flood protection drops public IPs silently, and the main's callbacks stop landing.
4. **Service / nginx** — `systemctl is-active xc_vm` and a local TCP check on the node's own broadcast port.
5. **Reporting cron** — is `cron:servers` present in the crontab (it refreshes the heartbeat the main watches; if missing, re-run `console.php startup`).
6. **Clock skew** — `time_offset` vs the panel.

> **Note:** the iptables check requires passwordless sudo (`sudo -n`). Without it the check reports `cannot check (need sudo iptables)` instead of failing — run the command as `root` for a full picture.

---

## Output & Exit Codes

Each check prints one aligned `[OK]`/`[WARN]` line, followed by a numbered **Probable cause(s)** summary with the exact fix command where one exists (e.g. the `iptables -D INPUT ... -j DROP` unblock line).

| Exit code | Meaning |
| --- | --- |
| `0` | No obvious cause found (or the target is the MAIN itself — nothing to diagnose) |
| `1` | Usage error: missing/unknown `server_id` |
| `2` | One or more probable causes were found and printed |

Example (local mode, self-blocked main):

```text
Self-diagnosis on node #3 — LB-Frankfurt (type 1)
----------------------------------------------------------------
[OK]   Enabled          yes
[OK]   Status           1 (online)
[WARN] Heartbeat        last check-in 641s ago (limit 180s)
[OK]   DB → main        reachable (this query ran)
[OK]   Ping main        reply (203.0.113.10)
[WARN] Main :8080       closed/timeout
[WARN] Main in iptables DROP present (+flood marker)
[OK]   Service xc_vm    active
[OK]   nginx :8080      listening
[OK]   cron:servers     in crontab
[OK]   Clock offset     2s vs panel
----------------------------------------------------------------
Probable cause(s):
  1. Heartbeat is stale (641s > 180s): the node stopped reporting — the checks below narrow down why.
  2. This node has DROPPED the main's IP 203.0.113.10 in its own iptables (flood/block false-positive). Unblock: `sudo iptables -D INPUT -s 203.0.113.10 -j DROP && sudo rm -f /home/xc_vm/tmp/flood/block_203.0.113.10`.
```

---

## Quick Reference — Common Causes

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Pings, port dropped | Node's iptables blocked the main's IP | `sudo iptables -D INPUT -s <main_ip> -j DROP` + remove the `block_<ip>` marker (the command prints the exact line) |
| No ping, no port | Host down / network partition / external firewall | Check hosting console, routes, provider firewall |
| Port open, `/api` dead | php-fpm down | Restart the `xc_vm` service on the node |
| `/api` fine, heartbeat stale | `cron:servers` missing or DB write failure | `console.php startup` on the node; check DB grants (`tools mysql` on the main) |
| Node flaps online/offline | Clock skew > 30 s | Sync NTP on the node |
| Status = 4 | Install/provision errored | Re-run `server:install` from the main |

---

## Related files

| File | Role |
| --- | --- |
| `src/Cli/Commands/ServerDiagnoseCommand.php` | The diagnostic command (both modes) |
| `src/Cli/CronJobs/ServersCronJob.php` | The heartbeat this command reasons about |
| `src/Cli/CronJobs/RootSignalsCronJob.php` | Applies iptables blocks (the false-positive source) |
| `src/Domain/Server/ServerRepository.php` | `servers` table access |

See also: [CLI Tools](en-us/guides/cli-tools.md), [Updating a Server](en-us/administration/server-update.md).
