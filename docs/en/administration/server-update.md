# Updating a Server

Step-by-step guide to updating an XC_VM server. For the internals of the update process, see [Update Mechanism](en-us/administration/update-system.md).

> 💾 Before updating, it is recommended to create a [backup](en-us/administration/backup-strategy.md).

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
