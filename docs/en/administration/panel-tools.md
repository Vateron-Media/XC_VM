# Panel connection filters and live maintenance

## Lines per IP

Open **Lines per IP**, select a time range, and use **View logs** on a customer.
The activity page opens with that customer's filter and the selected date range
already applied to its first table request. **All Time** leaves the date range
empty. Change or clear the filters on the activity page to broaden the results.

The range link uses ISO dates independently of the panel's display date format.
Existing bookmarks using `user`, `stream`, and `server` still work; the current
links use `user_id`, `stream_id`, and `server_id`.

## Restart unhealthy live streams

Under **Quick Tools → Streams**, two actions restart matching live instances:

- **Restart Live Streams with Progress below 0.8x or above 5x** selects known
  progress speeds strictly outside those bounds. Exactly 0.8x and 5x are left
  running. Missing or invalid progress data does not match this tool.
- **Restart Live Streams Missing Audio or Video Codec** selects instances whose
  stored audio codec or video codec is empty. Either missing codec is enough;
  use this deliberately for channels that are expected to carry both tracks.

Both tools use the panel's existing confirmation and restart command. They
select running live streams, including daemon-supervised encoders, and skip
direct sources, offline instances, VOD, radio, and instances being analyzed.
Each server receives only its own matching stream IDs. A healthy copy of the
same stream on a different server is left running.

The notification reports submitted requests against matching instances. A
submitted request is a queued restart command, not confirmation that the stream
has returned online. Check **Manage Streams** afterward. If there are no matches,
the notification reports `0/0` and no restart request is sent.

## MAG connection details

The **Last Active** cell in the admin and reseller MAG lists shows the current
connection's IP address and ISP underneath while the device is online. The
reseller list also has a Last Active column. If there are several active
connections, one latest active connection supplies both fields. Ended connections
are excluded, and missing ISP information is left blank.

The data comes from the active connection store in use, MySQL or Redis. It is
queried only for the customers already visible to the current listing; reseller
ownership restrictions continue to apply. IP and ISP values are displayed as
escaped text.
