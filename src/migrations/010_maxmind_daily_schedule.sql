-- Run the MaxMind GeoIP job daily instead of only Tuesdays so a failed
-- install-time download self-heals within a day. The installer runs
-- `cron:maxmind --force` but swallows a failed download, so a network hiccup
-- can leave the panel with no GeoIP data; on the old Tuesday-only schedule the
-- missing GeoLite2-Country.mmdb (which portal.php fatals on) was not re-fetched
-- until the next Tuesday. The command still performs the real weekly refresh on
-- Tuesdays and, on other days, only downloads when a required database is
-- missing (see MaxMindCronJob). Only touch the row if it still holds the old
-- Tuesday default, to preserve a schedule an operator may have customised.
UPDATE `crontab` SET `time` = '0 4 * * *' WHERE `filename` = 'maxmind' AND `time` = '0 4 * * 2';
