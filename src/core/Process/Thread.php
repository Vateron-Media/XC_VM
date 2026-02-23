<?php

/**
 * XC_VM — Обёртка для процесса
 *
 * Управление дочерним процессом через proc_open().
 * Используется совместно с Multithread для параллельного выполнения.
 *
 * Извлечён из crons/cache_engine.php, modules/plex/PlexCron.php,
 * modules/watch/WatchCron.php (устранение дублирования — Фаза 5 аудит).
 *
 * @see Multithread
 */

class Thread {

    /** @var resource|null proc_open handle */
    public $process = null;

    /** @var array Pipes (stdin, stdout, stderr) */
    public $pipes = null;

    /** @var string Внутренний буфер */
    public $buffer = null;

    /** @var string Накопленный stdout */
    public $output = null;

    /** @var string Накопленный stderr */
    public $error = null;

    /** @var int Таймаут в секундах (0 = без ограничений) */
    public $timeout = null;

    /** @var int Время запуска процесса */
    public $start_time = null;

    public function __construct() {
        $this->process = 0;
        $this->buffer = '';
        $this->pipes = (array) null;
        $this->output = '';
        $this->error = '';
        $this->start_time = time();
        $this->timeout = 0;
    }

    /**
     * Создать новый процесс из команды
     *
     * @param string $command Shell-команда
     * @return Thread
     */
    public static function create($command) {
        $t = new Thread();
        $descriptor = array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w'));
        $t->process = proc_open($command, $descriptor, $t->pipes);
        stream_set_blocking($t->pipes[1], 0);
        stream_set_blocking($t->pipes[2], 0);
        return $t;
    }

    /**
     * Проверить, активен ли процесс
     *
     * @return bool
     */
    public function isActive() {
        $this->buffer .= $this->listen();
        $f = stream_get_meta_data($this->pipes[1]);
        return !$f['eof'];
    }

    /**
     * Закрыть процесс
     *
     * @return int Exit code
     */
    public function close() {
        $r = proc_close($this->process);
        $this->process = null;
        return $r;
    }

    /**
     * Отправить данные в stdin процесса
     *
     * @param string $thought
     */
    public function tell($thought) {
        fwrite($this->pipes[0], $thought);
    }

    /**
     * Прочитать вывод из stdout
     *
     * @return string
     */
    public function listen() {
        $buffer = $this->buffer;
        $this->buffer = '';
        while ($r = fgets($this->pipes[1], 1024)) {
            $buffer .= $r;
            $this->output .= $r;
        }
        return $buffer;
    }

    /**
     * Получить статус процесса
     *
     * @return array
     */
    public function getStatus() {
        return proc_get_status($this->process);
    }

    /**
     * Проверить, превышен ли таймаут
     *
     * @return bool
     */
    public function isBusy() {
        return 0 < $this->timeout && $this->start_time + $this->timeout < time();
    }

    /**
     * Прочитать stderr
     *
     * @return string
     */
    public function getError() {
        $buffer = '';
        while ($r = fgets($this->pipes[2], 1024)) {
            $buffer .= $r;
        }
        return $buffer;
    }
}
