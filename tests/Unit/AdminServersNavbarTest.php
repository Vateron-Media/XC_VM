<?php

use XcVm\Core\Module\CoreNavbarProvider;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Cluster Nodes is folded into Manage Servers: the admin navbar no longer has a
 * standalone Cluster Nodes entry (the former servers.cluster → cluster_nodes),
 * and Manage Servers (servers.manage → servers) stays as the single cluster page.
 */
final class AdminServersNavbarTest extends TestCase {

	protected function setUp(): void {
		NavbarRegistry::reset();
	}

	protected function tearDown(): void {
		NavbarRegistry::reset();
	}

	/** @return NavbarItem[] */
	private function serverChildren(): array {
		CoreNavbarProvider::register();
		return NavbarRegistry::getChildren('servers');
	}

	public function testNoStandaloneClusterNodesEntry(): void {
		$rKeys = array_map(static fn(NavbarItem $rItem): string => $rItem->key, $this->serverChildren());

		$this->assertNotContains('servers.cluster', $rKeys, 'Cluster Nodes is folded into Manage Servers, not its own nav entry');
		$this->assertContains('servers.manage', $rKeys, 'Manage Servers stays and is now the single cluster page');
	}

	public function testNoServerNavItemLinksToTheRetiredClusterNodesPage(): void {
		foreach ($this->serverChildren() as $rItem) {
			$this->assertNotSame('cluster_nodes', $rItem->url, $rItem->key . ' must not link to the retired cluster_nodes page');
		}
	}
}
