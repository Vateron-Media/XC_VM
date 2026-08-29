# Updating a Server

Step-by-step guide to updating an XC_VM server. For the internals of the update process, see [Update Mechanism](../administration/update-system.md).

> 💾 Before updating, it is recommended to create a [backup](../administration/backup-strategy.md).

## Updating via the Panel

**Step 1.** Open the **Servers** section in the top menu of the panel.

![Servers menu](../../_media/update1.png)

**Step 2.** Select **Manage Servers** from the dropdown menu.

![Manage Servers item](../../_media/update2.png)

**Step 3.** Locate the target server in the servers table and click the menu button in the **Actions** column.

![Actions button](../../_media/update3.png)

**Step 4.** Select **Server Tools** from the menu.

![Server Tools item](../../_media/update4.png)

**Step 5.** In the **Server Tools** dialog, click **Update Server**.

![Update Server button](../../_media/update5.png)

Clicking the button inserts an `update` signal into the database. Within a minute, CRON picks it up and the update runs automatically: the panel is stopped, updated, and restarted. You can track progress via the server version in the **Manage Servers** table.

## Manual Update (CLI)

```bash
sudo -u xc_vm /home/xc_vm/console.php update update
```

Downloads and applies the latest update from GitHub. Usually triggered automatically through the web panel.

## Rolling Back a Server

If an update introduces a problem, you can roll a server back to an earlier release directly from the panel. Rollback works per-server, so both the **MAIN** panel and individual **Load Balancers** can be downgraded independently.

**Step 1.** Open the **Servers** section → **Manage Servers**.

**Step 2.** Locate the target server and open its **Actions** menu (the same menu used for updating).

**Step 3.** Click **Rollback Version**. A dialog opens listing earlier releases (newest first). Pre-release builds are tagged `(beta)`.

**Step 4.** Select the version to roll back to and confirm.

Clicking **Rollback** inserts a `rollback` signal (carrying the chosen version) into the database. Within a minute CRON picks it up and the rollback runs automatically, reusing the same stop → replace → restart flow as an update. Track progress via the server version in the **Manage Servers** table.

> 💾 On the **MAIN** server a database backup is taken automatically before the rollback, saved to `/home/xc_vm/backups/pre_rollback_<from>_to_<to>_<timestamp>.sql`. Load balancers have no database, so this step is skipped.

The versions offered depend on the server's update channel: on the `stable` channel only stable releases are listed; on `beta` you also see `(beta)` pre-releases.

> ⚠️ A rollback **does not undo database migrations** — they are forward-only. The schema is designed to stay backward-compatible, and the automatic backup is the recovery path if an older build cannot read newer data. Use rollback only as a recovery step.

### Manual Rollback (CLI)

```bash
sudo -u xc_vm /home/xc_vm/console.php update rollback 2.4.0
```

Downloads the specified release from GitHub and applies it, with the same integrity checks and — on MAIN — the automatic database backup.
