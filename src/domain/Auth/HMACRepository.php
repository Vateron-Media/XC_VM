<?php

class HMACRepository {
	public static function getAll($db) {
		$rReturn = array();
		$db->query('SELECT * FROM `hmac_keys` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getById($db, $rID) {
		$db->query('SELECT * FROM `hmac_keys` WHERE `id` = ?;', $rID);
		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
	}
}
