<h1 align="center">🧭 XC_VM Migration Guide</h1>
<p align="center">
  Migrate from compatible IPTV systems with ease using the built-in XC_VM migration tool.
</p>

<!-- <p align="center">
  <a href="../../wiki"><b>⬅️ Back to Wiki</b></a>
</p> -->

---

## ⚙️ Before You Begin

> 💡 **Recommendation:**  
> It’s best to perform the migration on a **clean XC_VM installation**.

If you decide to use an **existing installation**, note that:
- XC_VM will **wipe any tables** in your main database that match data found in your migration database.
- Always **create backups** before performing any migration.

---

## 🚀 Migration Steps

### 1. Upload Your Backup
Upload your existing backup database to your XC_VM server via **SFTP**.  
For this example, we’ll assume it’s located at:
```

/tmp/backup.sql

````

### 2. Restore the Backup to the Migration Database
Use the tools script to clear the existing migration database and restore your backup:
```bash
/home/xc_vm/tools migration "/tmp/backup.sql"
````

### 3. Start the Migration

Assuming no errors occurred during restoration, you can begin the migration in one of two ways:

#### 🧩 Option 1 — Command Line (recommended)

Run the migration manually:

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/includes/cli/migrate.php
```

#### 🌐 Option 2 — Web Installer

If you prefer not to use command line tools, you can also return to the **web installer** interface via the link provided by the script when installing the panel.
There, select the **“Migration”** option and follow the instructions on the screen to complete the process interactively.

You will see real-time progress updates while XC_VM migrates your data.
Once complete, you will be able to log in to your system.

---

## 🔑 Post-Migration Access Recovery

If you cannot log in (e.g., missing username/password or invalid access code), you can use the **rescue tools**:

### Generate a Rescue Access Code

```bash
/home/xc_vm/tools access
```

### Generate a Rescue Admin User

```bash
/home/xc_vm/tools user
```

> ⚠️ Once logged in, make sure to **change your access code and admin credentials** immediately.

---

## 🧩 After Migration

* Load balancers will initially appear **offline**.
* All streams are **stopped automatically** for safety.

### Next Steps

1. Reinstall your load balancers and **manually start your streams** once migration is verified.
2. Review and update your **default XC_VM settings**.
3. Check **Main Server** details — domain names, SSL configuration, etc.
4. Ensure everything matches your environment and preferences.

---

## 🖥️ Preparing the load balancer

You will need to reinstall the OS on the load balancers.


---

<!-- <p align="center">
  <a href="../../wiki"><b>⬅️ Back to Wiki</b></a>
</p>

--- -->