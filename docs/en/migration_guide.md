<h1 align="center">🧭 XC_VM Migration Guide</h1>

<p align="center">
  Easily migrate from compatible IPTV systems using the built-in XC_VM migration tool.
</p>

<p align="center">
  <a href="../en/main-page.md"><b>⬅️ Back to Main Page</b></a>
</p>

---

## 📚 Navigation

* [⚙️ Before You Start](#before-you-start)
* [🚀 Migration Steps](#migration-steps)
* [🔑 Restoring Access After Migration](#restoring-access-after-migration)
* [🧩 Post-Migration](#post-migration)
* [🖥️ Load Balancer Preparation](#load-balancer-preparation)

---

## ⚙️ Before You Start

> 💡 **Recommendation:**
> It’s best to perform migration on a **fresh XC_VM installation**.

If you decide to use an **existing installation**, note the following:

* XC_VM **will delete all tables** in your main database that match the data found in your migration database.
* Always **create backups** before performing migration.

---

## 🚀 Migration Steps

### 1. Upload Backup

Upload your existing database backup to the XC_VM server via **SFTP**.
In this example, it is located at:

```
/tmp/backup.sql
```

### 2. Restore Backup to Migration Database

Use the tools script to clear the existing migration database and restore the backup:

```bash
/home/xc_vm/tools migration "/tmp/backup.sql"
```

### 3. Start Migration

If the restoration completed without errors, you can start the migration using one of two methods:

#### 🧩 Option 1 — Command Line (Recommended)

Run the migration manually:

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/includes/cli/migrate.php
```

#### 🌐 Option 2 — Web Installer

If you prefer not to use the command line, you can return to the **web installer** via the link provided during panel setup.
Select the **“Migration”** option and follow the on-screen instructions to complete the process interactively.

You will see real-time progress updates while XC_VM migrates your data.
Once complete, you will be able to log in to your system.

---

## 🔑 Restoring Access After Migration

If you cannot log in (e.g., due to missing username/password or invalid access code), you can use **rescue tools**:

### Create a Rescue Access Code

```bash
/home/xc_vm/tools access
```

### Create an Administrator to Restore Access

```bash
/home/xc_vm/tools user
```

> ⚠️ After logging in, immediately **change the access code and administrator credentials**.

---

## 🧩 Post-Migration

* Load balancers will initially appear **disabled**.
* All streams will **automatically stop** for safety.

### Next Steps

1. Reinstall load balancers and **manually start streams** after confirming migration.
2. Review and update **XC_VM default settings**.
3. Check main server data — domains, SSL configuration, etc.
4. Ensure everything matches your environment and settings.

---

## 🖥️ Load Balancer Preparation

You will need to reinstall the OS on the load balancers.

---

<p align="center">
  <a href="../en/main-page.md"><b>⬅️ Back to Main Page</b></a>
</p>
