<?php
declare(strict_types=1);

namespace XcVm\Core\Module;

final class LineFormRegistry {
	/** @var array<string, callable(array, string): string> */
	private static array $widgets = [];

	public static function add(string $id, callable $render): void {
		if (!preg_match('/^[a-z0-9_-]+$/', $id)) {
			throw new \InvalidArgumentException("LineFormRegistry: invalid widget id '{$id}'");
		}
		self::$widgets[$id] = $render;
	}

	public static function render(array $line, string $scope): string {
		$html = '';
		foreach (self::$widgets as $render) {
			$html .= (string) $render($line, $scope);
		}
		return $html;
	}

	public static function reset(): void {
		self::$widgets = [];
	}
}
