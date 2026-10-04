<?php

use PHPUnit\Framework\TestCase;

/**
 * Every route in Public/routes names a method its controller has.
 *
 * `active_code_edit` and `active_code_delete` named two that did not exist:
 * the request died in the dispatch and the panel answered an empty 200.
 */
class RoutesNameExistingMethodsTest extends TestCase {
	public function testEveryRouteNamesAMethodItsControllerHas(): void {
		$router = new class {
			/** @var array<int, array{0: string, 1: mixed}> */
			public array $routes = [];

			/** @param array<int, mixed> $rArgs */
			public function __call(string $rVerb, array $rArgs): void {
				$this->routes[] = [$rVerb . ' ' . $rArgs[0], $rArgs[1] ?? null];
			}
		};

		foreach (glob(MAIN_HOME . 'Public/routes/*.php') as $rFile) {
			require $rFile;
		}

		$rMissing = [];
		foreach ($router->routes as [$rRoute, $rHandler]) {
			if (is_array($rHandler) && !method_exists($rHandler[0], $rHandler[1])) {
				$rMissing[] = $rRoute . ' -> ' . $rHandler[0] . '::' . $rHandler[1];
			}
		}

		$this->assertGreaterThan(300, count($router->routes));
		$this->assertSame([], $rMissing);
	}
}
