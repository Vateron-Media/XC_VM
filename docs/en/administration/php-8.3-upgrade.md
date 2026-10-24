# Upgrading PHP to 8.3

PHP 8.1 has reached end of life and gets no fix for the **php2shell** vulnerabilities. This guide moves the PHP runtime of every server in your cluster (MAIN and each load balancer) to **PHP 8.3** without waiting for a panel release.

Your panel version, database and settings do not change. Only the runtime bundle (PHP and nginx) and the `xcvm_core` extension are replaced.

!!! info "Panel 2.7.2 and newer"
    From 2.7.2, MAIN moves to PHP 8.3 on its own, at its next binaries update: the updater fetches the PHP 8.3 build of `xcvm_core` and points `php.ini` at the PHP 8.3 ionCube Loader before it stops the panel, and leaves the server as it is if either is missing. A load balancer updated to 2.7.2 or newer does the same within a minute of its panel update, and one installed from such a panel comes with PHP 8.3. On a load balancer that stays on an older panel, run this guide.

!!! danger "Do not skip step 1"
    Never install the PHP 8.3 bundle on a server that has not passed step 1. Without the PHP 8.3 build of `xcvm_core`, the panel does not start and cannot repair itself: you would need SSH access to roll it back.

    **If your MAIN's binaries channel is Beta** (Settings → Updates → Update Channel — Binaries), set it to **Stable** now: on some panel versions a Beta MAIN installs the PHP 8.3 bundle on its own at its next restart, without step 1.

---

## Before You Start

- **Panel version:** 2.5.0 or newer. An older panel has a single update channel for the panel and the binaries: update the panel first.
- **Supported systems:** Ubuntu 20.04, 22.04, 24.04 and Debian 12, 13. There is no PHP 8.3 build for any other system (Ubuntu 18, Debian 11, ...): upgrade the operating system first.
- **Access:** SSH as `root` to every server.
- **Disk:** about 1 GB free on `/` (`/root` and `/tmp`).
- **Downtime:** 1–3 minutes per server while the bundle is swapped. The streams served by that server reconnect afterwards.
- **Order:** MAIN first, then the load balancers **one at a time**.

---

## Step 1. Check and Prepare (every server, no downtime)

The panel keeps running on PHP 8.1 during this step. The script tests PHP 8.3 with your server's own `php.ini` and panel, then stages what PHP 8.3 needs. It changes nothing in the running panel.

Paste the whole block into the SSH session:

```bash
bash <<'EOF'
set -euo pipefail
trap 'echo "STOP: step failed at line $LINENO"' ERR
export LC_ALL=C
B=/home/xc_vm/bin
PHP=$B/php/bin/php
INI=$B/php/lib/php.ini
EXT83=$B/php/lib/php/extensions/no-debug-non-zts-20230831
REL=https://github.com/Vateron-Media/XC_VM_Binaries/releases/download/21102026
CORE=https://raw.githubusercontent.com/Vateron-Media/XC_VM_Binaries/4021565ebcbd51357d25e0547d4c970c3fbf1a65/bin/xcvm_extention
W=/root/php83

[ "$(id -u)" = 0 ] || { echo "STOP: run as root"; exit 1; }
. /etc/os-release
A="${ID}_${VERSION_ID%%.*}.tar.gz"
case "$A" in
    ubuntu_20.tar.gz|ubuntu_22.tar.gz|ubuntu_24.tar.gz|debian_12.tar.gz|debian_13.tar.gz) ;;
    *) echo "STOP: there is no PHP 8.3 build for $PRETTY_NAME"; exit 1 ;;
esac
rm -rf "$W"; mkdir -p "$W/rt"

# xcvm_core built for PHP 8.3
curl -fsSL "$CORE/SHA256SUMS" -o "$W/SHA256SUMS"
curl -fsSL "$CORE/xcvm_core-php8.3.tar.gz" -o "$W/xcvm_core-php8.3.tar.gz"
(cd "$W" && grep ' xcvm_core-php8.3.tar.gz$' SHA256SUMS | sha256sum -c --quiet -)
tar -xzf "$W/xcvm_core-php8.3.tar.gz" -C "$W" ./xcvm_core.so

# PHP 8.3 runtime, unpacked aside for the test only
curl -fsSL --retry 3 -o "$W/$A" "$REL/$A"
(cd "$W" && curl -fsSL "$REL/hashes.md5" | grep " $A\$" | md5sum -c --quiet -)
tar -xzf "$W/$A" -C "$W/rt"
P83=$(find "$W/rt" -path '*/php/bin/php' -type f | head -1)
X83=$(dirname "$(dirname "$P83")")/lib/php/extensions/no-debug-non-zts-20230831
cp "$W/xcvm_core.so" "$X83/"
ln -sfn ioncube_loader_lin_8.3.so "$X83/ioncube_loader_lin_8.1.so"

# PHP 8.3 must load every module PHP 8.1 loads here, and read the panel's
# config and database exactly as PHP 8.1 does.
"$P83" -v > /dev/null 2>&1 || { echo "STOP: PHP 8.3 does not run on this server:"; ldd "$P83" | grep 'not found' || true; exit 1; }
"$PHP" -c "$INI" -m 2>/dev/null | sort -u > "$W/modules-8.1"
"$P83" -c "$INI" -d extension_dir="$X83" -m 2>/dev/null | sort -u > "$W/modules-8.3"
MISSING=$(comm -23 "$W/modules-8.1" "$W/modules-8.3")
[ -z "$MISSING" ] || { echo "STOP: PHP 8.3 lacks:" $MISSING; exit 1; }
PROBE='$r = ["core" => class_exists("XC_VM"), "ioncube" => extension_loaded("ionCube Loader")];
try { $c = XC_VM::config_server(); $r["config"] = is_array($c) ? array_keys($c) : gettype($c); } catch (Throwable $e) { $r["config"] = "error"; }
try { $r["db"] = XC_VM::db_connect(false) instanceof PDO; } catch (Throwable $e) { $r["db"] = "error"; }
echo json_encode($r);'
O81=$("$PHP" -c "$INI" -r "$PROBE" 2>/dev/null) || true
O83=$("$P83" -c "$INI" -d extension_dir="$X83" -r "$PROBE" 2>/dev/null) || true
echo "PHP 8.1: $O81"
echo "PHP 8.3: $O83"
[ -n "$O83" ] && [ "$O83" = "$O81" ] || { echo "STOP: PHP 8.3 does not see the panel as PHP 8.1 does"; exit 1; }

# Stage for the real update. Your php.ini loads ioncube_loader_lin_8.1.so,
# and so do the php.ini files that panel updates bring: under PHP 8.3 that
# name points to the 8.3 loader.
install -D -m 0555 "$W/xcvm_core.so" "$EXT83/xcvm_core.so"
ln -sfn ioncube_loader_lin_8.3.so "$EXT83/ioncube_loader_lin_8.1.so"
curl -fsSL https://raw.githubusercontent.com/Vateron-Media/XC_VM/main/src/bin/install/update_binaries.sh -o "$W/update_binaries.sh"
rm -rf "$W/rt" "$W/$A"
echo "READY"
EOF
```

- **`READY`**: go on to the next step.
- **`STOP: ...`**: do not go on. The server is unchanged and keeps running on PHP 8.1. Open an [issue](https://github.com/Vateron-Media/XC_VM/issues) with the full output.

---

## Step 2. Keep the Binaries Channel on Stable (MAIN only)

In the admin panel, open **Settings → Updates** and check that **Update Channel — Binaries** is **Stable** (the default). If it is not, set it to Stable and save. Leave **Update Channel — Panel** as it is.

Load balancers do not need this: before 2.7.2 they never update their binaries on their own.

---

## Step 3. Install PHP 8.3 (every server: MAIN first, then each load balancer)

Run this only on a server whose step 1 printed `READY`:

```bash
nohup sh -c 'bash /root/php83/update_binaries.sh Vateron-Media XC_VM_Binaries /home/xc_vm/bin 21102026 && sed -i "s/\"release\": \"21102026\"/\"release\": \"29062026\"/" /home/xc_vm/bin/bin_version.json && { /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php root:own || true; }' > /root/php83/update.log 2>&1 &
tail -f /root/php83/update.log
```

The update keeps going if your SSH session drops. It ends with `Binaries updated successfully` (a panel older than 2.6.6 then also prints an unknown-command line for `root:own`, which is harmless); press `Ctrl+C` to leave the log. If anything fails along the way, the script puts the previous binaries back on its own.

The command records the installed bundle as `29062026`, the current Stable release. The panel checks for new binaries on every restart, and released panels rank `29062026` as the newest bundle on every channel: with the real tag recorded, the next restart would put PHP 8.1 back.

---

## Step 4. Verify

```bash
/home/xc_vm/bin/php/bin/php -r 'echo PHP_VERSION, " xcvm_core=", phpversion("xcvm_core"), " ioncube=", extension_loaded("ionCube Loader") ? "yes" : "no", "\n";'
systemctl is-active xc_vm
```

Expected output: `8.3.x xcvm_core=<version> ioncube=yes`, then `active`.

**Wait two minutes and run it again**: the restart's check for new binaries runs within a minute, so the second run is the one that counts. If it shows `8.1.x`, run step 3 again and open an [issue](https://github.com/Vateron-Media/XC_VM/issues) with `/root/php83/update.log`.

Then log in to the panel and play a stream served by this server. On MAIN, also open the pages of your installed modules.

---

## After the Upgrade

- **Keep the Stable binaries channel on MAIN.** On Beta, some panel versions install the Beta bundle at the next restart.
- **Panel updates** work as before; nothing needs to be repeated after them.
- **A load balancer installed later** comes with PHP 8.3 from a panel 2.7.2 or newer. Installed from an older panel, it needs steps 1, 3 and 4.
