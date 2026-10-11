-- VOD Import: find an episode by its TMDb title when the file names its
-- season and no episode number (VodItemImporter::matchEpisodeByTitle()). Off
-- by default: such a file stays "No match", as before.
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `tmdb_episode_by_title` tinyint(1) DEFAULT '0';
