<?php

namespace XcVm\Domain\User;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * UserCredits — changes to a user's credit balance
 *
 * A balance is changed on the row as it is stored at that moment, held from
 * the read to the write. It is never written back from a copy read earlier in
 * the request: another request may have changed it since.
 *
 * `users`.`credits` is a FLOAT: it is read at four decimals, so a balance
 * shown as 0.7 covers a price of 0.7 and leaves 0.
 *
 * @package XC_VM_Domain_User
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UserCredits {
	use DatabaseAware;

	/**
	 * Take an amount from a balance that covers it.
	 *
	 * @param int   $rUserID User id.
	 * @param float $rAmount Credits to take.
	 * @return bool False when the balance is short of the amount: nothing is taken.
	 */
	public static function debit(int $rUserID, float $rAmount): bool {
		return self::change($rUserID, -$rAmount, true);
	}

	/**
	 * Add an amount to a balance. A negative amount is taken from it, whatever it holds.
	 *
	 * @param int   $rUserID User id.
	 * @param float $rAmount Credits to add.
	 * @return bool False when no balance was changed (no such user).
	 */
	public static function credit(int $rUserID, float $rAmount): bool {
		return self::change($rUserID, $rAmount, false);
	}

	/**
	 * Move an amount from one balance to another: both change, or neither does.
	 * A negative amount moves the other way. Inside a transaction the caller has
	 * opened the move is part of it: the caller commits it, and rolls it back
	 * when the move is refused.
	 *
	 * @param int   $rFromID User who gives.
	 * @param int   $rToID   User who receives.
	 * @param float $rAmount Credits to move.
	 * @return bool False when the giving balance is short of the amount, or both are one account.
	 */
	public static function transfer(int $rFromID, int $rToID, float $rAmount): bool {
		if ($rFromID == $rToID) {
			return false;
		}

		if ($rAmount < 0) {
			return self::transfer($rToID, $rFromID, -$rAmount);
		}

		$db = self::db();
		$rOwn = !$db->isInTransaction() && $db->beginTransaction();
		$rMoved = self::debit($rFromID, $rAmount) && self::credit($rToID, $rAmount);

		if ($rOwn) {
			$rMoved ? $db->commit() : $db->rollback();
		}

		return $rMoved;
	}

	/**
	 * Move all a balance holds to another: what it holds when it is moved, not
	 * what a copy read earlier says.
	 *
	 * @param int $rFromID User who gives.
	 * @param int $rToID   User who receives.
	 * @return float The credits moved: 0 when none were.
	 */
	public static function transferAll(int $rFromID, int $rToID): float {
		$db = self::db();
		$rOwn = !$db->isInTransaction() && $db->beginTransaction();
		$rAmount = 0.0;

		if ($db->query('SELECT ROUND(COALESCE(`credits`, 0), 4) AS `credits` FROM `users` WHERE `id` = ? FOR UPDATE;', $rFromID) && $db->num_rows() == 1) {
			$rAmount = floatval($db->get_row()['credits']);
		}

		$rMoved = self::transfer($rFromID, $rToID, $rAmount);

		if ($rOwn) {
			$rMoved ? $db->commit() : $db->rollback();
		}

		return ($rMoved ? $rAmount : 0.0);
	}

	/**
	 * The balance as it is stored now.
	 *
	 * @param int $rUserID User id.
	 * @return float
	 */
	public static function balance(int $rUserID): float {
		$db = self::db();
		$db->query('SELECT ROUND(COALESCE(`credits`, 0), 4) FROM `users` WHERE `id` = ?;', $rUserID);

		return floatval($db->get_col());
	}

	/**
	 * Change a balance by an amount, in the caller's transaction or one of its own.
	 *
	 * @param int   $rUserID  User id.
	 * @param float $rAmount  Credits to add (negative: to take).
	 * @param bool  $rCovered Refuse a change that would leave the balance below zero.
	 * @return bool True when the balance was changed.
	 */
	private static function change(int $rUserID, float $rAmount, bool $rCovered): bool {
		if ($rAmount == 0) {
			return true;
		}

		$db = self::db();
		$rOwn = !$db->isInTransaction() && $db->beginTransaction();
		$rChanged = false;

		if ($db->query('SELECT ROUND(COALESCE(`credits`, 0), 4) AS `credits` FROM `users` WHERE `id` = ? FOR UPDATE;', $rUserID) && $db->num_rows() == 1) {
			// What is left is counted at four decimals as well: 0.3 less 0.1 * 3 is 0.
			$rCredits = round(floatval($db->get_row()['credits']) + $rAmount, 4);

			if (!$rCovered || 0 <= $rCredits) {
				$rChanged = $db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', number_format($rCredits, 4, '.', ''), $rUserID);
			}
		}

		if ($rOwn) {
			$rChanged ? $db->commit() : $db->rollback();
		}

		return $rChanged;
	}
}
