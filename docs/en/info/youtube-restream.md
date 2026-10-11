# Restreaming YouTube

XC_VM can take a YouTube live broadcast as a stream source and restream it to your
lines like any other channel. You add the YouTube page link as the source; the panel
turns it into a playable stream with the bundled `yt-dlp` and pulls it with ffmpeg.

For this to work on a server in a data center you need an **HTTP proxy for each
YouTube stream**. This page explains why, how to set it up, and how to troubleshoot it.

---

## Why a proxy is required

YouTube blocks data-center IP addresses. Without a proxy, a stream from a hosting
server fails with:

```
ERROR: [youtube] ...: Sign in to confirm you're not a bot.
```

Two things decide how the proxy must be used:

- **The playable link is tied to one IP.** The link YouTube hands out only plays from
  the IP that asked for it. The panel therefore sends *both* steps through the same
  proxy: getting the link (`yt-dlp`) and downloading the video (ffmpeg).
- **YouTube limits each IP.** Every start, restart and source check asks YouTube again,
  and the video itself is downloaded through the proxy. A few streams on one IP are
  enough to get that IP blocked. Give **each YouTube stream its own proxy IP**.

---

## Proxy requirements

| Requirement | Why |
|---|---|
| **HTTP proxy** (`http://`) | ffmpeg cannot use SOCKS proxies. A `socks5://` proxy lets the panel get the link, but the video download then goes out directly and fails. |
| **Not a hosting / data-center IP** | YouTube blocks data-center ranges. Use a residential or mobile proxy, or a server on a home connection. |
| **One IP per stream** | Spreads YouTube's per-IP limit. With a residential provider, use a separate sticky session per stream. |
| **Sticky, not rotating per request** | The link must be downloaded from the IP that got it, for as long as the stream runs (the link lives about 6 hours). |
| **Enough traffic** | All video goes through the proxy: about 3–6 Mbit/s for a 1080p stream. Residential proxies are usually billed per GB. |

Accepted formats for the proxy value:

```
ip:port
http://ip:port
http://user:password@host:port
```

A value without a scheme (`ip:port`) is treated as `http://ip:port`.

---

## Finding working proxies: Proxy Checker

**Tools → Proxy Checker** checks a whole list of proxies the way a
YouTube stream uses them, so you can pick working ones out of hundreds or thousands.

1. Paste a link to a YouTube broadcast that is **live right now**.
2. Paste the proxies, one per line (`ip:port` or `http://user:password@host:port`).
3. Set how many proxies to check at once (default 5, up to 20) and press **Start**.

Each proxy gets one of these results:

| Result | Meaning |
|---|---|
| **OK** | The proxy answers, YouTube accepts it, and the video plays through it. Ready to use. |
| **Blocked by YouTube** | The proxy works, but YouTube refuses its IP ("Sign in to confirm you're not a bot"). |
| **Does not play** | YouTube gave a link, but the video does not play through the proxy, usually because it is a SOCKS proxy or changes IP on every request. |
| **Not responding** | The proxy did not answer within 10 seconds, or the login is wrong. |

The **IP Address** column shows each proxy's exit IP. Several proxies with the same exit IP
count as one IP to YouTube, so such proxies are marked *(duplicate)*.
**Copy working (one per IP)** copies one working proxy per exit IP, ready to put into your
streams. Over plain `http`, where the browser has no clipboard access, the list is put
into the proxy box instead.

The check runs on the main server, and every proxy asks YouTube once. Checking a large
list repeatedly makes YouTube block those IPs sooner. A proxy that passes can still get
blocked later under load, so re-check your list from time to time.

---

## Using your own proxy server

Instead of a proxy provider you can run your own HTTP proxy (for example Squid on port
3128) on a separate VPS or a machine on a home connection. Before you set up the proxy,
check that YouTube gives links to that server's IP at all. Many hosting ranges are
flagged, so a fresh VPS can be blocked from the start.

Run `yt-dlp` directly on the proxy server, with a broadcast that is live right now.
Install [deno](https://deno.com) too, as the panel does: without a JavaScript runtime
`yt-dlp` cannot open some broadcasts at all, whatever the IP.

```bash
curl -L -o /usr/local/bin/yt-dlp \
  https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux
chmod +x /usr/local/bin/yt-dlp
curl -L -o /tmp/deno.zip \
  https://github.com/denoland/deno/releases/latest/download/deno-x86_64-unknown-linux-gnu.zip
python3 -m zipfile -e /tmp/deno.zip /usr/local/bin && chmod +x /usr/local/bin/deno
yt-dlp -g 'https://www.youtube.com/watch?v=VIDEO_ID'
```

| Result | Meaning |
|---|---|
| A `https://manifest.googlevideo.com/...` link | YouTube accepts this server's IP. A proxy on it will work. |
| `Sign in to confirm you're not a bot` | YouTube has flagged this IP. A proxy on it will not work. Try another provider or a residential IP. |
| `This live event has ended` | The broadcast is over. Test with one that is live now. |
| `This video is not available` or `No video formats found!` | Usually no JavaScript runtime: check that `deno --version` works. With deno installed, the video itself is the problem: it is geo-blocked in the server's country, private, removed or members-only. |

Keep the link in single quotes. Without them, the `&` in a link such as
`watch?v=...&pp=...` cuts the command and runs it in the background.

The `ip/...` part of the printed link shows which address YouTube saw. If the server
has both IPv4 and IPv6, that can be either one.

The server's country matters only for geo-restricted broadcasts. A broadcast limited to
one country needs a proxy IP in that country. Everything else works from anywhere.

Once the check passes, set up the proxy and run the check again from the panel side,
through the proxy (see [Troubleshooting](#troubleshooting)), or with
**Tools → Proxy Checker**.

!!! warning
    Allow only your panel and load balancer IPs to use the proxy, or require a login.
    An open proxy is found and abused within hours, and its IP gets blocked by YouTube.

---

## Setting up a YouTube stream

1. Open **Streams → Add Stream**.
2. On the **Details** tab, give the stream a name and category as usual.
3. On the **Sources** tab, add the YouTube link of the live broadcast, for example:

    ```
    https://www.youtube.com/watch?v=rFZHOHl-L8A
    ```

    Supported link forms are `https://www.youtube.com/watch?v=...` and
    `https://youtu.be/...`.

4. On the **Advanced** tab, fill in **HTTP Proxy** with this stream's proxy, for example:

    ```
    http://user:password@proxy.example.com:8080
    ```

5. On the same **Advanced** tab, leave **Direct Source** off. With direct source the
   panel sends viewers to the YouTube link itself, which does not play outside the
   proxy's IP.
6. Choose the server(s) on the **Servers** tab and save. Start the stream.

The stream is resolved and downloaded on the server that runs it. On a load balancer the
same proxy is used from that node, so every node that runs YouTube streams needs to be
able to reach the proxy. To check a stream before going live, use the command in
[Troubleshooting](#troubleshooting) on the server that will run it.

---

## What is supported

- **YouTube live broadcasts:** supported. The panel picks a single stream that carries
  both video and audio (up to 1080p).
- **JavaScript runtime (required):** with [deno](https://deno.com) on the server,
  `yt-dlp` asks YouTube as its mobile web client, whose streams keep playing. The panel
  installs deno to `/usr/local/bin/deno` on every server when it is missing, together
  with the daily `yt-dlp` check (`console.php ytdlp` as root runs it at once). Without
  deno the panel falls back to a client that needs no runtime: some broadcasts do not
  open through it at all, and YouTube cuts the segments of the rest off after about
  30 seconds, so the stream restarts every half minute.
- **Regular (non-live) YouTube videos:** not supported for now. YouTube refuses their
  download links to the panel's `yt-dlp` without a JavaScript runtime, even through a
  proxy.
- **Other platforms resolved the same way:** Twitch, Vimeo, Dailymotion, Facebook,
  Livestream, Ustream and CNN links also go through `yt-dlp` and use the stream's proxy
  the same way.

`yt-dlp` is updated automatically on every server once a day and right after a panel
update, so changes on YouTube's side are usually picked up without a panel update.

---

## Troubleshooting

The quickest check is to run `yt-dlp` on the server the way the panel does, as the
`xc_vm` user and with the stream's proxy. The panel tries `player_client=mweb` first and
`player_client=default,android_vr` when that prints nothing:

```bash
sudo -u xc_vm /home/xc_vm/bin/yt-dlp \
  --proxy 'http://user:password@proxy.example.com:8080' \
  --extractor-args 'youtube:player_client=mweb' \
  -q --get-url --skip-download -f best \
  'https://www.youtube.com/watch?v=VIDEO_ID'
```

A working setup prints one `https://manifest.googlevideo.com/...` link.

| Symptom | Cause | Fix |
|---|---|---|
| `Sign in to confirm you're not a bot` | No proxy on the stream, or the proxy's IP is blocked or flagged as hosting. | Set **HTTP Proxy** on the stream; use a residential/home IP; give the stream an IP no other stream uses. |
| `Requested format is not available` | The panel version predates YouTube restream support. | Update the panel. |
| The link is found, but the stream does not start or segments fail (`403 Forbidden`, `Failed to open segment`) | The video is downloaded from a different IP than the link was fetched from. This usually happens because the proxy is SOCKS, or because a proxy server with both IPv4 and IPv6 connects over one for the link and the other for the video. | Use an HTTP proxy. On your own proxy server, make outgoing connections use only one of IPv4 or IPv6. |
| The stream does not start; the check above prints nothing for `mweb`, and `This video is not available` for `default,android_vr` | No JavaScript runtime on the server. Without it `mweb` gets no formats, and some broadcasts are not available to the `android_vr` fallback at all. | Run `console.php ytdlp` as root, which installs deno, or install [deno](https://deno.com) to `/usr/local/bin/deno`. |
| The stream restarts every 30–50 seconds; the log shows `403 Forbidden` / `Failed to open segment` | No JavaScript runtime on the server, so the panel fell back to the `android_vr` client, whose segments YouTube cuts off after about 30 seconds. | Same as above: install deno. |
| Works for a while, then stops for several streams at once | Several YouTube streams share one proxy IP and it got rate-limited. | One proxy IP per stream. |
| `Unable to connect to proxy` / timeouts | The proxy is unreachable from this server or the login is wrong. | Test it with `curl -x 'http://user:password@host:port' https://api.ipify.org` on the server; it should print the proxy's IP. |

!!! note
    The proxy login and password are stored in the stream's settings and appear in the
    ffmpeg command line on the server (visible in `ps`). Use credentials dedicated to
    this purpose.
