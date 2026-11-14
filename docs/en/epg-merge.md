# Combining EPG sources

Apply database migrations `103_add_epg_merge`, `104_add_stream_epg_bindings`, and `105_add_epg_merge_lookup` through the normal XC_VM updater before using this feature.

## Search and select multiple EPG entries

Open **Streams → Edit channel → EPG → Search EPG**. Search by channel name, optionally narrow the source, and press **+** beside each desired result. The selected list shows the source and exact channel ID. Use the arrows to set priority, or remove individual entries. Multiple different IDs from the same source are supported.

Save the channel. Its guide import starts automatically. The first selected entry has priority; when it has no valid programmes, the next supplies them. Later entries also fill complete uncovered time slots. The existing non-overlap and last-valid-data rules still apply. The choices are fixed IDs rather than fuzzy matches, so they remain exactly the entries you selected.

Migration `104_add_stream_epg_bindings` stores the ordered entries per channel. This is MAIN's panel metadata; existing `epg_id` and `channel_id` continue carrying the materialized guide to players and nodes. User-configured XMLTV sources are used. Importing provider EPGs is a separate existing panel action and is not part of the channel-selection workflow.

## Manual source-level merge

The source-level merge below remains available for explicit channel mappings.

1. Add your XMLTV sources in **EPG** and reload them once.
2. Add a new EPG source and enable **Combine multiple EPG sources**.
3. Select two or more ordinary sources and give them priorities. Smaller numbers win; equal numbers use source ID order.
4. Identical XMLTV channel IDs combine automatically. For different IDs, add a channel mapping: target ID, input source, and that source's channel ID. For example, target `news.de`, backup source, input `NewsGermany`.
5. Save and reload the merged source. This also refreshes its inputs. Its channels now appear in the normal stream EPG selector.
6. Assign streams to channels from the merged source and reload again. The importer fetches programmes for the assigned channels even when their input sources are not directly assigned to streams.

The normal EPG cron maintains the merged guide. Reloading an input also rebuilds merged guides from the latest stored inputs. The merged source works with the existing XMLTV exports and per-stream player caches.

Higher-priority programmes win. Backups fill only complete, non-overlapping intervals; programmes are never cut into fragments. A backup starting before an existing programme ends is skipped, so small gaps can remain. No system can supply missing listings when every input lacks them. The edit page shows coverage for the next 24 hours and the latest programme end for each assigned channel.

Invalid downloads, malformed XML and failed database replacements retain the last successful data. An old merged guide is a fallback only when relevant inputs failed and its source order, mappings, and selected languages still match. Successful reloads do not reintroduce programmes removed by the inputs. Its entries still expire according to their actual programme times. The status lists failed input IDs detected during the latest reload.

Channel IDs are mapped explicitly; similar channel names are not matched automatically. Merged channel IDs are stable and separate from input IDs, preventing collisions in exported XMLTV. Changing a target ID requires reassigning affected streams. Removing a source used by a merge is refused. Switching an assigned source between ordinary and merged modes is refused; create a separate source instead.

Merge sources cannot contain other merges or the reserved Created Channels guide. Up to 20 inputs and 1,000 manual mappings are supported. Source timezone offsets apply before merging; a merge has no additional offset. The existing single preferred programme language per input channel remains in effect.

Configuration saves, input deletion, internal-group creation, and fetched-source publication share a database lock. An edit made while a source downloads discards that older response. Stream creation and its final selection channel ID commit atomically. A requested reload waits behind an active importer rather than being discarded. Failed process launches are logged; scheduled EPG refresh remains available.

Unused internal selection guides are removed when checking source deletion. They are excluded from legacy search and auto-assignment. Each selection retains the longest configured history of its inputs; zero means that the importer does not impose an additional history cutoff. Source history still expires after failed downloads. A broken merge retains its previous data and does not stop other guides, XMLTV generation, or stream-cache refresh.

Migration 105 adds `(epg_id, channel_id, end, start)` for per-channel lookups. A representative 10,000-row fixture uses this index and examines only its requested source/channel range; the `ORDER BY start` can still require a filesort after the indexed range lookup.
