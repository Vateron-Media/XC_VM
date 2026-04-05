#!/bin/bash
# Update Redis config with optimized parameters for XC_VM
# Safe to run on existing installations — preserves password and custom settings

set -euo pipefail

REDIS_CONF="/home/xc_vm/bin/redis/redis.conf"

if [[ ! -f "$REDIS_CONF" ]]; then
    echo "ERROR: $REDIS_CONF not found"
    exit 1
fi

echo "Updating Redis config: $REDIS_CONF"
cp "$REDIS_CONF" "${REDIS_CONF}.bak.$(date +%Y%m%d%H%M%S)"

# sed helper: replace value if key exists, skip otherwise
update_param() {
    local key="$1" value="$2"
    if grep -q "^${key} " "$REDIS_CONF"; then
        sed -i "s|^${key} .*|${key} ${value}|" "$REDIS_CONF"
        echo "  [OK] ${key} ${value}"
    else
        echo "${key} ${value}" >> "$REDIS_CONF"
        echo "  [ADD] ${key} ${value}"
    fi
}

# Network — keep bind * for LB connectivity
update_param "tcp-backlog"    "4096"
update_param "timeout"        "300"
update_param "tcp-keepalive"  "60"

# Performance
update_param "hz"             "100"

# Disable RDB snapshots — data is ephemeral connection cache
update_param "save"           '""'

# Non-blocking memory ops
update_param "lazyfree-lazy-eviction" "yes"
update_param "lazyfree-lazy-expire"   "yes"

echo ""
echo "Done. Restart Redis to apply:"
echo "  systemctl restart xc_vm"
