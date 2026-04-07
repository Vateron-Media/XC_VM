<h1 align="center">🧭 XC_VM Migration Guide</h1>

<p align="center">
  Safely migrate from compatible IPTV systems using the built-in XC_VM migration tools.
</p>

---

## 📚 Navigation

* [⚠️ Critical Migration Notice](#️-critical-migration-notice)
* [⚙️ Before You Start](#️-before-you-start)
* [🚀 Migration Steps](#-migration-steps)
* [🔑 Restoring Access After Migration](#-restoring-access-after-migration)
* [🖥️ Load Balancer Preparation](#️-load-balancer-preparation)
* [🧩 Post-Migration (Required)](#-post-migration-required)
* [❗ Common Post-Migration Issues](#-common-post-migration-issues)
* [✅ Summary](#-summary)

---

## ⚠️ Critical Migration Notice

> **Read this before starting the migration.**

XC_VM migration transfers **data only**.
**All configuration is intentionally excluded from migration.**

This includes (but is not limited to):

* API keys (e.g. **TMDb**)
* External service credentials
* Environment-specific settings
* Panel and system configuration
* Runtime and stream state

These values **must be reconfigured manually after migration**.

This is a **design decision**, not a limitation or a bug.
Skipping reconfiguration will **break metadata fetching, stream title updates, and related features**.

---

## ⚙️ Before You Start

> 💡 **Recommendation:**
> Perform migration on a **fresh XC_VM installation**.

> ⚠️ **Important:**
> System and panel settings are **NOT migrated**.
> Only database data supported by the migration process is transferred.

If you choose to migrate into an **existing installation**, be aware:

* XC_VM will **delete all tables** in the main database that match data from the migration database.
* **Backups are mandatory.** No automatic rollback is provided.

---

## 🚀 Migration Steps

### 1. Upload Backup

Upload your existing database backup to the XC_VM server using **SFTP**.

Example location:

```text
/tmp/backup.sql
```

---

### 2. Restore Backup into Migration Database

Clear the migration database and restore the backup:

```bash
sudo /home/xc_vm/console.php tools migration "/tmp/backup.sql"
```

Ensure the restore completes **without errors** before proceeding.

---

### 3. Start Migration

Once the backup is restored, start the migration using one of the following methods.

#### 🧩 Option 1 — Command Line (Recommended)

```bash
/home/xc_vm/console.php migrate
```

#### 🌐 Option 2 — Web Installer

* Return to the **web installer** (link shown during panel setup)
* Select **Migration**
* Follow the on-screen instructions

You will see real-time progress updates.
Once completed, the system will be accessible.

---

## 🔑 Restoring Access After Migration

If login fails due to missing credentials or access code, use the rescue tools.

### Create a Rescue Access Code

```bash
php /home/xc_vm/console.php tools access
```

### Create an Administrator Account

```bash
php /home/xc_vm/console.php tools user
```

> ⚠️ After regaining access, **immediately change** the access code and administrator credentials.

---

## 🖥️ Load Balancer Preparation

Load balancers are **not migrated**.

* Reinstall the operating system if required
* Reconfigure networking and routing
* Reconnect them to the main server

---

## 🧩 Post-Migration (Required)

After migration, the system is **not production-ready** until these steps are completed.

Skipping them will result in **expected but broken behavior**.

---

### 1. Reinitialize Runtime State

* Start all streams manually
* Verify streams are accessible and stable

> Stream runtime state is **never preserved** during migration.

---

### 2. Reconfigure System Settings

Review and restore all environment-specific configuration:

* File paths
* Limits and quotas
* Networking and reverse proxy settings
* Performance tuning

> Do **not** assume default values match your previous setup.
> Defaults are applied intentionally.

---

### 3. Restore API Keys and Providers

#### API Keys Are Never Migrated

The following **must be reconfigured manually**:

* **TMDb API key**

This is **expected behavior**.

> If metadata fetching does not work after migration,
> verify that the API key has been re-added and the provider is enabled.
> This does **not** indicate a migration bug.

---

## ❗ Common Post-Migration Issues

### Metadata Is Not Fetching (TMDb)

**Cause:**
TMDb API key and provider configuration were not restored.

**Resolution:**
Re-add the TMDb API key and enable the provider in main server settings.

---

## ✅ Summary

* Migration transfers **core application data only**
* Configuration is **excluded by design**
* API keys and environment-specific settings **must be restored manually**
* Missing functionality after migration is **expected until reconfiguration is complete**

---