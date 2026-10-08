<?php

use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\TranscodeProfileDeletedEvent;
use XcVm\Domain\Stream\StreamConfigRepository;
use PHPUnit\Framework\TestCase;

/**
 * Sample of the TestDb harness: exercises a real repository against an
 * in-memory database injected via setDb().
 *
 * @covers \XcVm\Domain\Stream\StreamConfigRepository
 */
final class StreamConfigRepositoryTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE profiles (profile_id INTEGER PRIMARY KEY AUTO_INCREMENT, profile_name TEXT);
			 CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, transcode_profile_id INTEGER DEFAULT 0);
			 CREATE TABLE streams_arguments (id INTEGER PRIMARY KEY AUTO_INCREMENT, argument_key TEXT, argument_cmd TEXT);

			 INSERT INTO profiles (profile_id, profile_name) VALUES (1, "CPU"), (2, "GPU");
			 INSERT INTO streams (id, transcode_profile_id) VALUES (10, 2), (11, 1);
			 INSERT INTO streams_arguments (id, argument_key, argument_cmd) VALUES
			   (1, "cookie", "-headers ?"), (2, "useragent", "-user_agent ?");'
		);

		StreamConfigRepository::setDb($this->db);
	}

	public function testGetTranscodeProfilesReturnsAll() {
		$profiles = StreamConfigRepository::getTranscodeProfiles();
		$this->assertCount(2, $profiles);
		$this->assertSame('CPU', $profiles[0]['profile_name']);
	}

	public function testGetTranscodeProfileByIdReturnsRow() {
		$profile = StreamConfigRepository::getTranscodeProfile(2);
		$this->assertIsArray($profile);
		$this->assertSame('GPU', $profile['profile_name']);
	}

	public function testGetTranscodeProfileReturnsNullWhenMissing() {
		$this->assertNull(StreamConfigRepository::getTranscodeProfile(999));
	}

	public function testGetStreamArgumentsKeyedByArgumentKey() {
		$args = StreamConfigRepository::getStreamArguments();
		$this->assertArrayHasKey('cookie', $args);
		$this->assertArrayHasKey('useragent', $args);
		$this->assertSame('-user_agent ?', $args['useragent']['argument_cmd']);
	}

	public function testDeleteProfileRemovesRowAndDetachesReferences() {
		EventDispatcher::setInstance(new EventDispatcher());
		$rDeleted = [];
		EventDispatcher::listen(TranscodeProfileDeletedEvent::class, static function (TranscodeProfileDeletedEvent $rEvent) use (&$rDeleted): void {
			$rDeleted[] = $rEvent->profileId;
		});

		$this->assertTrue(StreamConfigRepository::deleteProfile(2));

		// Profile gone.
		$this->assertNull(StreamConfigRepository::getTranscodeProfile(2));

		// References reset to 0; the unrelated stream keeps its profile.
		$this->db->query('SELECT transcode_profile_id FROM streams WHERE id = ?;', 10);
		$this->assertSame(0, (int) $this->db->get_col());

		$this->db->query('SELECT transcode_profile_id FROM streams WHERE id = ?;', 11);
		$this->assertSame(1, (int) $this->db->get_col());

		// Modules' tables (watch_folders) are theirs to detach: core says which profile went.
		$this->assertSame([2], $rDeleted);
	}

	public function testDeleteProfileReturnsFalseWhenMissing() {
		$this->assertFalse(StreamConfigRepository::deleteProfile(999));
	}
}
