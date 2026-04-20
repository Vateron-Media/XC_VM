<?php

/*
 * XC_VM — Утилиты для работы с SQL-запросами
 *
 * Статические методы для подготовки данных и проверки существования записей.
 */

class QueryHelper {
	public static function prepareColumn($rValue) {
		return strtolower(preg_replace('/[^a-z0-9_]+/i', '', $rValue));
	}

	public static function prepareArray($rArray) {
		$UpdateData = $rColumns = $rPlaceholder = $rData = array();

		foreach (array_keys($rArray) as $rKey) {
			$rColumns[] = '`' . self::prepareColumn($rKey) . '`';
			$UpdateData[] = '`' . self::prepareColumn($rKey) . '` = ?';
		}

		foreach (array_values($rArray) as $rValue) {
			if (is_array($rValue)) {
				$rValue = json_encode($rValue, JSON_UNESCAPED_UNICODE);
			} else {
				if (is_null($rValue) || strtolower($rValue) == 'null') {
					$rValue = null;
				}
			}

			$rPlaceholder[] = '?';
			$rData[] = $rValue;
		}

		return array('placeholder' => implode(',', $rPlaceholder), 'columns' => implode(',', $rColumns), 'data' => $rData, 'update' => implode(',', $UpdateData));
	}

	public static function verifyPostTable($rTable, $rData = array(), $rOnlyExisting = false) {
		global $db;
		$rReturn = array();
		$db->query('SELECT `column_name`, `column_default`, `is_nullable`, `data_type` FROM `information_schema`.`columns` WHERE `table_schema` = (SELECT DATABASE()) AND `table_name` = ? ORDER BY `ordinal_position`;', $rTable);

		foreach ($db->get_rows() as $rRow) {
			if ($rRow['column_default'] != 'NULL') {
			} else {
				$rRow['column_default'] = null;
			}

			$rForceDefault = false;

			if ($rRow['is_nullable'] != 'NO' || $rRow['column_default']) {
			} else {
				if (in_array($rRow['data_type'], array('int', 'float', 'tinyint', 'double', 'decimal', 'smallint', 'mediumint', 'bigint', 'bit'))) {
					$rRow['column_default'] = 0;
				} else {
					$rRow['column_default'] = '';
				}

				$rForceDefault = true;
			}

			if (array_key_exists($rRow['column_name'], $rData)) {
				if (empty($rData[$rRow['column_name']]) && !is_numeric($rData[$rRow['column_name']]) && is_null($rRow['column_default'])) {
					$rReturn[$rRow['column_name']] = ($rForceDefault ? $rRow['column_default'] : null);
				} else {
					$rReturn[$rRow['column_name']] = $rData[$rRow['column_name']];
				}
			} else {
				if ($rOnlyExisting) {
				} else {
					$rReturn[$rRow['column_name']] = $rRow['column_default'];
				}
			}
		}

		return $rReturn;
	}

	public static function checkExists($rTable, $rColumn, $rValue, $rExcludeColumn = null, $rExclude = null) {
		global $db;

		if ($rExcludeColumn && $rExclude) {
			$db->query('SELECT COUNT(*) AS `count` FROM `' . self::prepareColumn($rTable) . '` WHERE `' . self::prepareColumn($rColumn) . '` = ? AND `' . self::prepareColumn($rExcludeColumn) . '` <> ?;', $rValue, $rExclude);
		} else {
			$db->query('SELECT COUNT(*) AS `count` FROM `' . self::prepareColumn($rTable) . '` WHERE `' . self::prepareColumn($rColumn) . '` = ?;', $rValue);
		}

		return 0 < $db->get_row()['count'];
	}
}
