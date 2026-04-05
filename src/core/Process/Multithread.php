<?php

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
    public $output = array();

    /** @var array Ошибки каждой команды */
    public $error = array();

    /** @var Thread[]|null Активные потоки */
    public $thread = null;

    /** @var array Команды в работе */
    public $commands = array();

    /** @var bool Используется ли пул */
    public $hasPool = false;

    /** @var array Очередь команд для пула */
    public $toExecuted = array();

    /**
     * @param array $commands Массив shell-команд
     * @param int $sizePool Размер пула (0 = без ограничений)
     */
    public function __construct($commands, $sizePool = 0) {
        $this->hasPool = 0 < $sizePool;
        if (!$this->hasPool) {
        } else {
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
                    if (!$this->thread[$key]->isBusy()) {
                    } else {
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
     * @return bool|void
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
    }
}
