<?php

/**
 * XC_VM — Файловый логгер
 *
 * Записывает бизнес-события в файл в формате base64-encoded JSON.
 * Каждая строка — отдельная запись. Файл блокируется при записи (LOCK_EX).
 *
 * Формат записи:
 *   base64({"type":"pdo","message":"...","extra":"...","line":0,"time":1708300000,"env":"cli"})
 *
 * Фильтрация:
 *   - Игнорируются записи, содержащие 'panel_logs' в extra (рекурсивные логи)
 *   - Игнорируются 'timeout exceeded', 'lock wait timeout', 'duplicate entry' (шумные ошибки MySQL)
 *
 * Извлечено из: CoreUtilities::saveLog()
 *
 * @see LoggerInterface
 */

require_once __DIR__ . '/LoggerInterface.php';

class FileLogger implements LoggerInterface
{
    /**
     * Путь к файлу лога.
     * По умолчанию используется LOGS_TMP_PATH . 'error_log.log'
     *
     * @var string|null
     */
    private static ?string $logFile = null;

    /**
     * Установить путь к файлу лога.
     *
     * @param string $path Полный путь к файлу
     */
    public static function setLogFile(string $path): void
    {
        self::$logFile = $path;
    }

    /**
     * Получить текущий путь к файлу лога.
     * Если не установлен явно, используется LOGS_TMP_PATH . 'error_log.log'
     *
     * @return string
     */
    public static function getLogFile(): string
    {
        if (self::$logFile !== null) {
            return self::$logFile;
        }

        if (defined('LOGS_TMP_PATH')) {
            return LOGS_TMP_PATH . 'error_log.log';
        }

        return '/tmp/xc_vm_error.log';
    }

    /**
     * Записать лог-сообщение в файл.
     *
     * Перед записью проверяется фильтр: шумные ошибки MySQL и рекурсивные
     * обращения к panel_logs игнорируются.
     *
     * @param string     $type    Тип события ('pdo', 'epg', 'error', и т.д.)
     * @param string     $message Текст сообщения
     * @param string|int $extra   Дополнительные данные (SQL-запрос, trace, и т.д.)
     * @param int        $line    Номер строки (опционально)
     */
    public static function log(string $type, string $message, $extra = '', int $line = 0): void
    {
        $extra = (string) $extra;

        // Фильтрация шумных / рекурсивных записей
        if (self::shouldSkip($message, $extra)) {
            return;
        }

        $rData = [
            'type'    => $type,
            'message' => $message,
            'extra'   => $extra,
            'line'    => $line,
            'time'    => time(),
            'env'     => php_sapi_name(),
        ];

        $logLine = base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . "\n";

        file_put_contents(
            self::getLogFile(),
            $logLine,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Проверить, нужно ли пропустить запись (фильтрация шума).
     *
     * @param string $message Текст сообщения
     * @param string $extra   Дополнительные данные
     *
     * @return bool true — пропустить, false — записать
     */
    private static function shouldSkip(string $message, string $extra): bool
    {
        // Рекурсивный лог: запись о таблице panel_logs
        if (stripos($extra, 'panel_logs') !== false) {
            return true;
        }

        // Шумные ошибки MySQL — пропускаем
        $noisy = ['timeout exceeded', 'lock wait timeout', 'duplicate entry'];
        $messageLower = strtolower($message);
        foreach ($noisy as $pattern) {
            if (strpos($messageLower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
