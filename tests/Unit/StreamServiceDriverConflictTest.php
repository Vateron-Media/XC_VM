<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\SourceDriverRegistry;
use XcVm\Domain\Stream\StreamService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Stream saves that would hand a module source-driver URL to something other
 * than the driver's engine on the main server are refused
 * (StreamService::driverSourceConflict and its form / import / mass-edit uses).
 */
final class StreamServiceDriverConflictTest extends TestCase {
	private static function main(): callable {
		return static fn(): array => [1];
	}

	protected function setUp(): void {
		SourceDriverRegistry::reset();
		SourceDriverRegistry::register(new TestSourceDriver(['acmedash']));
	}

	protected function tearDown(): void {
		SourceDriverRegistry::reset();
		DatabaseFactory::reset();
	}

	private static function call(string $rMethod, ...$rArgs) {
		$m = new ReflectionMethod(StreamService::class, $rMethod);
		$m->setAccessible(true);
		return $m->invoke(null, ...$rArgs);
	}

	private static function tree(array $rNodes): array {
		return array_map(static fn(array $n): array => ['id' => (string) $n[0], 'parent' => (string) $n[1]], $rNodes);
	}

	/** A save with no driver source never looks the servers up (the table may not even be there). */
	public function testServersAreOnlyLookedUpForAFedDriverSource(): void {
		$rNever = static function (): array {
			throw new LogicException('looked up');
		};
		$this->assertNull(StreamService::driverSourceConflict(['http://a/b.ts'], [], self::tree([[5, 'source']]), $rNever));
		$this->assertNull(StreamService::driverSourceConflict(['acmedash://p/c'], [], self::tree([[5, 1]]), $rNever));
	}

	public function testCoreSourcesAreNeverRefused(): void {
		$this->assertNull(StreamService::driverSourceConflict(['http://a/b.ts'], ['direct_source' => 1, 'llod' => 2], self::tree([[5, 'source']]), self::main()));
	}

	public function testDriverSourceCannotBeDirect(): void {
		$this->assertSame('source_driver_no_direct', StreamService::driverSourceConflict(['http://a/b.ts', 'acmedash://p/c'], ['direct_source' => 1], null, self::main()));
		$this->assertSame('source_driver_no_direct', StreamService::driverSourceConflict(['acmedash://p/c'], ['direct_proxy' => 1], null, self::main()));
	}

	public function testDriverSourceCannotUseLlodV2(): void {
		$this->assertSame('source_driver_no_llod2', StreamService::driverSourceConflict(['acmedash://p/c'], ['llod' => '2'], null, self::main()));
		$this->assertNull(StreamService::driverSourceConflict(['acmedash://p/c'], ['llod' => 1], null, self::main()), 'LLOD v1 runs the producer path');
	}

	/** An LB fed from the source would run the source itself — without the module. */
	public function testOnlyMainMayBeFedFromTheSource(): void {
		$this->assertSame('source_driver_main_only', StreamService::driverSourceConflict(['acmedash://p/c'], [], self::tree([[1, 'source'], [5, 'source']]), self::main()));
		$this->assertNull(StreamService::driverSourceConflict(['acmedash://p/c'], [], self::tree([[1, 'source'], [5, 1], [6, 5]]), self::main()), 'LB children of main pull the loopback');
		$this->assertNull(StreamService::driverSourceConflict(['acmedash://p/c'], [], null, self::main()), 'servers unchanged');
	}

	public function testImportChecksEveryStreamWithItsOwnValues(): void {
		$rStreams = [['stream_source' => ['http://a/b.ts']], ['stream_source' => ['acmedash://p/c'], 'direct_source' => 1]];
		$this->assertSame('source_driver_no_direct', self::call('importDriverConflict', $rStreams, ['direct_source' => 0], null, self::main()));
		$this->assertNull(self::call('importDriverConflict', [['stream_source' => ['acmedash://p/c']]], ['direct_source' => 0], null, self::main()));
	}

	public function testPostedServerTree(): void {
		$rTree = json_encode([['id' => '1', 'parent' => 'source']]);
		$this->assertSame([['id' => '1', 'parent' => 'source']], self::call('postedServerTree', ['server_tree_data' => $rTree], false));
		$this->assertNull(self::call('postedServerTree', ['server_tree_data' => $rTree], true), 'mass edit without the servers box');
		$this->assertNull(self::call('postedServerTree', ['server_tree_data' => $rTree, 'c_server_tree' => 1, 'server_type' => 'DEL'], true), 'removing servers conflicts with nothing');
		$this->assertNotNull(self::call('postedServerTree', ['server_tree_data' => $rTree, 'c_server_tree' => 1, 'server_type' => 'SET'], true));
		$this->assertNull(self::call('postedServerTree', [], false));
	}

	public function testMassEditChecksTheStreamsAsTheyWillBe(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, stream_source TEXT, direct_source INTEGER, direct_proxy INTEGER, llod INTEGER)');
		$rDb->query('INSERT INTO streams VALUES (1, ?, 0, 0, 0), (2, ?, 1, 0, 0)', json_encode(['acmedash://p/c']), json_encode(['http://a/b.ts']));
		DatabaseFactory::set($rDb);

		$this->assertSame('source_driver_no_direct', self::call('massEditDriverConflict', [1, 2], ['direct_source' => 1], null, self::main()));
		$this->assertNull(self::call('massEditDriverConflict', [2], ['direct_source' => 1], null, self::main()), 'core source');
		$this->assertNull(self::call('massEditDriverConflict', [1], ['direct_source' => 0, 'direct_proxy' => 0], null, self::main()), 'the edit turns it off');
		$this->assertSame('source_driver_main_only', self::call('massEditDriverConflict', [1], [], self::tree([[5, 'source']]), self::main()));
		$this->assertNull(self::call('massEditDriverConflict', [1], ['stream_all' => 1], null, self::main()), 'nothing relevant changes');
		$this->assertNull(self::call('massEditDriverConflict', [], ['direct_source' => 1], null, self::main()));
	}
}
