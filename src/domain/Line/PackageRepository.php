<?php

class PackageRepository {
	public static function deleteById($db, $rGetPackageCallback, $rID) {
		$rPackage = call_user_func($rGetPackageCallback, $rID);

		if (!$rPackage) {
			return false;
		}

		$db->query('UPDATE `lines` SET `package_id` = null WHERE `package_id` = ?;', $rID);
		$db->query('DELETE FROM `users_packages` WHERE `id` = ?;', $rID);

		return true;
	}
}
