<?php

/**
 * Общий функционал для cron-задач.
 *
 * Выносит повторяющийся boilerplate: проверка пользователя, cron lock,
 * shutdown handler (закрытие БД + удаление lock-файла).
 */
trait CronTrait {

    /** @var string|null Путь к lock-файлу */
    protected $rIdentifier;

    /**
     * Проверить что процесс запущен от пользователя xc_vm.
     */
    protected function assertRunAsXcVm(): bool {
        if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'xc_vm') {
            echo "Please run as XC_VM!\n";
            return false;
        }
        return true;
    }

    /**
     * Проверить что процесс запущен от root.
     */
    protected function assertRunAsRoot(): bool {
        if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'root') {
            echo "Please run as root!\n";
            return false;
        }
        return true;
    }

    /**
     * Установить заголовок процесса + time limit.
     */
    protected function setProcessTitle(string $rTitle): void {
        set_time_limit(0);
        cli_set_process_title($rTitle);
    }

    /**
     * Получить cron lock (уникальный файл в CRONS_TMP_PATH).
     * Если lock уже занят — выходит с кодом 0.
     */
    protected function acquireCronLock(): void {
        $this->rIdentifier = CRONS_TMP_PATH . md5(
            Encryption::generateUniqueCode(SettingsManager::getAll()['live_streaming_pass']) . static::class
        );
        ProcessManager::acquireCronLock($this->rIdentifier);
    }

    /**
     * Зарегистрировать shutdown handler: закрытие БД + удаление lock.
     */
    protected function registerShutdown(): void {
        $rIdentifier = &$this->rIdentifier;
        register_shutdown_function(static function () use (&$rIdentifier) {
            global $db;
            if (isset($db) && is_object($db)) {
                $db->close_mysql();
            }
            if (!empty($rIdentifier) && file_exists($rIdentifier)) {
                @unlink($rIdentifier);
            }
        });
    }

    /**
     * Стандартная инициализация cron-задачи.
     *
     * @param string $rTitle Заголовок процесса (например 'XC_VM[Activity]')
     */
    protected function initCron(string $rTitle): void {
        $this->registerShutdown();
        $this->setProcessTitle($rTitle);
        $this->acquireCronLock();
    }
}
