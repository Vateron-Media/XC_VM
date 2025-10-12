<h1 align="center">🔄 XC_VM Update Guide</h1>

<p align="center">
  This document describes the process of updating <b>XC_VM</b> via the web control panel. Follow these steps to safely update the system and avoid errors.
</p>

<p align="center">
  <a href="../en/main-page.md"><b>⬅️ Back to Main Page</b></a>
</p>

---

## 📚 Navigation

* [⚙️ Before You Start](#before-you-start)
* [🪜 Step-by-Step Instructions](#step-by-step-instructions)
* [🧠 Notes and Recommendations](#notes-and-recommendations)

---

## ⚙️ Before You Start

Make sure all necessary resources are ready before updating. This helps prevent issues and data loss.

* 🔑 **Administrator access** to the control panel.
* 🌐 **Stable internet connection**.
* 💾 **Server data backup** *(strongly recommended before starting)*.

> ⚠️ **Important:** Do not interrupt the update process to avoid system corruption.

---

## 🪜 Step-by-Step Instructions

Follow these steps to update via the panel. Each step is illustrated with a screenshot for convenience.

### 1️⃣ Go to the **“Servers”** Section

* Log in to the control panel.
* Select **Servers** from the main menu.

  ![Servers](../img/update1.png)

### 2️⃣ Select **“Manage Servers”**

* In the **Servers** section, click **Manage Servers** to open the list of available servers.

  ![Manage Servers](../img/update2.png)

### 3️⃣ Open the **“Actions”** Menu

* Locate the server you want to update.
* Click the **Actions** button — usually a dropdown menu or an icon next to the server.

  ![Actions Menu](../img/update3.png)

### 4️⃣ Go to **“Server Tools”**

* In the **Actions** menu, select **Server Tools** to open server management utilities.

  ![Server Tools](../img/update4.png)

### 5️⃣ Run **“Update Server”**

* In **Server Tools**, click **Update Server**.
* Confirm the action if prompted (password may be required).
* Wait for the update to complete — **do not interrupt the process** to avoid errors.

  ![Update Server](../img/update5.png)

---

## 🧠 Notes and Recommendations

> 🕒 **Update Duration**
> Depends on server size and internet speed. Usually takes from a few minutes up to an hour.

> ✅ **Post-Update Check**
> After completion, verify server status, restart services, and ensure everything is working correctly.

> ⚠️ **Update Errors**
> If errors occur, check server logs (e.g., in `/home/xc_vm/logs/`). If the problem persists, create an issue in the [repository](https://github.com/Vateron-Media/XC_VM/issues).

---

<p align="center">
  <a href="../en/main-page.md"><b>⬅️ Back to Main Page</b></a>
</p>

---