<p align="center">
  <img src="https://avatars.githubusercontent.com/u/149707645?s=400&v=4" width="180" alt="Vateron Media Logo"/>
</p>

<h1 align="center">📺 XC_VM IPTV Panel</h1>

<p align="center">
  A modern, open-source IPTV panel inspired by Xtream Codes.<br>
  Lightweight. Fast. Community-driven.
</p>

---

## ❓ Что это такое

**XC_VM** — это современная IPTV-панель, работающая на PHP, Nginx, FFmpeg и MariaDB.

XC_VM помогает разворачивать полноценную IPTV-инфраструктуру:

- Управление Live, Movies и Series  
- Поддержка реселлеров и пользователей  
- Load Balancing  
- EPG и VOD система  
- Инструменты мониторинга и API

💡 *Полностью бесплатная. Без лицензий. Без сервер-локов.*

---

## ⭐ Особенности

- 🚀 Современная архитектура панели IPTV
- 🔀 Поддержка встроенного балансировщика нагрузки
- 🎥 Управление VOD и прямыми трансляциями
- 🧩 API, совместимый с Xtream Codes
- 🔐 Усиленные исправления безопасности
- 📦 Быстрое перекодирование на основе FFmpeg
- 🌍 Многоязычная документация
- 🧭 Простой и интуитивно понятный интерфейс
- 🛠 Модульная конструкция для расширений

---

## 🧰 Технологии

- **Nginx** — reverse proxy & web server  
- **PHP 8.1** — core backend  
- **MariaDB** — database  
- **KeyDB** — cache/session engine  
- **FFmpeg 8.0** — transcoding  
- **yt-dlp** — media acquiring  

XC_VM официально поддерживает Ubuntu 22.04 и тестируется на 24.04.

---

## 🌐 Сообщество

XC_VM — полностью управляемый сообществом проект.

- 💬 Issues: https://github.com/Vateron-Media/XC_VM/issues  
- ⭐ GitHub Stars: поддержите проект звездой  
- 🔧 Pull Requests: принимаются  

Если хочешь внести вклад — прочитай:  
[Contributing Guide](https://github.com/Vateron-Media/XC_VM/blob/master/CONTRIBUTING.md)

---

## 🛠 Установка

Установка на Ubuntu 22.04+:

```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y python3-pip unzip

latest_version=$(curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest | grep '"tag_name":' | cut -d '"' -f 4)
wget "https://github.com/Vateron-Media/XC_VM/releases/download/${latest_version}/XC_VM.zip"

unzip XC_VM.zip
sudo python3 install
```

---

## ⚠️ Отказ от ответственности

> Вы несете полную ответственность за собственное использование программного обеспечения.