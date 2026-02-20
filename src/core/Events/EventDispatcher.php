<?php

class EventDispatcher {
	private static $listeners = array();

	public static function subscribe($eventName, $listener) {
		if (!is_string($eventName) || strlen($eventName) == 0 || !is_callable($listener)) {
			return false;
		}

		if (!isset(self::$listeners[$eventName])) {
			self::$listeners[$eventName] = array();
		}

		self::$listeners[$eventName][] = $listener;
		return true;
	}

	public static function unsubscribe($eventName, $listener = null) {
		if (!isset(self::$listeners[$eventName])) {
			return;
		}

		if ($listener === null) {
			unset(self::$listeners[$eventName]);
			return;
		}

		foreach (self::$listeners[$eventName] as $index => $registered) {
			if ($registered === $listener) {
				unset(self::$listeners[$eventName][$index]);
			}
		}

		self::$listeners[$eventName] = array_values(self::$listeners[$eventName]);
	}

	public static function publish($eventName, $payload = null) {
		if ($eventName instanceof EventInterface) {
			$payload = $eventName->getPayload();
			$eventName = $eventName->getName();
		}

		if (!isset(self::$listeners[$eventName])) {
			return array();
		}

		$results = array();
		foreach (self::$listeners[$eventName] as $listener) {
			$results[] = call_user_func($listener, $payload, $eventName);
		}

		return $results;
	}

	public static function clear() {
		self::$listeners = array();
	}

	public static function getListeners($eventName = null) {
		if ($eventName === null) {
			return self::$listeners;
		}

		return (self::$listeners[$eventName] ?? array());
	}
}
