#!/bin/bash
# xc_agent keepalive supervisor (MAIN <-> LB cluster API). Started from
# `service` boot() and by the RootSignals cron keepalive, as xc_vm:
#   sudo -u xc_vm bash /home/xc_vm/bin/xc_agent/run.sh &
#
# Runs only on an enrolled node (config/cluster/agent.json exists), or on MAIN
# once its data-plane client was switched on (config/cluster/main.json, from
# `console.php cluster:main-dataplane on`): there it runs
# `xc_agent run -role main` with MAIN's own key (main_agent.json). One
# supervisor at a time (flock), one agent at a time (the loop blocks on it). The
# agent exits 3 when MAIN has stopped the node (revoked, unknown, or its
# enrolment never completed; an expired token re-keys instead): the
# supervisor then writes `stopped` and exits, and
# nothing restarts it until the node is enrolled again, which removes the file.
#
# A binary `fanout_binary` just installed is on trial (xc_agent.trial,
# "<installed at> <failed starts> [reach]"): if it exits within 60 s of its
# start three times within TRIAL_SEC of the install, the one it replaced
# (xc_agent.prev) is put back. A run that lasts, an exit MAIN asked for, or the
# end of the trial ends it. With `reach` (an agent that says it writes
# config/cluster/reached at each heartbeat MAIN answers), lasting is not
# enough: the trial ends once it has reached MAIN during one of its runs, and
# if it has not within REACH_SEC of the install, the previous one is put back.
# XCVM_AGENT_HOME and XCVM_AGENT_REACH_SEC are for tests: sudo passes neither on.
SCRIPT="${XCVM_AGENT_HOME:-/home/xc_vm}"
AGENT_DIR="$SCRIPT/bin/xc_agent"
STATE="$SCRIPT/config/cluster/agent.json"
MAIN_ID="$SCRIPT/config/cluster/main.json"
MAIN_STATE="$SCRIPT/config/cluster/main_agent.json"
LOG="$AGENT_DIR/xc_agent.log"
TRIAL_SEC=600
REACH_SEC="${XCVM_AGENT_REACH_SEC:-300}"
WATCH_SEC=$(( REACH_SEC < 10 ? 1 : 5 ))
REACHED="$SCRIPT/config/cluster/reached"

exec 9>"$AGENT_DIR/run.lock"
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || exit 0
fi
echo "=== $(date '+%F %T') supervisor pid=$$ start ===" >> "$LOG"

# rollback <why>: put the previous binary back and end the trial.
rollback() {
  mv -f "$AGENT_DIR/xc_agent.prev" "$AGENT_DIR/xc_agent"
  rm -f "$AGENT_DIR/xc_agent.trial"
  echo "=== $(date '+%F %T') $1: the previous one is back ===" >> "$LOG"
}

# trial <started at> <exit code>: judge a run of a binary on trial.
trial() {
  local now at fails reach lasted
  [ -f "$AGENT_DIR/xc_agent.trial" ] || return 0
  now=$(date +%s)
  read -r at fails reach < "$AGENT_DIR/xc_agent.trial"
  lasted=$(( now - $1 >= 60 ))
  [ "$reach" = "reach" ] && lasted=0
  if [ $((now - ${at:-0})) -gt "$TRIAL_SEC" ] || [ "$lasted" = "1" ] || [ "$2" = "3" ]; then
    rm -f "$AGENT_DIR/xc_agent.trial"
    return 0
  fi
  [ $((now - $1)) -ge 60 ] || fails=$(( ${fails:-0} + 1 ))
  if [ "${fails:-0}" -ge 3 ] && [ -x "$AGENT_DIR/xc_agent.prev" ]; then
    rollback "the new agent failed at start $fails times"
  else
    echo "$at ${fails:-0} $reach" > "$AGENT_DIR/xc_agent.trial"
  fi
}

# reach_watch <pid> <started at>: beside a run of a binary on trial for
# reaching MAIN, end the trial once this run has (the agent touched REACHED
# since the run started, so not the agent it replaced), or put the previous
# binary back and end the run when it has not within REACH_SEC of the install.
reach_watch() {
  local at fails reach
  while [ -f "$AGENT_DIR/xc_agent.trial" ]; do
    read -r at fails reach < "$AGENT_DIR/xc_agent.trial"
    [ "$reach" = "reach" ] || return 0
    if [ -f "$REACHED" ] && [ "$(stat -c %Y "$REACHED")" -gt "$2" ]; then
      rm -f "$AGENT_DIR/xc_agent.trial"
      echo "=== $(date '+%F %T') the new agent reached MAIN: its trial is over ===" >> "$LOG"
      return 0
    fi
    if [ $(( $(date +%s) - ${at:-0} )) -ge "$REACH_SEC" ] && [ -x "$AGENT_DIR/xc_agent.prev" ]; then
      rollback "the new agent did not reach MAIN within ${REACH_SEC}s of its install"
      kill "$1" 2>/dev/null
      return 0
    fi
    sleep "$WATCH_SEC"
  done
}

while true; do
  if [ -f "$MAIN_ID" ] && [ -f "$MAIN_STATE" ]; then
    # MAIN: its data-plane client. main.json says whether it serves; an
    # identity that changed ends the agent, and the loop starts it anew.
    if [ -x "$AGENT_DIR/xc_agent" ]; then
      pkill -u xc_vm -x xc_agent 2>/dev/null
      started=$(date +%s)
      "$AGENT_DIR/xc_agent" run -role main -state "$MAIN_STATE" >> "$LOG" 2>&1
      rc=$?
      echo "=== $(date '+%F %T') main agent exited rc=$rc ===" >> "$LOG"
      trial "$started" "$rc"
    fi
    sleep 2
    continue
  fi
  if [ ! -f "$STATE" ] || [ -f "$AGENT_DIR/stopped" ]; then
    echo "=== $(date '+%F %T') supervisor pid=$$ exiting: not enrolled or stopped by MAIN ===" >> "$LOG"
    exit 0
  fi
  if [ -x "$AGENT_DIR/xc_agent" ]; then
    # Serve-only LB (Phase A): seed the fanout with the node's assigned streams
    # from the replica (replaces PHP seedLocal) and carry its id + fanout binary
    # so a transcode directive resolves {server_id}/{binary}. The marker's content
    # is the node's server id; absent on a legacy node, so no extra flags there.
    # Gated on the serve_only marker AND on the agent knowing -seed-fanout (an
    # older agent binary exits non-zero on an unknown flag, which would fail the
    # install's agent-start check and crash-loop here; without the flags it just
    # enrols normally and seeding activates once a capable binary is in place —
    # the same self-healing the fanout front gate uses).
    SEED=()
    if [ -f "$SCRIPT/config/cluster/serve_only" ] && "$AGENT_DIR/xc_agent" run -h 2>&1 | grep -q -- '-seed-fanout'; then
      SEED=(-seed-fanout -server-id "$(cat "$SCRIPT/config/cluster/serve_only")" -fanout-binary "$SCRIPT/bin/xc_fanout/xc_fanout" -logs "$SCRIPT/logs/tmp/")
    fi
    pkill -u xc_vm -x xc_agent 2>/dev/null
    started=$(date +%s)
    "$AGENT_DIR/xc_agent" run -state "$STATE" "${SEED[@]}" >> "$LOG" 2>&1 &
    pid=$!
    watcher=""
    if [ -f "$AGENT_DIR/xc_agent.trial" ]; then
      reach_watch "$pid" "$started" &
      watcher=$!
    fi
    wait "$pid"
    rc=$?
    [ -n "$watcher" ] && kill "$watcher" 2>/dev/null
    echo "=== $(date '+%F %T') agent exited rc=$rc ===" >> "$LOG"
    trial "$started" "$rc"
    if [ "$rc" = "3" ]; then
      date '+%F %T' > "$AGENT_DIR/stopped"
      exit 0
    fi
  fi
  sleep 2
done
