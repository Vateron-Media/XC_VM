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

## 🚀 Overview

**XC_VM** is an open-source IPTV platform based on Xtream Codes.  
It enables:

- 📺 Live & VOD streaming
- 🔀 Load balancing
- 📊 Full user/reseller control
- 🎚️ Transcoding & EPG
- 🔐 Hardened security fixes

> ✅ 100% free. No license checks. No server locks.

---

## ⚠️ Status

> **BETA SOFTWARE** — actively developed 

---

## 🧱 Technology Stack

| Component  | Version | Description                     |
|------------|---------|---------------------------------|
| PHP        | 8.2     | Backend runtime                 |
| Nginx      | 1.24    | Web server & reverse proxy      |
| FFmpeg     | 4.4     | Media transcoding & processing  |
| MariaDB    | 10.6+   | SQL database engine             |
| KeyDB      | 6.3.4   | Cache & session storage (Redis) |

---

## 📥 Quick Install

> ✅ Ubuntu 22.04 or newer

```bash
# 1. Update system
sudo apt update && sudo apt full-upgrade -y

# 2. Install dependencies
sudo apt install -y python3-pip unzip

# 3. Download latest release
latest_version=$(curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest | grep '"tag_name":' | cut -d '"' -f 4)
wget "https://github.com/Vateron-Media/XC_VM/releases/download/${latest_version}/XC_VM.zip"

# 4. Unpack and install
unzip XC_VM.zip
sudo python3 install
````

---

## 🧰 Service Management

```bash
sudo systemctl start xc_vm     # Start
sudo systemctl stop xc_vm      # Stop
sudo systemctl restart xc_vm   # Restart
sudo systemctl status xc_vm    # Status
sudo systemctl reload nginx    # Reload Nginx config
journalctl -u xc_vm -f         # Live logs
```

---

## 📚 Documentation

* 🇬🇧 [English Guide](doc/en/main-page.md)
* 🇷🇺 [Руководство на русском](doc/ru/main-page.md)

---

## 🧮 Server Requirements & Sizing

### 🔧 Minimum Specs

| Component | Recommendation                |
| --------- | ----------------------------- |
| CPU       | 6+ cores (Xeon/Ryzen)         |
| RAM       | 16–32 GB                      |
| Disk      | SSD/NVMe, 480+ GB             |
| Network   | Dedicated 1 Gbps port         |
| OS        | Ubuntu 20.04+ (clean install) |

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
| CPU (transcoded) | \~1 core        |

---

## ✅ Features

* ✅ **No server restrictions**
* ✅ **EPG importer**
* ✅ **VOD management**
* ✅ **User/reseller panel**
* ✅ **Security patches**
* ✅ **Clean UI**

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

## 📝 License

[AGPL v3.0](LICENSE)

---

## ⚠️ Legal Disclaimer

> 🚫 **This software is for educational purposes only.**
> ⚖️ You are solely responsible for how it is used.
> We take no responsibility for misuse or illegal deployments.

---
