# Connection filters and live tools

## Lines per IP

Select a range in **Lines per IP**, then **View logs** on a customer. Activity
logs open filtered to that customer and range; **All Time** leaves dates empty.
Change or clear filters to broaden results. Range links use ISO dates, and old
bookmarks remain supported.

## Live stream restart tools

Under **Quick Tools → Streams**, confirm the action to restart:

- Streams with known progress **below 0.8x or above 5x**. The exact boundaries
  and missing/invalid progress do not match.
- Streams missing **either audio or video codec**. Use this for channels
  expected to carry both tracks.

Only running live instances match, including daemon-supervised encoders.
Direct sources, offline instances, VOD, radio and instances being analyzed are
excluded. Healthy copies on other servers keep running.

The notification shows submitted/matched restart requests; `0/0` means none
matched. Check **Manage Streams** afterward to confirm they returned online.

## MAG details

In admin and reseller MAG lists, **Last Active** shows IP and ISP from one latest
active connection. Ended connections are excluded, missing ISP is left blank,
and reseller access stays limited to their customers. MySQL and Redis are
supported.
