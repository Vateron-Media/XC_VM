# Core Extension (xcvm_core): What It Does, Stores, Sends — and What It Doesn't

This page is written for XC_VM users and administrators. The core (`xcvm_core.so`)
ships as a compiled binary without source code, and that is a legitimate reason to
ask questions. So this page describes **its entire behaviour**: what happens at
load time, which files it creates, every action it can perform, every network
request and exactly what each one carries, how licensing works and what precisely
happens when a licence is revoked — plus what the core does **not** do, and how to
**verify every claim yourself** with standard Linux tools, without the sources.

The page is self-contained: it does not refer you anywhere else.

---

## 1. In short: what it is

`xcvm_core` is a native PHP extension (written in Rust) that loads into the PHP
processes of the XC_VM panel and registers **one** class, `XC_VM`, with static
methods. It adds no global functions and installs no hooks into PHP code
execution.

It solves exactly three problems:

| Problem | What it does |
|---------|--------------|
| **Config, DB, Redis** | Keeps the panel settings in an encrypted, machine-bound `config.enc` and hands PHP ready-made MySQL/Redis connections so that passwords never appear in PHP code |
| **Modules** | Downloads modules from the store, strips the transport encryption and leaves IonCube-encoded files on disk, which the stock ionCube Loader executes |
| **Licence and cluster** | Checks the installation's licence and lets the MAIN server hand LB nodes configs and tokens without passing passwords around |

It is **not**: a backdoor, a remote-control channel, a telemetry collector, a way
to exfiltrate your data, or a mechanism for executing code sent from outside. The
details for each of those claims follow.

---

## 2. Why the core is a binary

The panel's PHP code is open and readable by anyone with access to the server. If
database passwords, encryption keys or paid-module keys lived in it, they would go
to the first person who opened the file. So exactly those things are moved into
compiled code:

1. **DB and Redis passwords** never appear in PHP: the core reads them from
   `config.enc` itself and opens the connection itself.
2. **Module keys** never appear in PHP: the core fetches them from the server
   itself, decrypts the files at install time itself, and stores the key encrypted.
3. **Licence checks** cannot be commented out by editing PHP.

That is the whole point of the binary being closed. Its behaviour is not secret —
it is described here.

---

## 3. What happens when PHP loads

At the start of every PHP process (php-fpm, CLI) the core initialises. This is the
complete list of what happens then — and nothing else:

1. **Debugger check.** The file `/proc/self/status` is read. If the process is
   being traced right now (`TracerPid` is non-zero — for example gdb is attached),
   the core **refuses to load**. Nothing else happens: nothing is deleted, nothing
   is sent anywhere, the server is not stopped — the `XC_VM` class is simply
   unavailable in that process.
2. **Registration of the ini directive** `xcvm_core.server_url` (see section 8.4).
3. **ionCube Loader presence check.** If it is not loaded, an ordinary PHP warning
   is emitted. It is only a message — the core keeps going.
4. **Installation identifier.** If the `install_id` file does not exist yet, a
   random UUID is generated and written to the config directory with `0600`
   permissions. If it exists, it is read.
5. **Automatic config migration.** If an old plaintext `config.ini` is in the config
   directory and `config.enc` is not, the ini is read, encrypted into `config.enc`,
   and `config.ini` is then **deleted** (so plaintext passwords do not stay on
   disk). If `config.enc` already exists, nothing happens. If neither exists,
   nothing happens.

**The core performs no network requests at load time.** Every request to the
server happens only inside calls to the methods listed in section 5.

The core creates no background threads, timers, daemons or cron jobs. Everything it
does happens inside a method call the panel made, and ends with that call.

---

## 4. What the core keeps on your disk

All per-installation secrets live in the **config directory** — by default
`/home/xc_vm/config` (permissions `0700`). The path can be overridden with the
`XCVM_CONFIG_DIR` environment variable — the **only** environment variable the
core reads.

| File | What it is | Mode |
|------|-----------|------|
| `config.enc` | Panel settings (DB, Redis, server role), AES-256-GCM encrypted. The key is derived from `install_id` **and** `/etc/machine-id`, so a copy on another machine does not decrypt | `0600` |
| `install_id` | The random UUID of your installation. Created once and never changes (survives core and PHP upgrades) | `0600` |
| `panel.pk` / `panel.sk` | The panel's X25519 key pair. The public half is sent to the server at registration; **the private half never leaves the machine** and is stored sealed to this machine's key | sk: `0600` |
| `module_keys/<module>.key` | The transport-encryption key of each installed paid module, itself encrypted under the installation's master key | `0600` |
| `activation_key` | The licence activation key (you paste it in the panel). Signed by the server, bound to your `install_id` | `0600` |
| `license_verdict` | The cache of the server's last signed answer about the licence state | `0600` |
| `license_checkin` | A timestamp for when the next daily check-in may happen (see section 7) | — |
| `license_enforce_ts` / `license_revoked` | Timestamps of the last enforcement of a revoked licence, and the revocation mark itself (see section 7.4) | — |
| `cluster/` | Cluster files: MAIN's root keys (`root.enc`), MAIN's pinned keys on an LB (`core.pin`), node revocation state (`nodes.state`), protected clocks (`clock.sealed`, `timeanchor`), LB leases (`lease`). All encrypted under this machine's key | `0600` |

Outside the config directory the core writes:

- to `/home/xc_vm/Modules/<module>/` — the files of installed modules (the path is
  fixed);
- a temporary file in `/tmp` with `0600` permissions — the connection options for
  `mysqldump`/`mysql` during a DB dump/restore; the file is **removed right after
  the operation**.

What the core does **not** write: anything outside those paths, hidden files, or
changes to `php.ini`, cron, systemd or autostart.

---

## 5. The complete list of the core's actions

Below are **all** methods of the `XC_VM` class. For each: what it does, what it
touches on disk, in the DB and on the network. A "—" in the Network column means the
method makes no network request. No method runs on its own — only when the panel
calls it.

### 5.1. Installation identity

| Method | What it does | Disk | Network |
|--------|-------------|------|---------|
| `install_id()` | Returns the installation UUID (creates it on first call) | reads/creates `install_id` | — |
| `env_fingerprint()` | Returns the hash `SHA-256(install_id + /etc/machine-id + hostname)`. It is a **hash**: the hostname or machine-id cannot be recovered from it | reads `/etc/machine-id`, hostname | — |

### 5.2. Panel config

| Method | What it does | Disk | Network |
|--------|-------------|------|---------|
| `config_init(data)` | Writes `config.enc` at panel install time (DB, Redis, server role). Deletes the old plaintext `config.ini` afterwards | writes `config.enc` | — |
| `config_set_redis(host, port, auth)` | Updates **only** the Redis section of `config.enc` (needed when the Redis password changes) | writes `config.enc` | — |
| `config_server()` | Returns the `server` section (server id, whether it is an LB). Does **not** return passwords | reads `config.enc` | — |
| `config_set_db(pass)` | On an LB node: replaces the DB password in its `config.enc` (the node's half of a password rotation) | writes `config.enc` | — |

Every write of `config.enc` is atomic: a temporary file is written, then renamed
over the old one. A failure at any point leaves either the old file or the new one,
never an empty or corrupted one.

### 5.3. Database and Redis

All methods read the credentials from `config.enc` inside the core; **they never
reach PHP**.

| Method | What it does | Disk | Network |
|--------|-------------|------|---------|
| `db_connect()` | Returns a ready PDO connection to your DB | reads `config.enc` | your DB only |
| `redis_connect()` | Returns a ready Redis connection | reads `config.enc` | your Redis only |
| `db_dump(file, ignore_tables)` | Dumps the DB via `mysqldump` (structure, then data) into the given file | writes the given file; temporary `.cnf` in `/tmp`, removed afterwards | your DB only |
| `db_restore(file, dbname)` | **Drops and recreates** the target DB, then imports the file. A destructive operation, but it runs **only on an explicit call** from the panel (restore from backup) | temporary `.cnf` in `/tmp`, removed afterwards | your DB only |
| `db_grant(host)` | Grants the panel's DB user privileges when connecting from the given address (for an LB node) | — | your DB only |
| `db_revoke(host)` | Revokes privileges for the given address. **Refuses** to revoke addresses MAIN itself may connect through (`localhost`, loopback, the DB host from the config) — so MAIN is never cut off from its own database | — | your DB only |
| `db_set_password(new)` | On MAIN: changes the panel user's password in the DB, then in `config.enc`, then on the nodes' grants. If the config write fails, the DB password is rolled back | writes `config.enc` | your DB only |

The address in `db_grant`/`db_revoke` is strictly validated (only characters legal
in a MySQL host), and values are quoted via PDO — SQL injection through these
methods is not possible.

### 5.4. Modules: store, installation, module licence

| Method | What it does | Disk | Network |
|--------|-------------|------|---------|
| `panel_register(api_key)` | Registers the panel with the server: creates (or reads) the panel key pair and sends `install_id`, the **public** key, `env_fp` and `hw_id` | creates `panel.pk`/`panel.sk` | `panel/register` |
| `plugins_check(plugin, install_id, api_key)` | Asks the server whether a module is available for installation | — | `plugins/check` |
| `plugins_token(plugin, install_id, api_key)` | Obtains a short-lived (5-minute) module access token from the server | — | `plugins/token` |
| `extensions_list(env, api_key, options)` | Returns the store catalogue (paged, with search and sorting) | — | `extensions` |
| `extensions_check_update(list, api_key)` | Asks whether updates exist for a list of installed modules | — | `extensions/check-update` |
| `module_install(slug, version, api_key)` | The full module installation cycle (see below) | writes `Modules/<slug>/`, `module_keys/<slug>.key` | `plugins/token`, `plugins/key`, `plugins/download` |
| `module_license(slug, server_data, api_key)` | Requests an ionCube `.lic` licence file for a module, bound to your machine | — (the panel writes the file) | `plugins/token`, `plugins/license` |

**How a module is installed (`module_install`)**, step by step:

1. A module access token is requested from the server. The server checks whether
   the owner of the API key is entitled to this module. For a **free** module
   neither an API key nor panel registration is needed.
2. (Paid modules only.) The module's transport-encryption key is requested from the
   server. It arrives sealed to your panel's **public** key, so only your panel can
   open it with its private key.
3. The key is saved to `module_keys/<slug>.key` — encrypted under the installation's
   master key.
4. The module archive (`tar.gz`) is downloaded. The server's response arrives inside
   a sealed envelope (see section 6.1), and the archive is **extracted only if the
   envelope opens** — i.e. it is proven to come from the server and to answer this
   very request. Any failure at this step = no installation, nothing is written to
   disk.
5. The archive is extracted into `/home/xc_vm/Modules/<slug>/`.
6. (Paid modules only.) Every `*.xcvm` file is decrypted into `*.php` **once,
   directly on disk**. Inside is IonCube-encoded PHP code.

After that the core **has nothing to do with the module**: the files on disk are
ordinary IonCube-encoded `.php` files, executed by the stock ionCube Loader through
PHP's normal mechanism. The core decrypts nothing per request, intercepts no PHP
compilation, and executes no code.

What is in a module archive: `module.json` (the manifest, **not** encrypted — the
panel reads it), and the module's files (`*.xcvm` for paid ones, `*.php` for free
ones).

**Module licence (`module_license`).** An optional step for paid modules. The panel
passes the core a machine fingerprint produced by ionCube itself
(`ioncube_server_data()`); the core sends it to the server; the server mints a
`.lic` file bound to this machine with a limited lifetime. The file goes next to the
module; without a matching `.lic` the ionCube Loader does not decode the module's
files. If module licensing is disabled on the server or there is no entitlement,
the panel simply writes no `.lic`.

### 5.5. Installation licence and attribution

Both methods work **without a network call** at the moment of the call (with one
caveat about the daily check-in, detailed in section 7).

| Method | What it does | Disk | Network |
|--------|-------------|------|---------|
| `license_valid()` | Answers whether this installation may use licensed features (for example fanout and cluster token minting). Details in section 7 | reads `activation_key`, `license_verdict`, the attribution files | at most once a day — `activation/status` / `activation/checkin` (section 7.3) |
| `verify_branding()` | Answers whether the panel's attribution is in place (see section 7.2) | reads 6 panel files | — |

### 5.6. Multiserver: MAIN ↔ LB nodes

These methods let the MAIN server hand LB/proxy nodes a config and tokens without
passing the DB password through PHP or leaving it on the nodes in the clear.

| Method | Where it runs | What it does |
|--------|--------------|-------------|
| `config_pack(target_install_id, params, lb_panel_pk)` | MAIN | Builds a transport blob with a config for a node: reads the DB password from its own `config.enc` **inside the core**. With the node's panel key (XCVT v2) it signs the blob with MAIN's cluster root and seals it to that key; without one (v1, the old format) it encrypts it under a key derived from the node's `install_id`. Can build a config **without** DB credentials (for nodes in API mode). The panel packs every node config in one place, v2 for every load balancer it pinned with v2 ([XCVT v2](../administration/main-lb-cluster.md#signed-sealed-load-balancer-configs-xcvt-v2)) |
| `panel_pk()` | node | The node's panel X25519 public key, the key MAIN seals a v2 config to. Creates `panel.sk` only when there is none; never replaces one |
| `install_config(blob)` | node, as root | Verifies the blob (addressed to this node, at most an hour old, for an LB, for the right `server_id`) and writes it as its `config.enc`. **Never overwrites** a MAIN config. Writes nothing on refusal |
| `strip_db_credentials()` | node, as root | Removes MAIN's DB user and password from the node's `config.enc` (switching the node to API mode). Refuses to run on MAIN |
| `cluster_info()` | any | Cluster API version, panel public keys, licence and clock state. Never refuses |
| `cluster_init()` | MAIN | Creates the cluster root keys once (`cluster/root.enc`). Calling it again changes nothing |
| `cluster_token_issue(params)` | MAIN | Mints a token for an LB node. **Requires a valid licence** |
| `cluster_session(record, node, hard)` | MAIN | Returns the current epoch's session keys for verifying a node's requests |
| `cluster_node_gen(node, gen, min_epoch)` | MAIN | Revokes a node (raises its "generation"); previously issued tokens stop working. Survives a DB restore from backup |
| `cluster_sign` / `cluster_verify` / `cluster_record_class` | MAIN / LB | Signing and verifying cluster records (commands, denials, replicas). "Granting" records require a licence; "restricting" ones (stop, ban) are signed even without one — so an unlicensed MAIN can still stop its fleet |
| `cluster_lease_issue` / `cluster_lease_verify` / `cluster_lease_store` / `cluster_lease_state` | MAIN → LB | A "lease" — MAIN-signed permission for an LB node to keep serving while MAIN is unreachable. On an LB, `license_valid()` follows the lease |
| `cluster_pack` / `cluster_pin` / `cluster_pinned` | MAIN → LB | Delivering and pinning MAIN's public keys on an LB (trust on first use; a later key substitution is refused). Version 1 needs a licence; version 2 is signed with MAIN's cluster root and needs none. A pinned LB accepts only v2 configs signed by the MAIN it pinned |
| `cluster_seal_local` / `cluster_open_local` | any | Seal/open data to **this** machine's key — for the PHP panel to keep its own secrets |
| `cluster_open_sealed(purpose, sealed)` | MAIN | Open a message addressed to the panel key (only for the node enrolment and re-keying stages) |
| `cluster_export_keys(passphrase)` / `cluster_import_keys(bundle, passphrase)` | old → new MAIN | A backup of the cluster root keys for moving MAIN to new hardware. Encrypted from your passphrase via Argon2id (1 GiB of memory, a few seconds) — hence available **from the CLI only** |
| `cluster_kek()` | LB | A machine-bound key for encrypting the node's key file |
| `cluster_last_error()` | any | The reason code of the last cluster call's refusal |

What the core guarantees in the cluster regardless of what PHP does: private keys
and token material never leave the core; node revocation survives a DB restore;
turning the clock back (on MAIN or on an LB) extends nothing; a node switched to
credential-free API mode does not get credentials back without an explicit
administrator action.

---

## 6. Network: every request and what it carries

The core talks to **only one external address** — the store and licence server
`https://www.xcvm.tech` (every path under `/api/v1/`). It connects nowhere else,
apart from **your** DB and **your** Redis at the addresses from your own config.

### 6.1. How every request is built

Every request to the server is a `POST` whose body is **sealed** to the server's
public key on top of TLS (the "XCVM-API-v1" envelope). Inside the envelope are a
timestamp, a one-time request id, the API key (when needed) and the body itself.
The server's response is sealed to a one-time key that only your panel knows, for
this one request.

Why: so that the request and response bodies cannot be read or substituted even
where TLS terminates on an intermediate hop (a proxy, a load balancer, the
nginx→php-fpm hop on the server side). This protection works **in your favour**:
your API key does not travel in an HTTP header and is invisible on intermediate
hops, and a downloaded module cannot be swapped in transit.

Client parameters:

- HTTPS only; certificate-chain and hostname verification is on and **cannot be
  turned off anywhere** — the library used simply has no such setting;
- redirects are refused (301/302 = error);
- timeouts: ordinary request 15 s, archive download 120 s, connection 10 s;
- an archive is at most 100 MB;
- there is no plaintext (unsealed) mode on either the client or the server.

### 6.2. The complete request table

| Endpoint | When | What is sent |
|----------|------|-------------|
| `panel/register` | registering the panel with the store | `install_id`; the panel's **public** key; `env_fp` (hash); `hw_id` (hash of `install_id + machine-id`); API key |
| `plugins/check` | before installing a module | `plugin_id`; `install_id`; API key (optional) |
| `plugins/token` | installing/licensing a module | `plugin_id`; `install_id`; API key (optional for free modules) |
| `plugins/key` | installing a paid module | token; module name and version |
| `plugins/download` | installing a module | slug, version, token |
| `plugins/license` | requesting a `.lic` for a module | token; the ionCube machine fingerprint (`ioncube_server_data()`) |
| `extensions` | browsing the store catalogue | filters: environment, page, search, sort; API key (optional) |
| `extensions/check-update` | checking for updates | a list of `{slug, version}` of installed modules; API key (optional) |
| `activation/status` | refreshing the licence verdict cache (section 7.3) | `jti` (activation key id); `install_id`; `env_fp`; a one-time nonce |
| `activation/checkin` | the daily check-in on MAIN (section 7.3) | the same |

### 6.3. What is never in a request

- ❌ data about your users, subscribers, playlists, streams, EPG;
- ❌ the contents of your DB or Redis;
- ❌ passwords — DB, Redis or system;
- ❌ private keys, `config.enc`, module keys;
- ❌ the hostname or `/etc/machine-id` in the clear — only their hash;
- ❌ usage statistics, logs, your clients' IP lists, load figures;
- ❌ anything "in the background": every request in the table happens inside a
  method call by the panel, and none at PHP load time or on a core timer.

The only "regular" contact — the licence check, at most once a day — also happens
**inside** a `license_valid()` call made by the panel, not on the core's own
initiative; what it carries is in the table above.

---

## 7. Licensing: how it works and what happens on revocation

Yes, the core checks the licence. This section describes exactly how, what happens
without network access, and what happens on revocation. None of it is hidden.

### 7.1. The activation key

The installation licence is a text key of the form `XCVM1.<data>.<signature>` that
the administrator pastes into the panel; it is saved to the `activation_key` file.
The key is **signed by the server** (Ed25519) and contains:

- `hwid` — your `install_id`: the key works only on the installation it was issued
  for;
- `jti` — the key's identifier;
- `iat` — the issue time;
- `exp` — the expiry (may be absent = perpetual).

Signature, binding and expiry are checked **offline**, with no network.

### 7.2. The attribution requirement

The XC_VM panel is distributed under the AGPL-3.0 licence, and a condition of using
the licensed features is keeping the authors' attribution. The core checks this: it
reads six of the panel's own files (`Core/Util/AdminHelpers.php` and five
footer/login templates of the admin, reseller and player panels) and makes sure the
attribution strings are in place (`github.com/Vateron-Media/XC_VM`,
`Vateron Media`, a link to AGPL-3.0) and that the footer-rendering call is not
commented out.

- The check is **purely file-based**: no network, no cryptography.
- If the files are **absent** (a non-standard layout, development), the check
  counts as **passed**: the core does not brick an installation over missing files.
- If the attribution has been cut out, `license_valid()` returns `false`, i.e. the
  **licensed** features (fanout, cluster token minting) switch off. The panel
  itself, your streams, DB and users keep working.
- The verdict is cached for 1 second so the files are not read on every request.

### 7.3. The server check and behaviour without network

On MAIN, `license_valid()` = "attribution in place" **and** "activation key valid".
Validity includes revocation, and this is how the core learns about it:

- The server's answer about the key's state is cached in the `license_verdict`
  file. The cache is signed by the server and cannot be forged.
- When the cache is stale (or absent), the core — inside a `license_valid()` call —
  makes an `activation/status` request; the answer is checked by signature, by the
  one-time `nonce` and by `jti`, and only then stored in the cache.
- On MAIN, additionally, once a day (20 hours plus a random offset of up to 8 hours,
  to avoid load spikes) an `activation/checkin` is made — the same data. LB nodes
  do no check-in.

**Behaviour without server access — the key point:**

- A key the server has confirmed at least once **stays valid offline
  indefinitely**. An unreachable licence server **never** turns a paid
  installation into an unpaid one.
- A key the server has never confirmed (for example, just pasted while there is no
  network) works for **14 days** from its issue time.
- A licence is considered invalid in exactly two cases: the `exp` recorded in the
  key itself has passed, **or** the server sent a **signed** "key revoked" answer.
  A network error, a timeout or an unparseable answer is **not** a revocation.

### 7.4. What exactly happens on revocation (enforcement)

This is worth knowing in advance. When the licence is **confirmed** invalid (the
two cases above), and only on the **MAIN** server, the core does two things:

1. Writes a `license_revoked` marker file with a timestamp into the config
   directory.
2. Revokes, in your DB, the panel user's access privileges **for the LB/proxy
   nodes' addresses** (it takes the list of addresses from your `servers` table
   where `is_main = 0` and runs `REVOKE ALL PRIVILEGES` for each). Each address
   first passes a strict character check to rule out SQL injection via the table.

This runs at most **once an hour**. What it does **not** do:

- ❌ it does not delete or modify your data — not a single row, not a single table;
- ❌ it does not touch MAIN's own access to its database;
- ❌ it does not stop PHP, nginx or streams, and does not delete modules or files;
- ❌ it sends nothing to the server;
- ❌ LB nodes **never** enforce, whatever happens.

The intent: a revoked licence takes away the installation's **multiserver**
capabilities (nodes lose access to the central DB) but breaks neither the panel nor
the data. Access can be re-granted with an ordinary `db_grant` once the licence is
restored.

### 7.5. The licence on an LB node

An LB node holds no activation key. For it, `license_valid()` = "attribution in
place" **and** "a live lease from MAIN" — MAIN-signed permission with a limited
lifetime, verified against MAIN's pinned key and judged on MAIN's time (turning
the node's clock back gains nothing). The lease lets a node keep serving while MAIN
is temporarily unreachable.

---

## 8. The protective mechanisms that usually raise questions

### 8.1. Anti-debugging
Described in section 3, item 1. To repeat the key point: it is only a refusal to
load under a debugger — with no side effects. Its purpose is to stop module keys
being lifted from the memory of a running process.

### 8.2. Hidden strings in the binary
The server address and two internal "peppers" (constants that take part in key
derivation) are stored in the binary XOR-encoded, so `strings xcvm_core.so` does
not show them. The purpose is to stop the core being trivially repointed at a fake
server, and to stop key derivation resting on `install_id` alone. This **hides
constants, not behaviour** — the behaviour is described here in full. Key-domain
names such as `xcvm_cfg_v1` and `xcvm_module_v1` are visible in `strings`, and that
is fine.

### 8.3. Machine binding: `env_fp` and `hw_id`
- `env_fp` = `SHA-256(install_id + /etc/machine-id + hostname)`. A "soft" binding:
  the server notices cloning or migration from a change in the hash, but a changed
  `env_fp` **blocks nothing**. If the machine-id or hostname is unavailable, it is
  replaced with an empty string.
- `hw_id` = `SHA-256(install_id + /etc/machine-id)`. The server pins it at first
  registration. If the same `install_id` shows up from another machine, **paid**
  modules stop being issued with the error `panel_moved` until the store
  administrator resets the binding. This protects against copying one paid
  installation onto many servers.

Both values are hashes; your hostname or machine-id cannot be recovered from them.

### 8.4. The `xcvm_core.server_url` directive
A different server address can be set in `php.ini`, but only in **encrypted** form
— the value is issued by a tool of the platform owner. A plaintext URL in this
directive is ignored and the built-in address is used. The decrypted address must be
`https://`. The purpose is to stop the core being redirected, by changing one
string, to a fake store serving fake modules.

### 8.5. Self-wiping keys
Module keys, the master key, DB passwords and other key material are held in the
core in buffers that are zeroed in memory when released — on every exit path,
including errors.

---

## 9. What the core does not contain

- ❌ **A backdoor or remote control.** There is no method that executes commands or
  code sent by the server. The server can only answer with data to a request the
  panel made: a catalogue, a token, a key, an archive, a licence verdict.
- ❌ **Execution of delivered code by the core.** The only thing that arrives from
  the server and is later executed is modules, and they are executed by the stock
  ionCube Loader through PHP's normal mechanism, after you yourself triggered the
  installation. The core installs no hooks into PHP compilation or execution.
- ❌ **Telemetry, spying, data exfiltration.** The full contents of every request
  are in section 6.2; there is nothing there beyond installation identifiers and
  the parameters of the requested operation.
- ❌ **Background activity.** No threads, timers, daemons or cron jobs. The core is
  active only inside a method call.
- ❌ **Self-updating.** The core does not download or replace its own binary.
- ❌ **Disabling TLS verification.** Certificate verification is always on; the
  library has no setting to turn it off.
- ❌ **Destructive actions without your call.** The only destructive operation on
  your data — `db_restore` (recreating the DB from a backup) — runs only on an
  explicit call from the panel. Licence enforcement does not touch data.
- ❌ **Reading anything extra.** Outside the config directory the core reads only
  `/etc/machine-id`, `/proc/self/status`, the hostname, the six panel files for the
  attribution check, and files you explicitly passed in (a dump to restore).
- ❌ **Memory-unsafe code.** The core is written in Rust; unsafe code is used only
  at the boundary with PHP.

---

## 10. How to verify all of this yourself

None of the steps below needs the sources or our secrets.

**The method list.** Confirm that the class contains exactly what section 5
describes, and no global functions:
```bash
php --rc XC_VM          # every method of the class
php --re xcvm_core      # everything the extension registers: the class, ini directives
```

**Network addresses and when they are contacted.** Capture traffic and confirm the
core goes to one address only, and only on explicit actions in the panel:
```bash
sudo tcpdump -n -i any 'port 443 and not host <your DB IP>'
```
At PHP load, during the normal operation of streams and users, there are no
requests. They appear on registration, module installation, catalogue browsing —
and at most once a day for the licence check.

**System calls.** Confirm there is no network activity at PHP start and no access to
unexpected files. Note: `strace` started from scratch traces the process from its
first instruction, so under it the core **will not load** (that is the
anti-debugging from section 3 — `class_exists("XC_VM")` returns `false`, which also
confirms the check really exists). So use tools without `ptrace`:
```bash
# a fresh process, via eBPF (linux-tools / perf package):
sudo perf trace -e 'connect,openat' php -r 'var_dump(class_exists("XC_VM"));'
```
You will see reads of `/proc/self/status`, `/etc/machine-id`, files in the config
directory — and not a single `connect()`.

To watch behaviour **at run time**, attach to an already running php-fpm worker —
the debugger check runs only at load, and the worker has already passed it:
```bash
sudo strace -f -e trace=network,openat -p $(pgrep -f 'php-fpm: pool' | head -1)
```
Perform actions in the panel and watch: connections go only to your DB/Redis and —
on module installation or a licence check — to `www.xcvm.tech`.

**What is in the config directory.** Compare with the table in section 4:
```bash
sudo ls -la /home/xc_vm/config/ /home/xc_vm/config/cluster/ 2>/dev/null
```

**What is baked into the binary.** No secrets or passwords in the clear:
```bash
strings xcvm_core.so | grep -iE 'password|secret|xcvm\.tech'
```

**What the core hands PHP.** `config_server()` returns no passwords:
```bash
php -r 'var_dump(XC_VM::config_server());'
```

**Behaviour without network.** Cut the server's access to `www.xcvm.tech` — the
panel, streams, DB and modules keep working; `license_valid()` for a confirmed key
stays `true`.

**Attribution.** `php -r 'var_dump(XC_VM::verify_branding());'` — `true` on an
untouched panel.

---

## 11. Honestly about the limits

We do not claim the protection is absolute. Here is where it ends:

- **A PHP encoder is not absolute.** Root on their own licensed machine can in
  theory extract the passphrase from an encoded file, open their own `.lic` and
  obtain the decoding key. Machine binding and the short `.lic` lifetime make this
  more expensive and provide a kill-switch, but it is a fundamental limitation of
  any IonCube-like solution.
- **A leak of `install_id`** by itself exposes neither `config.enc` (the
  `machine-id` is also needed) nor the cluster transport blobs (an internal
  "pepper" is also needed).
- **The sealed API has no forward secrecy** against the server's long-lived
  transport key: compromising it would expose previously recorded exchanges, and
  recovery means shipping a new core with a new key. The address, message sizes and
  timing are visible on the wire regardless — the envelope hides the body, not the
  metadata.
- **The attribution check is file-based** and is therefore bypassable by editing
  files on one's own machine; it deters rather than makes impossible.
- **Enforcement works at the level of DB grants**, and the MAIN administrator can
  re-issue them. That is deliberate: the core must not be able to break your
  installation, even on the platform owner's command.

That is what transparency means: describing not only what is protected, but also
where the protection ends.

---

## 12. Frequently asked questions

**Can the core delete or corrupt my data?**
Only `db_restore` recreates the DB, and only when you yourself start a restore from
backup. Nothing else — including licence revocation — touches data.

**What if the licence server shuts down or becomes unreachable?**
A confirmed licence keeps working offline indefinitely; the panel, streams, DB and
modules work. The only things that stop are downloading new modules and updates
from the store and minting a new `.lic` for a module (existing `.lic` files work
until their expiry).

**Does the core see my users, streams, traffic?**
It opens a connection to your DB at the panel's request, as PHP code would — but
sends that data nowhere. The contents of every external request are in section 6.2.

**Can the platform owner "switch off" my panel?**
No. The most that happens on licence revocation is described in section 7.4: nodes
lose access to the central DB, and the MAIN administrator can re-grant that access.
MAIN, the data, streams and users are unaffected.

**Why not just open the sources?**
Because the core is where the DB passwords and paid-module keys live; its reason for
existing is that they cannot be read from PHP. Instead of sources we provide a
complete description of the behaviour (this page) and ways to verify it from
outside (section 10).

**Can I run the core under a debugger or profiler?**
If the PHP process is **started** under a `ptrace` tool (gdb, `strace php ...`),
the core does not load in that process — this is checked once at start. Attaching
to an already running process is possible (`strace -p`, `gdb -p`): there is no
repeat check, and that is deliberate — the aim of the protection is to stop module
keys being read from memory via a debugger *while they are being obtained*, not to
stop you observing your own server. Tools without `ptrace` (`perf`, eBPF, `tcpdump`)
are not affected at all.

---

**Summary.** `xcvm_core` does exactly what the panel needs: it keeps the config and
provides DB/Redis access without passwords in PHP, delivers and protects modules,
checks the licence and serves the cluster. It contacts one server, only when the
panel calls it, and sends only installation identifiers and operation parameters.
It contains no backdoor, collects no telemetry, does not touch your data and cannot
be switched off from outside. Every one of these statements on this page comes with
a way to verify it yourself.
