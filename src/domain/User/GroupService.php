<?php

class GroupService {
	public static function process($rData) {
		return API::processGroupLegacy($rData);
	}
}
