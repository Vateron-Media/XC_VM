# MAIN and Load Balancers: the New Cluster

XC_VM can spread your viewers and streams over several servers. One server is **MAIN**: it runs the
admin panel and the database. The others are **load balancers** (LBs): they run streams and serve
viewers. This page explains how MAIN and its load balancers now work together, what is better than
before, and how to set it up.

You do not need to know how it works inside. If you want the technical details, see
[Cluster API (MAIN ↔ LB)](../development/cluster-api.md) in the Developer Guide.

## The idea in one minute

**Before**, a load balancer was given MAIN's database password. It logged straight into MAIN's
MySQL and Redis to read settings, lines and streams, and to write its stats and viewers. Every
load balancer held the keys to the whole panel.

**Now**, each load balancer runs a small program called the **agent** (`xc_agent`). The agent
talks to MAIN over one secure channel. The agent always starts the conversation: MAIN never
connects to the load balancer, it waits for the agent to ask. Every message is signed and encrypted, even over plain HTTP. The load balancer no longer
needs MAIN's database at all. In the end you can remove the database password from it completely.

```text
 BEFORE                                   NOW

  ┌──────────┐  MySQL 3306 + Redis 6379    ┌──────────┐   one secure channel   ┌──────────┐
  │   LB     │ ──────────────────────────► │   MAIN   │ ◄───────────────────── │   LB     │
  │ (has the │   panel database password   │          │   (MAIN's normal HTTP  │  agent   │
  │ DB pass) │ ◄────── MAIN's /api ─────── │          │    port, signed and    │ (own key)│
  └──────────┘   (shared stream password)  └──────────┘    encrypted)          └──────────┘
```

## Old versus new

| | Before (legacy load balancer) | Now (cluster) |
| --- | --- | --- |
| **How the LB talks to MAIN** | Logs into MAIN's MySQL and Redis directly | Its agent talks to MAIN through MAIN's normal web port |
| **What the LB stores** | MAIN's database and Redis passwords | Its own private keys and an access token that renews by itself (hourly by default) |
| **If someone steals an LB** | They get the password to your whole panel database | They get only that LB's identity, which you can revoke in one click |
| **Ports open on MAIN** | MySQL (3306) and Redis (6379) open to every LB | Only MAIN's web port; MySQL and Redis can be firewalled or closed |
| **Status in the panel** | The LB wrote its own stats into MAIN's database | The agent reports every 2 seconds; MAIN shows an LB as offline after 30 seconds of silence |
| **Viewer links** | One shared secret signs links for every server | Each LB gets its own key (Config flow), so a link made for one LB does not work on another |
| **Relays between servers** | The stream password travels in the URL | Signed tickets instead of the password (Data plane flow), and the stream bytes are sealed where both servers support it |
| **Proxies** | Trusted by their IP address | A proxy installed with the current proxy release signs what it sends with its own key |
| **Movies and timeshift** | One PHP process per viewer | Served by the streaming daemon (`xc_fanout`), which uses far less memory |
| **LB software updates** | Came from MAIN | Every server downloads checked releases from GitHub by itself, hourly |
| **Switching over** | All or nothing | Per LB and feature by feature; every step can be undone except removing the database password from an LB |

## What you get

**Better security**

- **No database password on the load balancers.** Once a load balancer runs fully on the cluster,
  MAIN's database and Redis credentials can be removed from it (*Drop DB credentials*).
- **Every LB has its own identity.** It is created on the LB itself during install, and its
  private keys never leave the server. Its access token expires and renews by itself (every
  60 minutes by default).
- **One-click controls** on the *Cluster Nodes* page: rotate a token, fence (stop) an LB, put it in
  quarantine, or revoke it.
- **Per-LB viewer keys.** With the Config flow on, a viewer link made for one load balancer cannot
  be reused on another.
- **Signed relays and file transfers** between your servers (Data plane flow), so the stream
  password is no longer needed in URLs. Where both servers support it, the bytes are sealed too.
- **Signed proxies.** A proxy installed (or reinstalled) with the current proxy release signs its
  reports. Once it has signed one, MAIN no longer accepts unsigned reports from its address.
- **Safer TLS.** A server that still uses the old built-in TLS key gets its own at startup, and
  only TLS 1.2 and 1.3 are allowed.

**More reliable**

- **MAIN outages hurt less.** With the Config flow on, each load balancer keeps a local copy of
  what it needs (settings, servers, stream list). If MAIN is unreachable, viewers already watching keep watching and the
  LB's streams keep running. What the LB has to tell MAIN waits on its disk and is sent when MAIN
  is back.
- **Faster, clearer status.** The *Cluster Nodes* page shows each LB's state, version, clock and
  warnings, such as a clock that is wrong, connections that do not match, or reports that are
  piling up.

**Less work**

- **Automatic enrolment.** Installing a load balancer from the panel also joins it to the cluster.
- **Automatic updates.** Every server checks GitHub hourly and installs new verified releases of
  the agent, the streaming daemon and the `xcvm_core` extension. MAIN no longer hands them out.
- **Lighter servers.** Movies and timeshift are streamed by the daemon instead of PHP, so a busy
  load balancer needs far fewer PHP workers.

## Before you start

- **A licence.** MAIN signs every load balancer's tokens with your licence. Without a valid one,
  no new load balancer can join.
- **The `xcvm_core` extension with the cluster API.** Check it under **Settings → Cluster**: the
  box at the top shows *Extension: xcvm_core … (API 1)*.
- **Supported systems** for load balancers: Ubuntu 20.04, 22.04 or 24.04, or Debian 12 or 13.
- **Network:**
  - each load balancer must reach MAIN's HTTP port (or the *Cluster API Port* you choose);
  - each server needs outbound HTTPS to GitHub to download its updates;
  - MAIN needs SSH access to a new load balancer for the install. A server behind NAT can join
    with a code instead (see below).
- **Correct time** on every server (NTP). The panel warns when a server's clock is more than
  30 seconds off.

## Setting it up

### Step 1: turn the cluster on (MAIN)

1. Open **Settings → Cluster**.
2. Switch on **Enable LB API** and click **Save**.

Leave the other settings at their defaults. You can come back to them later:

| Setting | Default | When to change it |
| --- | --- | --- |
| Cluster API Port | 0 (use MAIN's HTTP port) | If you want the cluster on its own port |
| MAIN Host Name | empty | Set a DNS name if MAIN's IP address may change |
| Cluster Transport | auto | `auto` already uses HTTPS when MAIN has a valid certificate |
| Offline Admission | local | What an LB does with new viewers while it cannot reach MAIN: `local` applies the line's connection limit itself, `allow` lets them in, `deny` refuses them |

### Step 2: add a load balancer

1. Open **Servers → Install Load Balancer**.
2. Fill in the server name, its IP address, and SSH login (user, password, port), as before.
3. Optionally, paste the server's **SSH host key fingerprint**. The panel then refuses to install
   if it sees a different server (protection against a fake server).
   The key seen at a server's first install is saved, and every later install must match it. If you
   reinstalled the server's operating system, it has a new key: on the reinstall form, switch on
   **Forget the Saved SSH Host Key** (or paste the new fingerprint). That install then trusts the
   key the server presents and saves it.
4. Start the install and follow its progress on the **Server View** page.

At the end of the install, the new load balancer installs its agent from GitHub, creates its own
keys, checks that it can reach MAIN, and joins the cluster. Open **Servers → Cluster Nodes**: it
appears there as **active**.

!!! note "A load balancer that is already installed, or that MAIN cannot reach over SSH (NAT)"
    The server must already be in **Servers → Manage Servers**.

    1. Make sure the load balancer is updated to the current release.
    2. On **Servers → Cluster Nodes**, use **Enrol by code**: choose the server and click **Issue
       code**. If the LB must reach MAIN at another address (for example a private IP), type it in
       the *MAIN URL* field first. The page shows a command once.
    3. Run that command on the load balancer as root. It prints a short check code (SAS).
    4. Type the SAS into the request waiting on **Cluster Nodes** and click **Approve**.

    A code is valid for 30 minutes and can be used once.

### Step 3: move work to the cluster, one feature at a time

A new load balancer joins in **mode 1** with every feature switched off. It is connected and
reporting, but it still works the old way. You then switch its features (**flows**) on, one by
one, on the **Cluster Nodes** page. Each switch moves one kind of work from "the LB uses MAIN's
database" to "the agent does it through the secure channel".

Switch them on in this order. After each one, watch the LB for a while (its streams, its viewers,
the warnings on Cluster Nodes) before going on:

| Order | Flow | What changes when it is on |
| --- | --- | --- |
| 1 | **Telemetry** | MAIN takes the LB's CPU, memory, network and stats from its agent |
| 2 | **Commands** | Start/stop, restarts and viewer kills reach the LB as signed commands |
| 3 | **Logs** | The LB's logs reach MAIN through the agent |
| 4 | **Streams** | The LB reports its streams' status (running, stopped, codecs) through the agent |
| 5 | **Content** | Recordings and movie analysis reach MAIN through the agent |
| 6 | **Config** | The LB reads its settings, servers and stream list from its local copy, not from MAIN's database. It also gets its own viewer key |
| 7 | **Connections** | The LB keeps its viewer list itself, and the agent mirrors it to MAIN. Needs Commands and Streams |
| 8 | **Data plane** | Relays and files from other servers go through the agent with signed tickets instead of the stream password. Needs Streams and Content |

Every flow can be switched off again: the load balancer then goes back to the old way for that
part. Before switching **Connections** on, load the LB's current viewers into its agent with one
command on MAIN:

```bash
sudo -u xc_vm /home/xc_vm/console.php cluster:seed-connections <server id>
```

### Step 4: full cluster mode (mode 2)

**Mode 2** means the load balancer no longer touches MAIN's database at all. The page only lets you
press **Mode up** when it is safe:

- every flow is on, Data plane included;
- the *Root pin* badge is shown, so updates and restarts can reach the LB as signed commands;
- the LB has reported **seven days in a row** with no connection to MAIN's MySQL or Redis. The
  *MAIN DB connects* box on Cluster Nodes shows what still connects, if anything.

**Mode down** is always allowed. It is your way back if something misbehaves.

### Step 5 (optional): lock everything down

Only when every load balancer is in mode 2:

1. **Drop DB credentials** (Cluster Nodes, per LB) removes MAIN's database and Redis passwords
   from the load balancer, and MAIN cancels its database access. This step cannot be undone from
   the panel.
2. **DB Allowlist** (Settings → Cluster) firewalls MAIN's MySQL and Redis so that only MAIN, and
   servers that still need them, can connect.
3. **Proxies:** reinstall each proxy from **Servers → Manage Proxies**, so it gets its own key and
   signs its reports.
4. Last, an administrator can close MySQL and Redis to the network entirely by running
   `/home/xc_vm/console.php cluster:lockdown` as root on MAIN. It refuses while any LB is below
   mode 2 or any proxy has not signed yet. `cluster:lockdown --undo` reverses it.

## Everyday use: the Cluster Nodes page

**Servers → Cluster Nodes** is where you watch and control your load balancers:

| You see | It means |
| --- | --- |
| **State** active / quarantined | Normal / frozen until you click *Trust again* |
| **Mode** 0, 1, 2 | Old way / mixed / fully on the cluster |
| **Last seen**, **Agent** | When the LB last reported, and its agent version |
| **Token expires** | When its current access token runs out. It renews by itself |
| **clock …** | The LB's clock is off; fix its time (NTP) |
| **connections differ** | MAIN and the LB disagree about who is watching; they re-sync by themselves |
| **P0 / P1 lagging** | Reports are waiting to reach MAIN. Check the network between them |
| **MAIN URL unreachable** | The LB cannot reach one of MAIN's addresses. Check the firewall or certificate |
| **relay port down** | Another program uses port 31290 on the LB; free it |

The buttons:

| Button | What it does |
| --- | --- |
| **Rotate token** / **Rotate all tokens now** | Issues a new token, for example if you think one leaked. The LB keeps working |
| **Fence** / **Unfence** | Stops new viewers on that LB at once, and drops the rest after a short drain. Unfence puts it back on air |
| **Quarantine** / **Trust again** | Freezes the LB's trust: it keeps serving but takes no new instructions. MAIN also does this by itself if it suspects a cloned server |
| **Resync** | The LB fetches its copy of the configuration and its viewer list again from scratch |
| **Revoke** | Removes the LB from the cluster. Its tokens stop working at once; it must join again |
| **Mode up** / **Mode down** | Moves the LB between modes 0, 1 and 2 |

## When MAIN is unreachable

- Viewers already watching on a load balancer keep watching, and its streams keep running.
- Everything the LB has to report waits on its disk and is sent when MAIN is back.
- **Offline Admission** (Settings → Cluster) decides what happens to viewers the LB cannot check
  with MAIN.
- By default a load balancer never stops on its own because it cannot reach MAIN. If you turn on
  **Lease Fence** in Settings → Cluster, an LB that has not heard from MAIN for too long stops
  serving. It refuses new viewers once its token has expired plus the **Partition Tolerance**
  (12 hours by default, never more than 26 hours after its last token), and drops the remaining
  viewers after the **Fence Drain** (10 minutes by default). Turn it on before you ever need it,
  because it reaches the LBs only while your licence is valid.

## Common questions

**Do I have to switch everything over at once?**
No. Each load balancer and each flow is switched separately, and you can mix old and new load
balancers in the same panel.

**Do I need HTTPS?**
No. Every message is signed and encrypted even over plain HTTP. If MAIN has a valid certificate,
the default *auto* transport uses HTTPS anyway.

**What happens if I change MAIN's IP address or port?**
MAIN tells the load balancers the new address and keeps answering on the old one for seven days,
so none of them gets lost. Setting a *MAIN Host Name* (a DNS name) avoids the problem altogether.

**A load balancer shows "relay port down". What do I do?**
Another program on that load balancer uses port 31290, which the agent needs. Stop that program,
and the agent takes the port by itself.

**The install says the licence does not allow it (`CLUSTER_LICENCE_REQUIRED`).**
MAIN can only let new load balancers join with a valid licence. Check
[Licensing & Activation](../info/licensing-and-activation.md).

**A new load balancer stayed in the old mode after install.**
It could not download its agent from GitHub, or the cluster was not switched on yet. Check that
the server can reach GitHub over HTTPS, then join it with **Enrol by code**.
