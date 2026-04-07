<p align="center">
  <img src="https://avatars.githubusercontent.com/u/149707645?s=400&v=4" width="220" alt="Vateron Media Logo"/>
</p>

<h1 align="center">XC_VM IPTV Panel</h1>
<p align="center">
  <b>Open-source, community-driven Xtream Codes panel</b><br>
  Built for modern IPTV workflows – powerful, scalable, and free.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Vateron-Media/XC_VM" /></a>
  <a href="https://github.com/Vateron-Media/XC_VM/stargazers"><img src="https://img.shields.io/github/stars/Vateron-Media/XC_VM?style=flat" /></a>
  <a href="https://github.com/Vateron-Media/XC_VM/issues"><img src="https://img.shields.io/github/issues/Vateron-Media/XC_VM" /></a>
</p>

---

# 📑 Table of Contents

<details>
<summary><strong>📘 Contents</strong></summary>

* 🏁 [Overview](#-overview)
* ⚠️ [Status](#️-status)
* 📚 [Documentation](#-documentation)
* 🧱 [Technology Stack](#-technology-stack)
* 🧩 [Supported OS](#-supported-operating-systems)
* 📥 [Quick Install](#-quick-install)
* 🧰 [Service Management](#-service-management)
* 📂 [Project Structure](#-project-structure)
* 🧮 [Server Requirements & Sizing](#-server-requirements--sizing)
* ⭐ [Features](#-features)
* 🐞 [Known Limitations](#-known-limitations)
* 🤝 [Contributing](#-contributing)
* ⚠️ [Legal & Licensing Notice](#️-legal--licensing-notice)
* 📜 [License (AGPL-3.0)](#-license-agpl-30)

</details>

---

## 🚀 Overview

**XC_VM** is an open-source IPTV platform based on Xtream Codes.
It enables:

* 📺 Live & VOD streaming
* 🔀 Load balancing
* 📊 Full user/reseller control
* 🎚️ Transcoding & EPG
* 🔐 Hardened security fixes

> ✅ 100% free. No license checks. No server locks.

---

## ⚠️ Status

> **BETA SOFTWARE** — actively developed

---

## 📚 Documentation

* 🇬🇧 **English Guide**
  [https://vateron-media.github.io/XC_VM/#/en-us/](https://vateron-media.github.io/XC_VM/#/en-us/)

* 🇷🇺 **Руководство на русском**
  [https://vateron-media.github.io/XC_VM/#/ru-ru/](https://vateron-media.github.io/XC_VM/#/ru-ru/)

---

## 🧱 Technology Stack

| Component | Version    | Description                     |
| --------- | ---------- | ------------------------------- |
| PHP       | 8.1.33        | Backend runtime                 |
| Nginx     | 1.24       | Web server & reverse proxy      |
| FFmpeg    | 8.0, 7.1, 4.0                        | Media transcoding & processing  |
| MariaDB   | 11.4      | SQL database engine             |
| KeyDB     | 6.3.4      | Cache & session storage (Redis) |
| yt-dlp    | 2025.07.21 | Audio/Video downloader          |

---

## 🐧 Supported Operating Systems

XC_VM supports multiple Linux distributions. Distribution-specific binaries (PHP, Nginx) are downloaded automatically from [XC_VM_Binaries](https://github.com/Vateron-Media/XC_VM_Binaries) during installation.

### Ubuntu

| Version    | Codename        | Status                |
| ---------- | --------------- | --------------------- |
| **18.04**  | Bionic Beaver   | ⚠️ *Legacy*           |
| **20.04**  | Focal Fossa     | ✅ Supported           |
| **22.04**  | Jammy Jellyfish | ✅ **Recommended**     |
| **24.04**  | Noble Numbat    | ✅ **Recommended**     |

### Debian

| Version    | Codename   | Status                |
| ---------- | ---------- | --------------------- |
| **11**     | Bullseye   | ✅ Supported           |
| **12**     | Bookworm   | ✅ **Recommended**     |
| **13**     | Trixie     | ✅ Supported           |

### RHEL-compatible (Rocky Linux, AlmaLinux, CentOS, RHEL)

| Version | Status             |
| ------- | ------------------ |
| **8**   | 🚧 *Not yet supported* |
| **9**   | 🚧 *Not yet supported* |

> ⚠️ RHEL-family support is planned but not yet available. The installer recognizes these distributions, but pre-built binaries are not provided yet.

---

### 💡 Recommendations

For new installations:

* 🟢 **Ubuntu 22.04 / 24.04 LTS**
* 🟢 **Debian 12**

> ⚠️ Ubuntu 18.04 is in legacy mode — it works but receives no priority fixes.

---

## 📥 Quick Install

> ✅ Ubuntu 22.04+, Debian 11+

```bash
# 1. Update system
sudo apt update && sudo apt full-upgrade -y

# 2. Install dependencies
sudo apt install -y curl wget python3 unzip

# 3. Download latest release
latest_version=$(curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest | grep '"tag_name":' | cut -d '"' -f 4)
wget "https://github.com/Vateron-Media/XC_VM/releases/download/${latest_version}/XC_VM.zip"

# 4. Unpack and install
unzip XC_VM.zip
sudo python3 install
```

### 🧪 Beta Install

To install the latest **beta** (pre-release) version:

```bash
# 1. Update system
sudo apt update && sudo apt full-upgrade -y

# 2. Install dependencies
sudo apt install -y curl wget python3-pip unzip

# 3. Download latest beta release
beta_version=$(curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases | grep -m1 '"tag_name":' | cut -d '"' -f 4)
wget "https://github.com/Vateron-Media/XC_VM/releases/download/${beta_version}/XC_VM.zip"

# 4. Unpack and install
unzip XC_VM.zip
sudo python3 install
```

> ⚠️ Beta versions may contain unstable features. Use on test servers only.

---

## 🧰 Service Management

```bash
sudo systemctl start xc_vm     # Start
sudo systemctl stop xc_vm      # Stop
sudo systemctl restart xc_vm   # Restart
sudo systemctl status xc_vm    # Status
sudo /home/xc_vm/bin/nginx/sbin/nginx -s reload    # Reload Nginx config
journalctl -u xc_vm -f         # Live logs
```

---

## 📂 Project Structure

```text.
├─ docs/        # 📚 Project documentation
├─ lb_configs/  # ⚙️ Configurations for building Load Balancer (LB)
└─ src/         # 💻 Main project code
```

---

## 🧮 Server Requirements & Sizing

### 🔧 Minimum Specs

| Component | Recommendation                |
| --------- | ----------------------------- |
| CPU       | 6+ cores (Xeon/Ryzen)         |
| RAM       | 16–32 GB                      |
| Disk      | SSD/NVMe, 480+ GB             |
| Network   | Dedicated 1 Gbps port         |
| OS        | Ubuntu 22.04+, Debian 12+ (clean install) |

---

### 📊 Planning Formulae

* **Bandwidth (Mbps)** = Channels × Bitrate
* **Max Users** = Bandwidth ÷ Stream Bitrate

```text
Example:
HD bitrate = 4 Mbps
1 Gbps = ~940 usable Mbps

→ Max Channels: 940 ÷ 4 = ~235
→ Max Users:    940 ÷ 4 = ~235
```

> ⚠️ 10 users watching the same channel = 10× bandwidth (unless caching or multicast used)

---

### 💻 RAM & CPU Usage

| Resource         | Load per Stream |
| ---------------- | --------------- |
| RAM              | 50–100 MB       |
| CPU (transcoded) | ~1 core         |

---

## ✅ Features

* ✅ No server restrictions
* ✅ EPG importer
* ✅ VOD management
* ✅ User/reseller panel
* ✅ Security patches
* ✅ Clean UI

---

## 🔧 Known Limitations

* ❌ Requires Linux knowledge
* ❌ Community-based support
* ❌ Some bugs in transcoding module (in progress)

---

## 🤝 Contributing

We welcome community help!

* 🛠️ [Contributing Guide](CONTRIBUTING.md)
* 👥 [Contributors List](CONTRIBUTORS.md)

---

### ⚠️ Legal & Project Independence Notice

**XC_VM** is an independent software project and is **not affiliated with or endorsed by XUI.one**.

All backend systems, core logic, and infrastructure of XC_VM have been **independently developed** without using source code from XUI.one or related projects.

The administrative interface (UI/UX) is **inspired by general industry practices and partially by XUI.one in terms of usability concepts only**.
No proprietary source code or protected assets have been intentionally reused.

---

### 📜 License Enforcement (AGPL-3.0)

XC_VM is distributed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**.

Under this license:

* Redistribution or modification is permitted **only under the same license (AGPL-3.0)**
* Any modified version **must remain open-source**
* Providing XC_VM as a service **requires making the source code available**
* **Copyright and attribution must be preserved**

Any attempt to redistribute this software under a different license, remove attribution, or obscure the origin of the project constitutes a violation of the license terms.

> ⚖️ You are solely responsible for how it is used.
> We take no responsibility for misuse or illegal deployments.

---
