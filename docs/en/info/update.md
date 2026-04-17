# 🔄 XC_VM Update Guide

This document describes the process of updating XC_VM via the web control panel. Follow these steps to safely update the system and avoid errors.

---

## ⚙️ Before You Start

Make sure all necessary resources are ready before updating. This helps prevent issues and data loss.

- 🔑 **Administrator access** to the control panel.
- 🌐 **Stable internet connection**.
- 💾 **Server data backup** *(strongly recommended before starting)*.

> ⚠️ **Important:** Do not interrupt the update process to avoid system corruption.

---

## 🪜 Step-by-Step Instructions

Follow these steps to update via the panel. Each step is illustrated with a screenshot for convenience.

### 1️⃣ Go to the **“Servers”** Section

- Log in to the control panel.
- Select **Servers** from the main menu.

  ![Servers](../../_media/update1.png)

### 2️⃣ Select **“Manage Servers”**

- In the **Servers** section, click **Manage Servers** to open the list of available servers.

  ![Manage Servers](../../_media/update2.png)

### 3️⃣ Open the **“Actions”** Menu

- Locate the server you want to update.
- Click the **Actions** button — usually a dropdown menu or an icon next to the server.

  ![Actions Menu](../../_media/update3.png)

### 4️⃣ Go to **“Server Tools”**

- In the **Actions** menu, select **Server Tools** to open server management utilities.

  ![Server Tools](../../_media/update4.png)

### 5️⃣ Run **“Update Server”**

- In **Server Tools**, click **Update Server**.
- Confirm the action if prompted (password may be required).
- Wait for the update to complete — **do not interrupt the process** to avoid errors.

  ![Update Server](../../_media/update5.png)

---

## 💻 Update via CLI

If the web panel is unavailable or you prefer the command line, the update can be triggered directly via SSH.

### Connect and Run

1. Connect to the server via SSH.
1. If needed, make the console script executable:

```bash
sudo chmod +x /home/xc_vm/console.php
```

1. Run the command:

```bash
sudo -u xc_vm /home/xc_vm/console.php update update
```

> ⚠️ **Important:** The command must run as the `xc_vm` user. Do not run it as `root` directly.

### What Happens

- The system checks GitHub for a new version.
- The update archive is downloaded and its checksum (MD5) is verified.
- The system update script stops services, applies files, and runs post-update tasks.

---

## 🧠 Notes and Recommendations

> 🕒 **Update Duration**
> Depends on server size and internet speed. Usually takes from a few minutes up to an hour.
>
> ✅ **Post-Update Check**
> After completion, verify server status, restart services, and ensure everything is working correctly.
>
> ⚠️ **Update Errors**
> If errors occur, check server logs (e.g., in `/home/xc_vm/update.log`). If the problem persists, create an issue in the [repository](https://github.com/Vateron-Media/XC_VM/issues).

---
