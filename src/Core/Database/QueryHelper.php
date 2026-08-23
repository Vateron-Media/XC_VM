<?php

namespace XcVm\Core\Database;

/*
 * XC_VM — Утилиты для работы с SQL-запросами
 *
 * Статические методы для подготовки данных и проверки существования записей.
 */

class QueryHelper {
	/**
	 * Sanitize a string into a safe SQL identifier (lowercase, [a-z0-9_]).
	 *
	 * @param string $rValue Raw column/table name.
	 * @return string Sanitized identifier.
	 */
	public static function prepareColumn($rValue) {
		return strtolower(preg_replace('/[^a-z0-9_]+/i', '', $rValue));
	}

	/**
	 * Build SQL fragments for a parameterized INSERT/UPDATE from an assoc array.
	 *
	 * Array values are JSON-encoded; the literal 'null'/PHP null become SQL NULL.
	 *
	 * @param array $rArray Column => value map.
	 * @return array ['columns' => string, 'placeholder' => string, 'data' => array, 'update' => string].
	 */
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

	/**
	 * Map submitted data onto a table's real columns, applying defaults/coercion.
	 *
	 * Reads column metadata from information_schema, fills missing columns with
	 * their defaults, and coerces empty values for numeric columns.
	 *
	 * @param string $rTable        Target table name.
	 * @param array  $rData         Submitted column => value map.
	 * @param bool   $rOnlyExisting When true, skip columns absent from $rData.
	 * @return array Sanitized column => value map ready for prepareArray().
	 */
	public static function verifyPostTable($rTable, $rData = array(), $rOnlyExisting = false) {
		global $db;
		$rReturn = array();
		$db->query('SELECT `column_name`, `column_default`, `is_nullable`, `data_type` FROM `information_schema`.`columns` WHERE `table_schema` = (SELECT DATABASE()) AND `table_name` = ? ORDER BY `ordinal_position`;', $rTable);

		foreach ($db->get_rows() as $rRow) {
			if ($rRow['column_default'] != 'NULL') {
				// strip surrounding quotes returned by some information_schema implementations
				if (is_string($rRow['column_default']) && preg_match("/^'.*'$/", $rRow['column_default'])) {
					$rRow['column_default'] = substr($rRow['column_default'], 1, -1);
				}
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
				// coerce empty string for numeric columns to a safe default
				$rNumericTypes = array('int', 'float', 'tinyint', 'double', 'decimal', 'smallint', 'mediumint', 'bigint', 'bit');
				$rValue = $rData[$rRow['column_name']];
				if ($rValue === '' && in_array($rRow['data_type'], $rNumericTypes)) {
					$rReturn[$rRow['column_name']] = is_null($rRow['column_default']) ? ($rForceDefault ? 0 : null) : $rRow['column_default'];
				} else if (empty($rValue) && !is_numeric($rValue) && is_null($rRow['column_default'])) {
					$rReturn[$rRow['column_name']] = ($rForceDefault ? $rRow['column_default'] : null);
				} else {
					$rReturn[$rRow['column_name']] = $rValue;
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

	/**
	 * Check whether a row exists with the given column value.
	 *
	 * @param string      $rTable         Table name.
	 * @param string      $rColumn        Column to match.
	 * @param mixed       $rValue         Value to match.
	 * @param string|null $rExcludeColumn Optional column to exclude a row by (e.g. 'id').
	 * @param mixed       $rExclude       Value to exclude for $rExcludeColumn.
	 * @return bool True if at least one matching row exists.
	 */
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
