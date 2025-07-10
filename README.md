# **XtreamUI OpenSource / XC_VM Server**  
[![Main version](https://img.shields.io/github/v/release/Vateron-Media/Xtream_main?label=Main%20Release&color=green)](https://github.com/Vateron-Media/Xtream_main/)
[![Sub version](https://img.shields.io/github/v/release/Vateron-Media/Xtream_lb?label=LB%20Release&color=blue)](https://github.com/Vateron-Media/Xtream_lb/)
[![License](https://img.shields.io/github/license/Vateron-Media/Xtream_main)](LICENSE)
[![Forks](https://img.shields.io/github/forks/Vateron-Media/Xtream_main?style=flat)](https://github.com/Vateron-Media/Xtream_main/fork)
[![Stars](https://img.shields.io/github/stars/Vateron-Media/Xtream_main?style=flat)](https://github.com/Vateron-Media/Xtream_main/stargazers)
[![Issues](https://img.shields.io/github/issues/Vateron-Media/Xtream_main)](https://github.com/Vateron-Media/Xtream_main/issues)
[![Pull Requests](https://img.shields.io/github/issues-pr/Vateron-Media/Xtream_main)](https://github.com/Vateron-Media/Xtream_main/pulls)
[![All Contributors](https://img.shields.io/badge/all_contributors-2-orange.svg)](CONTRIBUTORS.md)

---

## 📌 About the Project

XtreamUI OpenSource is a **community-driven** project based on publicly available Xtream Codes source code.

**XC_VM** is a powerful and scalable IPTV streaming server designed for efficient media content delivery. It supports various protocols and provides a modern, intuitive web panel.

---

## ⚠️ Warnings

🔴 **The panel is currently in ALPHA testing.**  
🔴 **Balancer (SUB) is NOT fully functional and will be fixed after core panel issues are resolved.**  
🛑 **Avoid Cyrillic characters or special symbols** in filenames when uploading via the panel.

---

## 📂 Project Repositories

| Repository | Description |
|------------|-------------|
| [🔹 XC_VM](https://github.com/Vateron-Media/Xtream_main) | streaming panel |
| [⚙ Xtream Build](https://github.com/Vateron-Media/Xtream_build) | Build scripts and tools |

---

## 💾 Installation Guide

✅ **Supported OS:** Ubuntu 22.04+

### 1️⃣ Update the system
```sh
sudo apt update && sudo apt full-upgrade -y
````

### 2️⃣ Install required packages

```sh
sudo apt install -y python3-pip git
```

### 3️⃣ Clone and run the installer

```sh
git clone https://github.com/Vateron-Media/Xtream_install
cd Xtream_install/
pip3 install -r requirements.txt
sudo python3 install.py
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

* [🇬🇧 English Guide](https://github.com/Vateron-Media/Xtream_main/blob/main/doc/en/main-page.md)
* [🇷🇺 Руководство на русском](https://github.com/Vateron-Media/Xtream_main/blob/main/doc/ru/main-page.md)

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
