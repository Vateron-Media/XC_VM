<?php
declare(strict_types=1);

namespace XcVm\Core\Module\Contract;

interface StreamRoutingProviderInterface {
	public function proxyFor(int $lineId): ?int;
}
