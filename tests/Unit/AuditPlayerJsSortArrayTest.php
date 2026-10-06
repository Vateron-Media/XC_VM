<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\AdminHelpers;

/**
 * AdminHelpers::sortArrayByArray() puts a list in the order another list
 * gives. What comes back is the list it was given and nothing else: each of
 * its values once, those the order names first, the others after them. A
 * value that is neither a number nor text is no id and is left out.
 *
 * The panel uses it on bouquet ids: the ones chosen for a line, a device or a
 * package against the bouquets' own order, and the ones every line and package
 * holds against a new order.
 */
final class AuditPlayerJsSortArrayTest extends TestCase {
	/** The bouquet list as every caller stores it. */
	private static function stored(array $rArray, array $rSort): string {
		return '[' . implode(',', array_map('intval', AdminHelpers::sortArrayByArray($rArray, $rSort))) . ']';
	}

	/** Ids come as numbers from the database and as text from a form: either way they are put in order. */
	public function testAListTheOrderCoversIsPutInThatOrder(): void {
		$this->assertSame([9, 5, 7], AdminHelpers::sortArrayByArray([5, 7, 9], [9, 5, 7, 2]));
		$this->assertSame('[9,5,7]', self::stored(['5', '7', '9'], [9, 5, 7, 2]));
		$this->assertSame('[9,5,7]', self::stored([5, 7, 9], ['9', '5', '7', '2']));
		$this->assertSame([], AdminHelpers::sortArrayByArray([], [1, 2]));
		$this->assertSame([], AdminHelpers::sortArrayByArray([1, 2], []));
	}

	public function testValuesTheOrderDoesNotNameFollowTheOthers(): void {
		$this->assertSame([9, 5, 7], AdminHelpers::sortArrayByArray([5, 7, 9], [9]));
		$this->assertSame([1, 2, 999], AdminHelpers::sortArrayByArray([999, 1, 2], [1, 2, 3]));
		$this->assertSame([5, 7, 5], AdminHelpers::sortArrayByArray([5, 5, 7], [5, 7]));

		// A line holds a bouquet made after the new order was drawn up: it keeps it.
		$this->assertSame('[9,7,5,12]', self::stored([12, 5, 7, 9], [9, 7, 5]));
	}

	/** The order only arranges: whatever it holds, the list's own values come back. */
	public function testTheOrderAddsNoValueOfItsOwn(): void {
		$this->assertSame('[5,7,9]', self::stored([5, 7, 9], [true]));
		$this->assertSame('[5,7]', self::stored([5, 7], [null, false, [5], [], 1.5]));
		$this->assertSame('[7,5]', self::stored([5, 7], [true, '7', null, 5]));
		$this->assertSame(['7', '5'], AdminHelpers::sortArrayByArray(['5', '7'], [7, 5]));
	}

	/** A value that is no id takes no id's place in the order, and is not handed on to be stored as one. */
	public function testAValueThatIsNoIdIsLeftOut(): void {
		$this->assertSame([], AdminHelpers::sortArrayByArray([true], [3, 1, 2]));
		$this->assertSame([2, 3], AdminHelpers::sortArrayByArray([true, 3, null, 2], [0, 2, 3]));
		$this->assertSame([3], AdminHelpers::sortArrayByArray([[3], 3], [[3], 3]));
		$this->assertSame('[7,5]', self::stored([true, '5', false, [1], 7], [1, 7]));
	}
}
