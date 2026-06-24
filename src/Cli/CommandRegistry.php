<?php

/**
 * Реестр CLI-команд.
 *
 * Хранит маппинг имя → экземпляр CommandInterface.
 * Dispatch: ищет команду по имени из $argv и вызывает execute().
 *
 * Команды обнаруживаются автоматически: console.php сканирует
 * cli/Commands/ и cli/CronJobs/ и регистрирует все классы,
 * реализующие CommandInterface.
 *
 * @package XC_VM_CLI
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CommandRegistry {

	/** @var CommandInterface[] name → command */
	private $rCommands = [];

	/**
	 * Зарегистрировать команду.
	 */
	public function register(CommandInterface $rCommand): void {
		$this->rCommands[$rCommand->getName()] = $rCommand;
	}

	/**
	 * Dispatch: найти команду по $argv и выполнить.
	 *
	 * @param array $rArgv  Полный $argv из CLI
	 * @return int  Exit code
	 */
	public function dispatch(array $rArgv): int {
		$rCommandName = $rArgv[1] ?? null;

		if ($rCommandName === null || $rCommandName === '--help' || $rCommandName === '-h') {
			$this->printHelp();
			return 0;
		}

		if ($rCommandName === '--list') {
			$this->printList();
			return 0;
		}

		if (!isset($this->rCommands[$rCommandName])) {
			fwrite(STDERR, "Unknown command: {$rCommandName}\n");
			fwrite(STDERR, "Run with --list to see available commands.\n");
			return 1;
		}

		$rArgs = array_slice($rArgv, 2);
		return $this->rCommands[$rCommandName]->execute($rArgs);
	}

	/**
	 * Получить команду по имени (для тестов и проверок).
	 */
	public function get(string $rName): ?CommandInterface {
		return $this->rCommands[$rName] ?? null;
	}

	/**
	 * Все зарегистрированные команды.
	 *
	 * @return CommandInterface[]
	 */
	public function getAll(): array {
		return $this->rCommands;
	}

	private function printHelp(): void {
		echo "XC_VM Console\n";
		echo "\n";
		echo "Usage: /home/xc_vm/console.php <command> [arguments]\n";
		echo "\n";
		echo "  --list    Show all available commands\n";
		echo "  --help    Show this help message\n";
		echo "\n";
	}

	private function printList(): void {
		echo "Available commands:\n\n";

		$rGrouped = [];
		foreach ($this->rCommands as $rName => $rCommand) {
			$rParts = explode(':', $rName, 2);
			$rGroup = count($rParts) > 1 ? $rParts[0] : '';
			$rGrouped[$rGroup][$rName] = $rCommand->getDescription();
		}

		ksort($rGrouped);

		foreach ($rGrouped as $rGroup => $rItems) {
			if ($rGroup !== '') {
				echo " {$rGroup}\n";
			}
			ksort($rItems);
			foreach ($rItems as $rName => $rDesc) {
				echo sprintf("  %-30s %s\n", $rName, $rDesc);
			}
			echo "\n";
		}
	}
}
