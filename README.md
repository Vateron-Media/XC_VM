# **XtreamUI OpenSource / XC_VM Server**  
[![Last version](https://img.shields.io/github/v/release/Vateron-Media/XC_VM?label=Main%20Release&color=green)](https://github.com/Vateron-Media/XC_VM/)
[![License](https://img.shields.io/github/license/Vateron-Media/XC_VM)](LICENSE)
[![Forks](https://img.shields.io/github/forks/Vateron-Media/XC_VM?style=flat)](https://github.com/Vateron-Media/XC_VM/fork)
[![Stars](https://img.shields.io/github/stars/Vateron-Media/XC_VM?style=flat)](https://github.com/Vateron-Media/XC_VM/stargazers)
[![Issues](https://img.shields.io/github/issues/Vateron-Media/XC_VM)](https://github.com/Vateron-Media/XC_VM/issues)
[![Pull Requests](https://img.shields.io/github/issues-pr/Vateron-Media/XC_VM)](https://github.com/Vateron-Media/XC_VM/pulls)
[![All Contributors](https://img.shields.io/badge/all_contributors-0-orange.svg)](CONTRIBUTORS.md)

[![PHP 8.2](https://img.shields.io/badge/PHP-8.1.33-blue?logo=php&logoColor=white)]()
[![Nginx 1.24](https://img.shields.io/badge/Nginx-1.28.0-brightgreen?logo=nginx&logoColor=white)]()
[![FFmpeg 4.4+](https://img.shields.io/badge/FFmpeg-4.4-critical?logo=ffmpeg&logoColor=white)]()

---

<p align="center">
  <img src="https://avatars.githubusercontent.com/u/149707645?s=400&v=4" alt="Vateron Media Logo" width="400" />
</p>

---

## 📌 About the Project

XtreamUI OpenSource is a **community-driven** project based on publicly available Xtream Codes source code.

**XC_VM** is a powerful and scalable IPTV streaming server designed for efficient media content delivery. It supports various protocols and provides a modern, intuitive web panel.

---

## ⚠️ Warnings

🔴 **The panel is currently in BETA testing.**  
🛑 **Avoid Cyrillic characters or special symbols** in filenames when uploading via the panel.

---

## 📂 Project Repositories

| Repository | Description |
|------------|-------------|
| [🔹 XC_VM](https://github.com/Vateron-Media/XC_VM) | streaming panel |
| [⚙ Xtream Build](https://github.com/Vateron-Media/Xtream_build) | Build scripts and tools |

---

## 💾 Installation Guide

✅ **Supported OS:** Ubuntu 22.04+

---

### 1️⃣ Update Your System

Make sure your system is up to date:

```bash
sudo apt update && sudo apt full-upgrade -y
```

---

### 2️⃣ Install Required Dependencies

Install essential packages:

```bash
sudo apt install -y python3-pip unzip
```

---

### 3️⃣ Get the Latest Release

Check the latest available version here:
👉 [Latest Release](https://github.com/Vateron-Media/XC_VM/releases/latest)

---

### 4️⃣ Download the Release Package

Replace `v1.x.x` with the actual release version:

```bash
wget https://github.com/Vateron-Media/XC_VM/releases/download/v1.x.x/XC_VM.zip
```

---

### 5️⃣ Unzip and Install

Extract the package and run the installer:

```bash
unzip XC_VM.zip
sudo python3 install
```

---

## 🛠️ Panel Management

Use `systemctl` to manage the Xtream Codes service:

```sh
sudo systemctl start xc_vm
```

| Command   | Description                 |
| --------- | --------------------------- |
| `start`   | Start the panel             |
| `stop`    | Stop the panel              |
| `restart` | Restart the panel           |
| `reload`  | Reload Nginx configuration  |
| `status`  | View current service status |

Real-time logs:

```sh
journalctl -u xc_vm -f
```

---

## 📚 Documentation

* [🇬🇧 English Guide](https://github.com/Vateron-Media/XC_VM/blob/main/doc/en/main-page.md)
* [🇷🇺 Руководство на русском](https://github.com/Vateron-Media/XC_VM/blob/main/doc/ru/main-page.md)

---

## 🤝 Contributing

We welcome community contributions!
Check out our [CONTRIBUTING.md](CONTRIBUTING.md) and view all [contributors](CONTRIBUTORS.md).

---

## 📜 License

This project is licensed under the [AGPL-3.0 License](LICENSE).

---

## ⚠ Disclaimer

📌 **This project is intended for educational purposes only.**
📌 **Use responsibly and in compliance with your local laws.**
📌 **We do not take responsibility for any misuse.**

---
