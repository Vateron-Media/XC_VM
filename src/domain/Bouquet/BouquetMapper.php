<?php

class BouquetMapper {
	public static function getMapEntry($rStreamID) {
		$rBouquetMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'bouquet_map'));
		$rReturn = ($rBouquetMap[$rStreamID] ?: array());
		unset($rBouquetMap);
		return $rReturn;
	}
}
