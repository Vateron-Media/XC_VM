# Custom FFmpeg Commands

By default the panel builds the ffmpeg command for a channel from its settings: the
source options (user agent, proxy, cookies, headers), the transcoding profile, the
track mapping and so on. When none of that gives you what you need, you can write the
ffmpeg options for a channel yourself in **Custom FFmpeg Command**.

The field is on the **Advanced** tab of the live stream form (**Streams → Add/Edit
Stream**) and on the radio station form.

!!! warning "Only for administrators who know ffmpeg"
    The command runs on your servers exactly as written. A mistake stops the channel,
    and the panel no longer applies its own settings to it (see
    [What the panel no longer does](#what-the-panel-no-longer-does)).

---

## How the command is built

You do **not** write the whole command. The panel puts its own parts around yours:

```text
ffmpeg -y -nostdin -hide_banner -loglevel error -progress <file>  YOUR COMMAND  <outputs>
```

- **In front:** the ffmpeg binary and the logging/progress flags.
- **Your part:** the input and everything that should happen to it: input options,
  `-i {STREAM_SOURCE}`, filters, codecs.
- **After:** the outputs the panel needs: the HLS segments the channel is served from
  and, if enabled, the RTMP output and the external RTMP pushes.

So your command must contain the **input** (`-i {STREAM_SOURCE}`) and the **codec
options**, but **no output file**. Anything you add at the end becomes part of the
first output's options.

### Placeholders

| Placeholder | Replaced with |
|---|---|
| `{STREAM_SOURCE}` | The URL of the source being tried, already quoted for the shell. If the channel has several sources and one fails, the panel runs the same command with the next source's URL. |

The placeholders `{GPU}`, `{INPUT_CODEC}` and `{LOGO}` are also recognised but always
become empty for a custom command, because they come from the transcoding profile,
which a custom command does not use.

---

## Examples

Copy the source without re-encoding:

```text
-i {STREAM_SOURCE} -c copy
```

Re-encode the video to H.264 and the audio to AAC:

```text
-i {STREAM_SOURCE} -c:v libx264 -preset veryfast -b:v 3000k -c:a aac -b:a 128k
```

Pull a source that needs a user agent and survives short drops of an HTTP source
(the panel adds these for normal channels, but not for a custom command):

```text
-user_agent "Mozilla/5.0" -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -i {STREAM_SOURCE} -c copy
```

Scale to 720p and burn in a logo (the logo file must exist on every server that runs
the channel):

```text
-i {STREAM_SOURCE} -i /home/xc_vm/content/logo.png -filter_complex '[0:v]scale=-2:720[v];[v][1:v]overlay=10:10' -c:v libx264 -preset veryfast -c:a aac
```

Encode on an NVIDIA GPU:

```text
-hwaccel cuda -i {STREAM_SOURCE} -c:v h264_nvenc -preset p4 -b:v 4000k -c:a aac
```

When the command contains `nvenc`, the panel starts it with the GPU build of ffmpeg
instead of the CPU one.

---

## Rules to follow

- **Always include `-i {STREAM_SOURCE}`.** Without it ffmpeg has no input and the
  channel does not start.
- **Do not add an output.** No file name, `-f`, or URL at the end; the panel adds the
  outputs.
- **Use codecs that fit MPEG-TS.** The channel is served as HLS with MPEG-TS segments:
  use H.264 or HEVC video and AAC, MP3 or AC-3 audio.
- **Quote for the shell.** The command is passed to the shell as written, so wrap
  filter graphs and values with spaces or special characters (`;`, `[`, `]`, `&`,
  `|`) in single quotes, as in the examples above.
- **Mind extra outputs.** Your codec options apply to the HLS output only. If the
  channel also has **RTMP output** enabled or external RTMP pushes, those outputs use
  ffmpeg's defaults. Test such channels carefully, or leave those options off.

---

## What the panel no longer does

With a custom command, the panel ignores these channel settings:

- the **transcoding profile**, including its logo and GPU options;
- **user agent, HTTP proxy, cookie and headers**, and the automatic reconnect for
  HTTP sources: add them to your command yourself (see the examples);
- **Generate PTS**, **Native Frames**, **Stream All Codecs** and custom track mapping.

A channel with a custom command is also always run by ffmpeg, even when the native
remuxer is enabled, and it cannot use **Direct Source**: the field is disabled when
Direct Source is on, because such a channel never runs ffmpeg.

---

## Troubleshooting

If the channel does not start or keeps restarting:

1. Open the channel's errors (**Logs → Streams → Stream Errors**). ffmpeg's error messages for
   the channel end up there. Turn on **FFMPEG Show Warnings** in the settings to also
   see warnings.
2. Test the command by hand on the server, replacing `{STREAM_SOURCE}` with the source
   URL and adding a test output:

    ```text
    /home/xc_vm/bin/ffmpeg_bin/<version>/ffmpeg -i 'http://source/url' -c copy -t 20 -f mpegts /tmp/test.ts
    ```

3. Clear the field and save to return the channel to the panel's normal command.
