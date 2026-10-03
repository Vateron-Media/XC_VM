<?php

namespace XcVm\Core\Process;

/**
 * Параллельное выполнение команд
 *
 * Запускает набор shell-команд в параллель через Thread.
 * Поддерживает пул с ограничением одновременных процессов.
 *
 * Использование:
 *   $mt = new Multithread(['cmd1', 'cmd2', 'cmd3'], $poolSize);
 *   $output = $mt->run();
 *
 * @see Thread
 *
 * @package XC_VM_Core_Process
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class Multithread {
	/** @var array Вывод каждой команды */
	public $output = [];

	/** @var array Ошибки каждой команды */
	public $error = [];

	/** @var Thread[]|null Активные потоки */
	public $thread;

	/** @var array Команды в работе */
	public $commands = [];

	/** @var bool Используется ли пул */
	public $hasPool = false;

	/** @var array Очередь команд для пула */
	public $toExecuted = [];

	/**
	 * @param array $commands Массив shell-команд
	 * @param int $sizePool Размер пула (0 = без ограничений)
	 */
	public function __construct(array $commands, int $sizePool = 0) {
		$this->hasPool = 0 < $sizePool;
		if ($this->hasPool) {
			$this->toExecuted = array_splice($commands, $sizePool);
		}
		$this->commands = $commands;
		foreach ($this->commands as $key => $command) {
			$this->thread[$key] = Thread::create($command);
		}
	}

	/**
	 * Выполнить все команды и дождаться завершения
	 *
	 * @return array Массив выводов
	 */
	public function run() {
		while (0 < count($this->commands)) {
			foreach ($this->commands as $key => $command) {
				if (!isset($this->thread[$key])) {
					unset($this->commands[$key]);
					$this->launchNextInQueue();
					continue;
				}
				if (!isset($this->output[$key])) {
					$this->output[$key] = '';
				}
				if (!isset($this->error[$key])) {
					$this->error[$key] = '';
				}
				$this->output[$key] .= @$this->thread[$key]->listen();
				$this->error[$key] .= @$this->thread[$key]->getError();
				if ($this->thread[$key]->isActive()) {
					$this->output[$key] .= $this->thread[$key]->listen();
					if ($this->thread[$key]->isBusy()) {
						$this->thread[$key]->close();
						unset($this->commands[$key]);
						$this->launchNextInQueue();
					}
				} else {
					$this->thread[$key]->close();
					unset($this->commands[$key]);
					$this->launchNextInQueue();
				}
			}
		}
		return $this->output;
	}

	/**
	 * Запустить следующую команду из очереди
	 *
	 * @return bool|null
	 */
	public function launchNextInQueue() {
		if (count($this->toExecuted) != 0) {
			reset($this->toExecuted);
			$keyToExecuted = key($this->toExecuted);
			$this->commands[$keyToExecuted] = $this->toExecuted[$keyToExecuted];
			$this->thread[$keyToExecuted] = Thread::create($this->toExecuted[$keyToExecuted]);
			unset($this->toExecuted[$keyToExecuted]);
		} else {
			return true;
		}
		return null;
	}

	/**
	 * Run commands with at most $rSize at a time, taking each from $rCommands
	 * only when a slot frees up, so a generator can feed tens of thousands of
	 * them without holding them in memory. Output is discarded; the loop polls
	 * every $rPollMicros instead of spinning.
	 *
	 * @param iterable $rCommands   Shell commands.
	 * @param int      $rSize       Maximum running at once (at least 1).
	 * @param int      $rPollMicros Poll interval while every slot is busy.
	 * @return int How many commands were started.
	 */
	public static function pool(iterable $rCommands, int $rSize, int $rPollMicros = 100000): int {
		$rSize = max(1, $rSize);
		$rNull = [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];
		$rRunning = [];
		$rStarted = 0;
		foreach ($rCommands as $rCommand) {
			$rRunning = self::waitBelow($rRunning, $rSize, $rPollMicros);
			$rProcess = proc_open($rCommand, $rNull, $rPipes);
			if (is_resource($rProcess)) {
				$rRunning[] = $rProcess;
				$rStarted++;
			}
		}
		self::waitBelow($rRunning, 1, $rPollMicros);
		return $rStarted;
	}

	/**
	 * Wait until fewer than $rLimit processes run, closing the finished ones.
	 *
	 * @param resource[] $rRunning
	 * @param int        $rLimit
	 * @param int        $rPollMicros
	 * @return resource[] The ones still running.
	 */
	private static function waitBelow(array $rRunning, int $rLimit, int $rPollMicros): array {
		while (true) {
			foreach ($rRunning as $rKey => $rProcess) {
				if (!proc_get_status($rProcess)['running']) {
					proc_close($rProcess);
					unset($rRunning[$rKey]);
				}
			}
			if (count($rRunning) < $rLimit) {
				return $rRunning;
			}
			usleep($rPollMicros);
		}
	}
}
