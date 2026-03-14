<?php

/**
 * Контракт для CLI-команд и cron-задач.
 *
 * Каждая команда — один класс, реализующий этот интерфейс.
 * Регистрируется в CommandRegistry по имени (например 'startup', 'cron:servers').
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
