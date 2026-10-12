# Adding Your Own FFmpeg Build

The panel ships its own FFmpeg builds and keeps them up to date. You can also add a
build of your own, for example a version with extra codecs or a hardware encoder,
and choose it for your streams like any other.

---

## How the panel finds FFmpeg builds

Every build is a folder in `/home/xc_vm/bin/ffmpeg_bin/` that holds an `ffmpeg` and an
`ffprobe` binary. The panel lists every such folder whose name has the right form, and
the folder name is what the settings store.

| Folder | What it is |
|---|---|
| `4.4`, `7.1`, `8.1` | The panel's own builds. The panel downloads and updates them every day. |
| `4.0` | XUI's build on panels migrated from XUI, or an older XC_VM build. Never changed. |
| **Your build** | Any other name of the form below. The panel never changes or deletes it. |

The panel shows each build by the version its `ffmpeg` binary reports, not by its
folder name. A folder `6.1-my` holding FFmpeg 6.1.2 shows as **v6.1.2**.

---

## Naming your folder

Use `<major>.<minor>-<name>`, for example `6.1-my` or `7.1-nv`:

- `<major>.<minor>` is the FFmpeg version of your build (`6.1`, `7.1`, …).
- `<name>` is any short name of your own: lowercase letters and digits only.
- The whole name can be **at most 8 characters**, because that is how much the
  setting stores.

Do not use a name the panel uses itself: `4.4`, `7.1` and `8.1` are replaced on every
update, and `4.0` may hold XUI's build.

Folders with any other name, such as `my-ffmpeg` or `6.1.backup`, are not listed.

---

## Adding the build

The build has to run on the server's own system, so a **static build** is the safest
choice. Put it on **every server that runs streams**: the main server and each load
balancer. A server that does not have the build uses another one instead (see below).

Run these on each server as root, with your folder name and the path to your binaries:

```bash
mkdir /home/xc_vm/bin/ffmpeg_bin/6.1-my
cp /path/to/ffmpeg /path/to/ffprobe /home/xc_vm/bin/ffmpeg_bin/6.1-my/
chown -R xc_vm:xc_vm /home/xc_vm/bin/ffmpeg_bin/6.1-my
chmod 755 /home/xc_vm/bin/ffmpeg_bin/6.1-my/ffmpeg /home/xc_vm/bin/ffmpeg_bin/6.1-my/ffprobe
```

Check that it runs on this server, as the panel's user:

```bash
sudo -u xc_vm /home/xc_vm/bin/ffmpeg_bin/6.1-my/ffmpeg -hide_banner -version
```

It must print the version. If it reports a missing library or `GLIBC_… not found`, the
build does not suit this server; use a static build instead.

---

## Choosing the build

1. Open **Settings → Info**. Your build appears in the **FFmpeg** table, with its
   version and whether it can encode on the GPU.
2. On **Settings → Streaming**, pick it in **FFMPEG Version (CPU)**, and in
   **FFMPEG Version (GPU)** if it should do the GPU work.
3. Save. The new build is used from the next start of each stream, so restart the
   streams you want to move to it now.

---

## When a server does not have the build

If the build you chose is missing on a server, that server picks one itself:

1. A build of the same version, for example `6.1` or another `6.1-…`.
2. Otherwise the newest build with the same major version, for example `6.0`.
3. Otherwise the newest build on the server.

So a stream never fails because one load balancer lacks your build, but it may run
with a different FFmpeg there. Put the build on every server to get the same result
everywhere.

---

## Removing the build

Choose another build in **Settings → Streaming** first, save, then delete the folder on
every server:

```bash
rm -rf /home/xc_vm/bin/ffmpeg_bin/6.1-my
```

Streams that still run on it keep running until they restart.

---

## Related

- [Custom FFmpeg Commands](custom-ffmpeg-command.md): your own ffmpeg options for a
  single channel.
- [CLI tools](../guides/cli-tools.md): the `ffmpeg` command that installs and updates
  the panel's builds.
