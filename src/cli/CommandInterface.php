<?php

/**
 * Контракт для CLI-команд и cron-задач.
 *
 * Каждая команда — один класс, реализующий этот интерфейс.
 * Регистрируется в CommandRegistry по имени (например 'startup', 'cron:servers').
 *
 * @package XC_VM_CLI
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

interface CommandInterface {

	/**
	 * Уникальное имя команды.
	 *
	 * Формат: 'name' для CLI-команд, 'cron:name' для cron-задач.
	 * Используется при вызове: php console.php <name>
	 */
	public function getName(): string;

	/**
	 * Краткое описание (одна строка). Выводится в --help.
	 */
	public function getDescription(): string;

	/**
	 * Выполнение команды.
	 *
	 * @param array $rArgs  Аргументы командной строки ($argv без первых двух элементов)
	 * @return int  Exit code: 0 = успех, >0 = ошибка
	 */
	public function execute(array $rArgs): int;
}
