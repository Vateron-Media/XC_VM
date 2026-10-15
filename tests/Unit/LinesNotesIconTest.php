<?php

use PHPUnit\Framework\TestCase;

/**
 * 1.16.58 (adapted from the Parches module) — a note icon next to the actions
 * (⋮) button in Lines, MAG and Enigma, as Users already shows. Core's
 * handleLines/handleMags/handleEnigmas send `notes` as an array (not the stock
 * panel's single string), and esc() here is a full HTML-entity escaper (quotes
 * included), so the title is built from row.notes.join('\n') through esc().
 * View JS is not drivable by the PHP suite, so the regression is pinned at the
 * source.
 */
final class LinesNotesIconTest extends TestCase {

	private function src(string $rView): string {
		return (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/' . $rView . '.php');
	}

	/** @return array<string, array{0: string}> */
	public static function views(): array {
		return ['lines' => ['lines'], 'mags' => ['mags'], 'enigmas' => ['enigmas']];
	}

	/** @dataProvider views */
	public function testActionsColumnShowsTheNoteIcon(string $rView): void {
		$rSrc = $this->src($rView);
		$this->assertStringContainsString(
			'tabler-note text-primary" title="\' + esc(row.notes.join(\'\n\'))',
			$rSrc,
			$rView . ': note icon built from the escaped joined notes'
		);
		$this->assertStringContainsString(
			'<div class="d-inline-flex align-items-center gap-2">\' + note +',
			$rSrc,
			$rView . ': icon sits before the actions button'
		);
	}

	/** MAG and Enigma return '' when the admin has no actions; now they still show the note icon. */
	public function testMagAndEnigmaKeepTheIconWithoutActions(): void {
		foreach (['mags', 'enigmas'] as $rView) {
			$this->assertStringContainsString('return note;', $this->src($rView), $rView . ': lone icon instead of an empty cell');
		}
	}
}
