# RTMP auth-notify faithfulness gates — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Go `/stream/rtmp` handler (Fanout #97) match rtmp.php's LB-arm allow/deny on the three gates it deliberately left open — the allowed-RTMP list, the `on_demand` start gate, and the brute-force ban — so the flip (cutover sub-project 4) can turn `-front-agent-sock` on for RTMP without a behaviour change.

**Architecture:** Agent-side only (`internal/clusteragent`), reading three inputs that the current MAIN already ships to a serve-only LB's replica files (verified on disk): `blocklist.json.data.rtmp`, `streams/<id>.json` `data.server.on_demand`, and `servers.json.data.servers[]`. New readers + helpers live in a new `rtmpgates.go`; the wiring edits land in the existing `rtmpnotify.go`. The gateway's block-marker convention is **mirrored, not imported** (clusteragent does not import `gateway`).

**Tech Stack:** Go, stdlib + in-repo packages only; zero new external deps. `GOWORK=off` for every go command.

**Spec:** `docs/superpowers/specs/2026-10-20-lb-rtmp-gates-design.md`

## Global Constraints

- Go stdlib + in-repo packages only; **zero new external dependencies**. Run every go command with `GOWORK=off`.
- **Agent-side only** — no MAIN/XC_VM change. All three inputs are already on the LB's disk with the current MAIN (verified).
- Faithful to rtmp.php's LB arm. The wire stays `200` (admit) / `404` (deny) — nginx_rtmp treats any non-2xx as deny.
- Base: XC_VM_Fanout `feat/lb-transcode-serve` @ `dc9862f` (already carries the merged #97 handler). XC_VM side is docs-only (this plan + the design, branch `docs/lb-rtmp-gates-design`).
- Conventional Commits, English messages, **no Claude attribution** (no generated-by line, no Claude co-author trailer).
- The whole package suite must stay green: `GOWORK=off go test ./internal/clusteragent/` and `GOWORK=off go vet ./...`.

## Review Focus

Input classes the gates will meet that no gate's happy-path test exercises — each pinned in the owning task:

- **Missing/malformed replica file on a fresh LB** (`blocklist.json`, `streams/<id>.json`, `servers.json`, `settings.json` absent or non-JSON): every reader must fail safe, never panic — empty allowlist (Task 1), `on_demand=false`→404 (Task 2), exclusion set still contains `127.0.0.1` (Task 4), empty blocked-set (Task 4). → tested in Tasks 1, 2, 4.
- **Invalid / IPv6 peer `n.Addr`** (`net.ParseIP(ip) == nil`): the marker-read, marker-write and counter paths must guard on a parseable IP, as the gateway does, and simply not ban rather than build `block_<garbage>`. → Tasks 3, 4.
- **Concurrent counter writes** (several RTMP callbacks for one IP, and php-fpm racing the same `floodDir/<ip>` file during the transition): the write is atomic temp-rename and a torn/short read resets the counter instead of crashing. → Task 4.
- **`whitelist_ips` double-encoding edge** (`null`, `""`, or malformed inner JSON in `servers.json`): skip that server's whitelist, keep the rest of the exclusion set. → Task 4.
- **Flood window boundary** (`flood_seconds` unset/0, first-sighting vs expired): the Go counter reproduces PHP's exact arithmetic — first sighting writes `requests:0`, within-window blocks at `requests >= flood_limit`, expiry resets to `requests:0`. → Task 4.

---

### Task 1: Gate 1 — allowed-RTMP allowlist reader, wired into the publish + relay arms

**Files:**
- Create: `internal/clusteragent/rtmpgates.go`
- Modify: `internal/clusteragent/rtmpnotify.go` (publish arm ~35-41; relay-bypass condition ~49)
- Modify: `internal/clusteragent/agent.go` (add the allowlist cache fields to the `Agent` struct, next to the existing `rtmp*` unexported fields ~133-137)
- Test: `internal/clusteragent/rtmpgates_test.go` (readers), `internal/clusteragent/rtmpnotify_test.go` (wiring)

**Interfaces:**
- Consumes (existing): `func (a *Agent) passMatches(known, given string) bool` (rtmpnotify.go:127); `func (a *Agent) secret(key string) string` (rtmpnotify.go:136); `a.ReplicaDir string`; the handler rig `newRtmpHandlerRig(t)` + `rig.do(query, header)` (rtmpnotify_test.go).
- Produces:
  - `type rtmpAllow struct { Password string; Push, Pull int64 }`
  - `func (a *Agent) allowedRTMP(ip string) (rtmpAllow, bool)` — cached, refreshed on `blocklist.json` mod-time.
  - `func secretMatches(known, given string) bool` — `known != "" && hmac.Equal([]byte(known), []byte(given))` (port of `AuthService::secretMatches`; empty known never matches).
  - `func (a *Agent) allowRTMP(ip, want, given string) bool` — `want` is `"push"` or `"pull"`; `entry, ok := a.allowedRTMP(ip); ok && flag(want)==1 && (entry.Password=="" || secretMatches(entry.Password, given))`.

- [ ] **Step 1: Write the failing reader test**

In `rtmpgates_test.go`, `package clusteragent`:

```go
func TestAllowedRTMPReadsBlocklist(t *testing.T) {
	a := &Agent{ReplicaDir: t.TempDir()}
	writeBlocklist(t, a.ReplicaDir, map[string]any{
		"ip":   []string{},
		"rtmp": []map[string]any{{"id": 1, "ip": "203.0.113.5", "password": "", "push": 1, "pull": 0}},
	})
	e, ok := a.allowedRTMP("203.0.113.5")
	if !ok || e.Push != 1 || e.Pull != 0 || e.Password != "" {
		t.Fatalf("entry = %+v ok=%v", e, ok)
	}
	if _, ok := a.allowedRTMP("198.51.100.1"); ok {
		t.Fatal("unlisted ip must not match")
	}
}

func TestAllowedRTMPMissingFileSafe(t *testing.T) {
	a := &Agent{ReplicaDir: t.TempDir()} // no blocklist.json
	if _, ok := a.allowedRTMP("203.0.113.5"); ok {
		t.Fatal("missing blocklist.json must yield empty allowlist, not match")
	}
}

func TestSecretMatches(t *testing.T) {
	if secretMatches("", "anything") {
		t.Fatal("empty known must never match")
	}
	if !secretMatches("s3cret", "s3cret") || secretMatches("s3cret", "nope") {
		t.Fatal("non-empty known is a constant-time equal")
	}
}
```

Add the fixture helper in `rtmpgates_test.go` (mirrors the on-disk `{seq,etag,data}` envelope `materialise` writes, replica.go:724):

```go
func writeBlocklist(t *testing.T, dir string, data map[string]any) {
	t.Helper()
	b, _ := json.Marshal(map[string]any{"seq": 1, "etag": "e", "data": data})
	if err := os.WriteFile(filepath.Join(dir, "blocklist.json"), b, 0o600); err != nil {
		t.Fatal(err)
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestAllowedRTMP|TestSecretMatches' -v`
Expected: FAIL — `a.allowedRTMP` / `secretMatches` undefined.

- [ ] **Step 3: Implement the reader + helpers in `rtmpgates.go`**

Add to the `Agent` struct (agent.go, by the other `rtmp*` fields): `rtmpAllowMu sync.Mutex`, `rtmpAllowMtime time.Time`, `rtmpAllowCache map[string]rtmpAllow`.

`allowedRTMP(ip)`: lock; `stat(<ReplicaDir>/blocklist.json)`; on a mod-time change (or nil cache) re-read the file, `json.Unmarshal` into `struct{ Data struct{ Rtmp []struct{ IP, Password string; Push, Pull int64 } `json:"rtmp"` } `json:"data"` }`, rebuild `rtmpAllowCache` keyed by the row's literal `IP` (rows are IP-validated at MAIN write time — `// ponytail: no gethostbyname; rows are IP literals. add resolution only if MAIN ever permits hostname rtmp_ips rows`). A read/parse error leaves the cache empty (fail-safe). Return `entry, ok := a.rtmpAllowCache[ip]`.
`secretMatches` and `allowRTMP` as in Interfaces; `allowRTMP` selects `entry.Push`/`entry.Pull` by `want`.

- [ ] **Step 4: Run it to verify it passes**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestAllowedRTMP|TestSecretMatches' -v`
Expected: PASS.

- [ ] **Step 5: Write the failing wiring test**

In `rtmpnotify_test.go` (uses the existing rig; stream `100` is held+running per the rig's control-socket stub, so no on_demand interaction here):

```go
func TestRtmpPublishAllowedRTMPNoStreamPass(t *testing.T) {
	rig := newRtmpHandlerRig(t)
	writeBlocklist(t, rig.a.ReplicaDir, map[string]any{"ip": []string{},
		"rtmp": []map[string]any{{"id": 1, "ip": "203.0.113.5", "password": "", "push": 1, "pull": 0}}})
	rec := rig.do("addr=203.0.113.5&clientid=1&call=publish&name=100", nil) // no password
	if rec.Code != 200 {
		t.Fatalf("push-listed publish without stream pass: %d", rec.Code)
	}
}

func TestRtmpPublishAllowedRTMPEntryPassword(t *testing.T) {
	rig := newRtmpHandlerRig(t)
	writeBlocklist(t, rig.a.ReplicaDir, map[string]any{"ip": []string{},
		"rtmp": []map[string]any{{"id": 1, "ip": "203.0.113.5", "password": "pub-pw", "push": 1, "pull": 0}}})
	if rig.do("addr=203.0.113.5&clientid=1&call=publish&name=100&password=pub-pw", nil).Code != 200 {
		t.Fatal("correct entry password must admit")
	}
	if rig.do("addr=203.0.113.5&clientid=1&call=publish&name=100&password=wrong", nil).Code != 404 {
		t.Fatal("wrong entry password must deny")
	}
}

func TestRtmpPublishPushFlagRequired(t *testing.T) {
	rig := newRtmpHandlerRig(t)
	writeBlocklist(t, rig.a.ReplicaDir, map[string]any{"ip": []string{},
		"rtmp": []map[string]any{{"id": 1, "ip": "203.0.113.5", "password": "", "push": 0, "pull": 1}}})
	if rig.do("addr=203.0.113.5&clientid=1&call=publish&name=100", nil).Code != 404 {
		t.Fatal("pull-only entry must not admit a publish")
	}
}

func TestRtmpPlayRelayAllowedRTMPPull(t *testing.T) {
	rig := newRtmpHandlerRig(t)
	writeBlocklist(t, rig.a.ReplicaDir, map[string]any{"ip": []string{},
		"rtmp": []map[string]any{{"id": 1, "ip": "203.0.113.5", "password": "", "push": 0, "pull": 1}}})
	// relay-bypass arm: play with no line creds, pull-listed IP, running stream 100
	rec := rig.do("addr=203.0.113.5&clientid=1&call=play&name=100", nil)
	if rec.Code != 200 {
		t.Fatalf("pull-listed relay play: %d", rec.Code)
	}
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestRtmpPublishAllowed|TestRtmpPublishPushFlag|TestRtmpPlayRelayAllowed' -v`
Expected: FAIL — the arms ignore the allowlist (push-listed publish without the stream pass is denied; pull relay is denied).

- [ ] **Step 7: Wire the allowlist into the two arms in `rtmpnotify.go`**

- Publish arm (~line 37): change the admit condition to
  `if a.passMatches(a.secret("live_streaming_pass"), q.Get("password")) || a.allowRTMP(n.Addr, "push", q.Get("password"))` (where `q := r.URL.Query()`), keeping the existing 200 / deny branches.
- Relay-bypass arm (~line 49): change the condition to
  `if a.passMatches(a.secret("live_streaming_pass"), q.Get("password")) || a.allowRTMP(n.Addr, "pull", q.Get("password"))`, leaving its inner held/running block and 200 unchanged (Task 2 restructures that block).
- Add the new meter labels where they admit via the allowlist is optional; keep the existing `rtmpMeter("publish")` / `rtmpMeter("play_relay")` calls (no wire change).

- [ ] **Step 8: Run the wiring + reader tests to verify they pass**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestAllowedRTMP|TestSecretMatches|TestRtmpPublish|TestRtmpPlayRelay' -v`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add internal/clusteragent/rtmpgates.go internal/clusteragent/rtmpgates_test.go internal/clusteragent/rtmpnotify.go internal/clusteragent/rtmpnotify_test.go internal/clusteragent/agent.go
git commit -m "feat(cluster): honour the allowed-RTMP list on the Go /stream/rtmp arms"
```

---

### Task 2: Gate 2 — `on_demand` reader, gating held-but-not-running in both play arms

**Files:**
- Modify: `internal/clusteragent/rtmpgates.go` (add `streamOnDemand`)
- Modify: `internal/clusteragent/rtmpnotify.go` (relay-bypass inner block ~51-55; line arm held/running block ~82-89)
- Test: `internal/clusteragent/rtmpgates_test.go`, `internal/clusteragent/rtmpnotify_test.go`

**Interfaces:**
- Consumes: `func streamsDir(dir string) string` (streams.go:121) → `<ReplicaDir>/streams/<id>.json`; `func (a *Agent) fanoutStreamStatus(ctx, id) (running, hasData, ok bool)` (streamregister.go:498); `func (a *Agent) fanoutEnsure(ctx, id)` (streamregister.go:526). The rig's control-socket stub answers `/streams/200` as `{running:false,has_data:false}` (held, not running) and `/streams/200/ensure` (streamregister reads the status via `ok`→held).
- Produces: `func (a *Agent) streamOnDemand(id string) bool` — reads `<ReplicaDir>/streams/<id>.json`, decodes `.data.server.on_demand` (int), returns `on_demand == 1`; a missing/unreadable file, a null `data.server`, or `on_demand != 1` → `false`.

- [ ] **Step 1: Write the failing reader test**

```go
func TestStreamOnDemand(t *testing.T) {
	a := &Agent{ReplicaDir: t.TempDir()}
	writeStreamRecord(t, a.ReplicaDir, "200", map[string]any{"server": map[string]any{"on_demand": 1}})
	writeStreamRecord(t, a.ReplicaDir, "201", map[string]any{"server": map[string]any{"on_demand": 0}})
	writeStreamRecord(t, a.ReplicaDir, "202", map[string]any{"server": nil})
	if !a.streamOnDemand("200") {
		t.Fatal("on_demand=1 must be true")
	}
	for _, id := range []string{"201", "202", "999"} { // 999 = missing file
		if a.streamOnDemand(id) {
			t.Fatalf("%s must be not-on-demand", id)
		}
	}
}
```

Fixture helper (envelope mirrors `checkStream`'s on-disk `{etag,ver,data}`, streams.go:628):

```go
func writeStreamRecord(t *testing.T, dir, id string, data map[string]any) {
	t.Helper()
	if err := os.MkdirAll(filepath.Join(dir, "streams"), 0o750); err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(map[string]any{"etag": "e", "ver": 1, "data": data})
	if err := os.WriteFile(filepath.Join(dir, "streams", id+".json"), b, 0o600); err != nil {
		t.Fatal(err)
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run TestStreamOnDemand -v`
Expected: FAIL — `a.streamOnDemand` undefined.

- [ ] **Step 3: Implement `streamOnDemand` in `rtmpgates.go`**

`os.ReadFile(filepath.Join(streamsDir(a.ReplicaDir), id+".json"))`; `json.Unmarshal` into `struct{ Data struct{ Server *struct{ OnDemand int64 `json:"on_demand"` } `json:"server"` } `json:"data"` }`; return `w.Data.Server != nil && w.Data.Server.OnDemand == 1`. Any error → `false`.

- [ ] **Step 4: Run it to verify it passes**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run TestStreamOnDemand -v`
Expected: PASS.

- [ ] **Step 5: Write the failing gating tests (both arms)**

```go
func TestRtmpRelayOnDemandGate(t *testing.T) {
	// relay-bypass arm, held+not-running stream 200
	run := func(onDemand int) int {
		rig := newRtmpHandlerRig(t)
		writeStreamRecord(t, rig.a.ReplicaDir, "200", map[string]any{"server": map[string]any{"on_demand": onDemand}})
		return rig.do("addr=1.2.3.4&clientid=1&call=play&name=200&password=s3cret", nil).Code // stream-pass relay
	}
	if run(1) != 200 {
		t.Fatal("held+idle+on_demand=1 relay must start and admit")
	}
	if run(0) != 404 {
		t.Fatal("held+idle+on_demand!=1 relay must 404")
	}
}

func TestRtmpLineOnDemandGate(t *testing.T) {
	run := func(onDemand int) int {
		rig := newRtmpHandlerRig(t)
		rig.okAuth()
		writeStreamRecord(t, rig.a.ReplicaDir, "200", map[string]any{"server": map[string]any{"on_demand": onDemand}})
		return rig.do("addr=1.2.3.4&clientid=1&call=play&name=200&tcurl=rtmp://h/live&app=live&username=u&password=p", nil).Code
	}
	if run(1) != 200 {
		t.Fatal("held+idle+on_demand=1 line must start and admit")
	}
	if run(0) != 404 {
		t.Fatal("held+idle+on_demand!=1 line must 404")
	}
}

func TestRtmpPlayNotHeldUnchanged(t *testing.T) {
	// name=999 → control socket 404 → not held; relay 200, line 404 (unchanged, rtmp.php:117)
	rig := newRtmpHandlerRig(t)
	if rig.do("addr=1.2.3.4&clientid=1&call=play&name=999&password=s3cret", nil).Code != 200 {
		t.Fatal("not-held relay stays 200")
	}
	rig2 := newRtmpHandlerRig(t)
	rig2.okAuth()
	if rig2.do("addr=1.2.3.4&clientid=1&call=play&name=999&tcurl=rtmp://h/live&app=live&username=u&password=p", nil).Code != 404 {
		t.Fatal("not-held line stays 404")
	}
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestRtmpRelayOnDemand|TestRtmpLineOnDemand|TestRtmpPlayNotHeld' -v`
Expected: FAIL — the arms call `fanoutEnsure` unconditionally on held+idle, so `on_demand=0` returns 200 not 404.

- [ ] **Step 7: Restructure the held/not-running block in both arms (`rtmpnotify.go`)**

Replace the "ensure when held && !running" logic in each arm with the on_demand gate:

```go
// relay-bypass arm (was: if held && !running { fanoutEnsure }):
if held && !running {
	if !a.streamOnDemand(n.Name) {
		a.rtmpDeny(w, "on_demand")
		return
	}
	a.fanoutEnsure(r.Context(), n.Name)
}
```

Line arm (~87): same shape — replace `if !running { a.fanoutEnsure(...) }` with the `if !running { if !a.streamOnDemand(n.Name) { rtmpDeny("on_demand"); return }; fanoutEnsure }`. The `if !held { rtmpDeny("not held") }` (line) and the not-held→200 (relay) stay as they are. `rtmpDeny("on_demand")` is a **non-counting** deny (Task 4 keeps it on `rtmpDeny`, not the flood variant).

- [ ] **Step 8: Run the Gate-2 tests + the Task-1 tests to verify no regression**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestStreamOnDemand|TestRtmp' -v`
Expected: PASS (including the existing `TestRtmpPlayLineAuth`, `TestRtmpPlayRelay*`, play_done, loopback tests).

- [ ] **Step 9: Commit**

```bash
git add internal/clusteragent/rtmpgates.go internal/clusteragent/rtmpgates_test.go internal/clusteragent/rtmpnotify.go internal/clusteragent/rtmpnotify_test.go
git commit -m "feat(cluster): gate RTMP on-demand starts on data.server.on_demand"
```

---

### Task 3: Gate 3a — enforce the `block_<ip>` marker at the top of the handler; add the flood-dir config

**Files:**
- Modify: `internal/clusteragent/agent.go` (add `FloodDir string` to the exported `Agent` fields, ~39)
- Modify: `internal/agentcmd/agentcmd.go` (new `-flood-dir` flag, default `/home/xc_vm/tmp/flood/`; set `FloodDir` in the Agent struct literal ~248)
- Modify: `internal/clusteragent/rtmpgates.go` (add `floodBlocked`)
- Modify: `internal/clusteragent/rtmpnotify.go` (top of `serveRtmpNotify`, after parse ~22)
- Test: `internal/clusteragent/rtmpnotify_test.go`

**Interfaces:**
- Consumes (mirror, not import): the gateway convention `exists(Flood + "block_" + ip)` (gateway/judge.go:200 `BootstrapRefuses`).
- Produces: `a.FloodDir string` (config); `func (a *Agent) floodBlocked(ip string) bool` — `net.ParseIP(ip) != nil && fileExists(filepath.Join(a.FloodDir, "block_"+ip))`; `a.FloodDir == ""` → `false`.

- [ ] **Step 1: Write the failing test**

```go
func TestRtmpBlockedIPRefusedAtTop(t *testing.T) {
	rig := newRtmpHandlerRig(t)
	rig.a.FloodDir = t.TempDir()
	rig.okAuth() // would admit if reached
	if err := os.WriteFile(filepath.Join(rig.a.FloodDir, "block_203.0.113.9"), nil, 0o600); err != nil {
		t.Fatal(err)
	}
	rec := rig.do("addr=203.0.113.9&clientid=7&call=play&name=100&tcurl=rtmp://h/live&app=live&username=u&password=p", nil)
	if rec.Code != 404 {
		t.Fatalf("blocked IP must be refused: %d", rec.Code)
	}
	if rig.a.Registry.Get(rtmpUUID(3, "7")) != nil {
		t.Fatal("blocked IP must not reach auth / open a record")
	}
}

func TestRtmpBlockedInvalidIPNoCrash(t *testing.T) {
	rig := newRtmpHandlerRig(t)
	rig.a.FloodDir = t.TempDir()
	if rig.do("addr=not-an-ip&clientid=7&call=publish&name=100&password=s3cret", nil).Code == 0 {
		t.Fatal("a non-IP addr must not crash the handler")
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestRtmpBlocked' -v`
Expected: FAIL — `a.FloodDir` / `a.floodBlocked` undefined (compile error), and the marked IP is admitted.

- [ ] **Step 3: Implement the field, flag, and enforce check**

- `agent.go`: add `FloodDir string` to the exported config fields.
- `agentcmd.go`: `floodDir := flag.String("flood-dir", "/home/xc_vm/tmp/flood/", "dir holding per-IP flood counters and block_<ip> markers (match PHP FLOOD_TMP_PATH)")`; add `FloodDir: *floodDir` to the `&clusteragent.Agent{...}` literal. (`// ponytail: trivial flag wiring; covered by go build + the FloodDir field tests, no flag unit test`.)
- `rtmpgates.go`: `floodBlocked` per Interfaces (reuse any existing `fileExists`-style helper in the package if one exists, else `os.Stat` + `!os.IsNotExist`).
- `rtmpnotify.go`: right after the parse guard in `serveRtmpNotify`, before the loopback fast-path: `if a.floodBlocked(n.Addr) { a.rtmpDeny(w, "blocked"); return }`.

- [ ] **Step 4: Run it to verify it passes**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestRtmpBlocked' -v`
Expected: PASS.

- [ ] **Step 5: Build the agent command to prove the flag wiring compiles**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go build ./internal/agentcmd/`
Expected: builds clean.

- [ ] **Step 6: Commit**

```bash
git add internal/clusteragent/agent.go internal/agentcmd/agentcmd.go internal/clusteragent/rtmpgates.go internal/clusteragent/rtmpnotify.go internal/clusteragent/rtmpnotify_test.go
git commit -m "feat(cluster): refuse a flood-blocked IP at the top of /stream/rtmp"
```

---

### Task 4: Gate 3b/c/d — the file-backed flood counter, exclusions, ban + P0 spool; wire the full deny set

**Files:**
- Modify: `internal/clusteragent/rtmpgates.go` (add `floodTick`, `allowedIPs`, `blockedIPs`, `floodSettings`)
- Modify: `internal/clusteragent/rtmpnotify.go` (add `rtmpFloodDeny`; call it on the counting deny paths; remove the in-memory `rtmpFlood`/`rtmpFloodCount`/`rtmpFloodMax`)
- Modify: `internal/clusteragent/agent.go` (remove `rtmpFloodMu`/`rtmpFloodN` ~136-137)
- Test: `internal/clusteragent/rtmpgates_test.go` (counter/exclusions/ban), `internal/clusteragent/rtmpnotify_test.go` (deny-set wiring; rewrite the three existing flood tests)

**Interfaces:**
- Consumes: `func (a *Agent) replicaSettings() map[string]any` (leasefence.go:42); `func settingInt(v any) (int64, bool)` (leasefence.go:76); `func spoolP0(dir string, events []map[string]any) error` (registry.go:568) — call `spoolP0(a.SpoolDir, …)`; `a.FloodDir` (Task 3); `a.Client.State.ServerID`; `writeFileAtomic`/the package's atomic write helper (replica.go uses `writeFileAtomic`).
- Produces:
  - `func (a *Agent) floodTick(ip string)` — the faithful port of `BruteforceGuard::checkFlood` (algorithm below). No-op unless `flood_limit > 0`; honours exclusions; on over-limit writes the `block_<ip>` marker and spools the P0 event.
  - `func (a *Agent) allowedIPs() map[string]bool` — the exclusion set (port of `ServerRepository::getAllowedIPs`, below).
  - `func (a *Agent) blockedIPs() map[string]bool` — the materialised blocklist IP set: `blocklist.json` → `.data.ip` (`[]string`).
  - `func (a *Agent) rtmpFloodDeny(w http.ResponseWriter, reason, ip string)` — `a.floodTick(ip)` then `a.rtmpDeny(w, reason)` (the "denied-and-counts" paths).

**The `floodTick` algorithm (faithful to BruteforceGuard::checkFlood, src/Core/Auth/BruteforceGuard.php:141-190):**
1. `lim, ok := floodInt("flood_limit")`; if `!ok || lim == 0` → return (off).
2. `if net.ParseIP(ip) == nil` → return. `if a.allowedIPs()[ip]` → return.
3. `floodExclude` = split `settings["flood_ips_exclude"]` (string) on `,`, trim empties; `if excluded[ip]` → return.
4. `ipFile := filepath.Join(a.FloodDir, ip)`. If it does not exist → write `{"requests":0,"last_request":<now>}` (atomic) and return. **First sighting is 0, not 1.**
5. Else read + `json.Unmarshal` into `{Requests int64; LastRequest int64}`; a read/parse error → treat as a fresh `{0, now}` write and return (torn-read safe).
6. `secs, _ := floodInt("flood_seconds")`. If `now - row.LastRequest <= secs` (within window): `row.Requests++`; **if `lim > row.Requests`** → set `row.LastRequest = now`, write, return; **else** (`row.Requests >= lim`) → block (step 8).
7. Else (window expired): `row.Requests = 0; row.LastRequest = now`; write; return.
8. Block: `if !a.blockedIPs()[ip] { spoolP0(a.SpoolDir, []map[string]any{{"type":"security.block_ip","d":map[string]any{"ip":ip,"reason":"FLOOD ATTACK"}}}) }`; always `touch(filepath.Join(a.FloodDir,"block_"+ip))`; `os.Remove(ipFile)`; return.

`floodInt(key)` = `settingInt(a.replicaSettings()[key])`. Writes use the package atomic writer to coexist with php-fpm's `LOCK_EX` writes of the same file (both best-effort during the transition).

**The `allowedIPs` set (faithful to ServerRepository::getAllowedIPs, src/Domain/Server/ServerRepository.php:510-544):** start with `127.0.0.1`; add this node's own `server_ip` (the `servers.json` row whose `id == a.Client.State.ServerID`); for **every** server row in `servers.json` `data.servers[]`: add `server_ip`, `private_ip` (if non-empty), each valid-IP entry of the `domain_name` CSV, and every element of `whitelist_ips` (a JSON-array-encoded **string** — `json.Unmarshal` the inner string into `[]string`; `null`/`""`/malformed → skip); then add each CSV entry of `settings["allowed_ips_admin"]`. A missing `servers.json` still yields `{127.0.0.1}`. *(Ruling — the design abbreviated this to "127.0.0.1 + own server_ip + whitelist_ips"; the faithful rtmp.php path excludes the broader set, so the port uses the full `getAllowedIPs`.)*

- [ ] **Step 1: Write the failing counter/ban test**

```go
func floodSettings(t *testing.T, dir string, kv map[string]any) {
	t.Helper()
	b, _ := json.Marshal(map[string]any{"data": kv})
	if err := os.WriteFile(filepath.Join(dir, "settings.json"), b, 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestFloodTickBansAtLimit(t *testing.T) {
	_, st := newFake(t) // st.ServerID == 3 (matches the node's own id below)
	a := &Agent{ReplicaDir: t.TempDir(), FloodDir: t.TempDir(), SpoolDir: t.TempDir(), Client: NewClient(st, "t")}
	floodSettings(t, a.ReplicaDir, map[string]any{"flood_limit": 3, "flood_seconds": 100})
	ip := "203.0.113.9"
	for i := 0; i < 4; i++ { // first=0, then 1,2,3 -> blocks on the 4th (requests>=3)
		a.floodTick(ip)
	}
	if _, err := os.Stat(filepath.Join(a.FloodDir, "block_"+ip)); err != nil {
		t.Fatalf("expected block marker: %v", err)
	}
	// P0 event spooled with reason exactly "FLOOD ATTACK"
	assertP0BlockIP(t, a.SpoolDir, ip, "FLOOD ATTACK")
}

func TestFloodTickOffWhenLimitZero(t *testing.T) {
	_, st := newFake(t)
	a := &Agent{ReplicaDir: t.TempDir(), FloodDir: t.TempDir(), SpoolDir: t.TempDir(), Client: NewClient(st, "t")}
	floodSettings(t, a.ReplicaDir, map[string]any{"flood_limit": 0})
	for i := 0; i < 50; i++ {
		a.floodTick("203.0.113.9")
	}
	if _, err := os.Stat(filepath.Join(a.FloodDir, "block_203.0.113.9")); err == nil {
		t.Fatal("flood_limit=0 must never ban")
	}
}

func TestFloodTickExclusions(t *testing.T) {
	_, st := newFake(t)
	a := &Agent{ReplicaDir: t.TempDir(), FloodDir: t.TempDir(), SpoolDir: t.TempDir(), Client: NewClient(st, "t")}
	floodSettings(t, a.ReplicaDir, map[string]any{"flood_limit": 1, "flood_seconds": 100, "flood_ips_exclude": "5.5.5.5"})
	writeServers(t, a.ReplicaDir, []map[string]any{
		{"id": 3, "server_ip": "9.9.9.9", "private_ip": "", "whitelist_ips": `["7.7.7.7"]`, "domain_name": ""},
		{"id": 4, "server_ip": "8.8.8.8", "private_ip": "", "whitelist_ips": nil, "domain_name": ""}, // null whitelist must be skipped, not crash
	}) // node's own id (st.ServerID) is 3
	// "not-an-ip" exercises the net.ParseIP guard (must return before writing a block_ marker)
	for _, ip := range []string{"127.0.0.1", "9.9.9.9", "7.7.7.7", "5.5.5.5", "not-an-ip"} {
		for i := 0; i < 5; i++ {
			a.floodTick(ip)
		}
		if _, err := os.Stat(filepath.Join(a.FloodDir, "block_"+ip)); err == nil {
			t.Fatalf("%s must be excluded from banning", ip)
		}
	}
}

func TestFloodTickAlreadyBlockedSkipsSpool(t *testing.T) {
	_, st := newFake(t)
	a := &Agent{ReplicaDir: t.TempDir(), FloodDir: t.TempDir(), SpoolDir: t.TempDir(), Client: NewClient(st, "t")}
	floodSettings(t, a.ReplicaDir, map[string]any{"flood_limit": 1, "flood_seconds": 100})
	writeBlocklist(t, a.ReplicaDir, map[string]any{"ip": []string{"203.0.113.9"}})
	for i := 0; i < 3; i++ {
		a.floodTick("203.0.113.9")
	}
	if _, err := os.Stat(filepath.Join(a.FloodDir, "block_203.0.113.9")); err != nil {
		t.Fatal("marker still touched for an already-blocked IP")
	}
	assertNoP0(t, a.SpoolDir) // getBlockedIPs() guard: no P0 for an already-listed IP
}

func TestFloodTickWindowExpiryResets(t *testing.T) {
	// A counter outside the window resets to requests:0 and does NOT block, even from a high count.
	_, st := newFake(t)
	a := &Agent{ReplicaDir: t.TempDir(), FloodDir: t.TempDir(), SpoolDir: t.TempDir(), Client: NewClient(st, "t")}
	floodSettings(t, a.ReplicaDir, map[string]any{"flood_limit": 2, "flood_seconds": 100})
	ip := "203.0.113.9"
	b, _ := json.Marshal(map[string]any{"requests": 5, "last_request": time.Now().Unix() - 1000}) // expired
	if err := os.WriteFile(filepath.Join(a.FloodDir, ip), b, 0o600); err != nil {
		t.Fatal(err)
	}
	a.floodTick(ip)
	if _, err := os.Stat(filepath.Join(a.FloodDir, "block_"+ip)); err == nil {
		t.Fatal("an expired window must reset, not ban")
	}
	raw, _ := os.ReadFile(filepath.Join(a.FloodDir, ip))
	var row struct{ Requests int64 `json:"requests"` }
	_ = json.Unmarshal(raw, &row)
	if row.Requests != 0 {
		t.Fatalf("expired window must reset requests to 0, got %d", row.Requests)
	}
}
```

Add `writeServers` (mirror `data.servers[]`) and the P0 assertion helpers (`assertP0BlockIP` reads the newest `*.ndjson` under `<SpoolDir>/p0/`, decodes each line, and asserts one `{type:"security.block_ip", d:{ip, reason}}`; `assertNoP0` asserts the dir is empty/absent) in `rtmpgates_test.go`. Reuse `newState`/`NewClient` the way `newFake` seeds `ServerID` (the rtmp rig uses ServerID 3); if a lighter state constructor exists in the test files, use it.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestFloodTick' -v`
Expected: FAIL — `a.floodTick` undefined.

- [ ] **Step 3: Implement `floodTick`, `allowedIPs`, `blockedIPs`, `floodInt` in `rtmpgates.go`**

Per the algorithm and set definitions above. `blockedIPs` reuses the same `blocklist.json` read shape as `allowedRTMP` (`.data.ip` `[]string`). `writeServers`/servers parsing reads `<ReplicaDir>/servers.json` → `.data.servers[]` with fields `id, server_ip, private_ip, whitelist_ips, domain_name` (all the exclusion needs).

- [ ] **Step 4: Run it to verify it passes**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestFloodTick' -v`
Expected: PASS.

- [ ] **Step 5: Write the failing concurrent-write test (Review Focus)**

```go
func TestFloodTickConcurrent(t *testing.T) {
	_, st := newFake(t)
	a := &Agent{ReplicaDir: t.TempDir(), FloodDir: t.TempDir(), SpoolDir: t.TempDir(), Client: NewClient(st, "t")}
	floodSettings(t, a.ReplicaDir, map[string]any{"flood_limit": 1000000, "flood_seconds": 100})
	var wg sync.WaitGroup
	for i := 0; i < 50; i++ {
		wg.Add(1)
		go func() { defer wg.Done(); a.floodTick("203.0.113.9") }()
	}
	wg.Wait() // must not panic or leave an unparseable file
	if b, err := os.ReadFile(filepath.Join(a.FloodDir, "203.0.113.9")); err == nil {
		var row struct{ Requests, LastRequest int64 }
		if json.Unmarshal(b, &row) != nil {
			t.Fatal("counter file must stay valid JSON under concurrency")
		}
	}
}
```

- [ ] **Step 6: Run it; verify it passes (atomic write holds)**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run TestFloodTickConcurrent -race -v` — if `-race` is unavailable in this environment (no C toolchain), run without `-race` and note it.
Expected: PASS, no panic, file parses.

- [ ] **Step 7: Write the failing deny-set wiring tests; rewrite the three existing flood tests**

Replace `TestRtmpPlayAuthFailedTicksFlood`, `TestRtmpPlayRefusedNoFlood`, `TestRtmpFloodBounded` (which assert the removed in-memory `rtmpFloodCount`/`rtmpFloodMax`) with file-backed equivalents:

```go
func TestRtmpDenySetCountsTowardFlood(t *testing.T) {
	// publish-gate, no-tcurl/app, cred-array, and line AUTH_FAILED each tick; limit 1 -> block on the 2nd
	cases := []struct {
		name  string
		query string
		auth  func() // nil => no okAuth
	}{
		{"publish_gate", "addr=203.0.113.9&clientid=1&call=publish&name=100&password=wrong", nil},
		{"no_tcurl", "addr=203.0.113.9&clientid=1&call=play&name=100&username=u&password=p", nil},
		{"cred_array", "addr=203.0.113.9&clientid=1&call=play&name=100&tcurl=rtmp://h/live&app=live", nil},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			rig := newRtmpHandlerRig(t)
			rig.a.FloodDir = t.TempDir()
			rig.a.SpoolDir = t.TempDir()
			floodSettings(t, rig.a.ReplicaDir, map[string]any{"flood_limit": 1, "flood_seconds": 100})
			for i := 0; i < 2; i++ {
				rig.do(c.query, nil)
			}
			if _, err := os.Stat(filepath.Join(rig.a.FloodDir, "block_203.0.113.9")); err != nil {
				t.Fatalf("%s should count toward flood: %v", c.name, err)
			}
		})
	}
}

func TestRtmpAuthFailedCountsRefusedDoesNot(t *testing.T) {
	// AUTH_FAILED ticks; a REFUSED (the rig's default refusal) and a parse-failure do not.
	rig := newRtmpHandlerRig(t)
	rig.a.FloodDir = t.TempDir()
	rig.a.SpoolDir = t.TempDir()
	floodSettings(t, rig.a.ReplicaDir, map[string]any{"flood_limit": 1, "flood_seconds": 100})
	rig.auth = func(w http.ResponseWriter, reqCtx []byte) { /* box ok:false, reason AUTH_FAILED */ }
	line := "addr=203.0.113.9&clientid=1&call=play&name=100&tcurl=rtmp://h/live&app=live&username=u&password=p"
	for i := 0; i < 2; i++ {
		rig.do(line, nil)
	}
	if _, err := os.Stat(filepath.Join(rig.a.FloodDir, "block_203.0.113.9")); err != nil {
		t.Fatal("AUTH_FAILED must count")
	}

	rig2 := newRtmpHandlerRig(t) // default answer => a verified REFUSED
	rig2.a.FloodDir = t.TempDir()
	floodSettings(t, rig2.a.ReplicaDir, map[string]any{"flood_limit": 1, "flood_seconds": 100})
	for i := 0; i < 5; i++ {
		rig2.do(line, nil)                                           // REFUSED
		rig2.do("addr=203.0.113.9&clientid=1&call=bogus&q", nil)     // parse failure
	}
	if _, err := os.Stat(filepath.Join(rig2.a.FloodDir, "block_203.0.113.9")); err == nil {
		t.Fatal("REFUSED and parse-failure must not count toward flood")
	}
}
```

(Use whatever boxing helper `rig.okAuth` uses to set an `ok:false, reason:"AUTH_FAILED"` reply; mirror `rtmpoffline_test.go`'s reply construction.)

- [ ] **Step 8: Run them to verify they fail**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestRtmpDenySet|TestRtmpAuthFailedCounts' -v`
Expected: FAIL — only the AUTH_FAILED path ticks today, and it uses the in-memory counter (no marker written).

- [ ] **Step 9: Wire the deny set + remove the in-memory counter (`rtmpnotify.go`, `agent.go`)**

- Add `rtmpFloodDeny(w, reason, ip)` per Interfaces.
- Switch the **counting** deny sites to it: publish gate (~41, `"publish gate"`), no-tcurl/app (~61), cred-array (~66), and the line `AUTH_FAILED` branch (~72-76 — replace the `if reply.Reason=="AUTH_FAILED" { a.rtmpFlood(...) }` + `rtmpDeny` with: AUTH_FAILED → `rtmpFloodDeny(w, "line "+reply.Reason, n.Addr)`; any other reason (REFUSED/NO_ANSWER) → plain `rtmpDeny(w, "line "+reply.Reason)`).
- Leave **non-counting** denies on plain `rtmpDeny`: parse (~21), `"blocked"` (Task 3), `"not held"` (~84), `"on_demand"` (Task 2), `"record"` (~95).
- Delete `rtmpFlood`, `rtmpFloodCount`, `const rtmpFloodMax`, and the `rtmpFloodMu`/`rtmpFloodN` fields in `agent.go`. (`// ponytail: file-backed counter is bounded by disk like PHP; no in-memory map to cap`.)

- [ ] **Step 10: Run the Gate-3 tests + the whole rtmp suite to verify + no regression**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestFloodTick|TestRtmp' -v`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add internal/clusteragent/rtmpgates.go internal/clusteragent/rtmpgates_test.go internal/clusteragent/rtmpnotify.go internal/clusteragent/rtmpnotify_test.go internal/clusteragent/agent.go
git commit -m "feat(cluster): ban RTMP flooders with a file-backed counter and P0 block event"
```

---

### Task 5: Full-suite green + vet

**Files:** none (verification task).

- [ ] **Step 1: Run the whole package suite**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ 2>&1 | tail -20`
Expected: `ok  github.com/Vateron-Media/XC_VM_Fanout/internal/clusteragent`.

- [ ] **Step 2: Run the repo build + vet**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go build ./... && GOWORK=off go vet ./...`
Expected: both clean, no output.

- [ ] **Step 3: Run the interop suite (rtmp vectors, if present)**

Run: `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./... -run Interop 2>&1 | tail -20`
Expected: PASS (or "no tests to run" if none match — note which).

No commit (verification only); if a step fails, fix under the owning task and re-run.
