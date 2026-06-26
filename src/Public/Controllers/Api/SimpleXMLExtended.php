<?php

namespace XcVm\Public\Controllers\Api;

/**
 * SimpleXMLExtended — \SimpleXMLElement с поддержкой CDATA.
 *
 * Ранее определялся inline внутри switch/default в enigma2.php.
 * Вынесен на верхний уровень для корректной загрузки автолоадером.
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SimpleXMLExtended extends \SimpleXMLElement {
	public function addCData($rCData) {
		$rNode = dom_import_simplexml($this);
		$rRowner = $rNode->ownerDocument;
		$rNode->appendChild($rRowner->createCDATASection($rCData));
	}
}
