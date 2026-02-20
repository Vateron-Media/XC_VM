<?php

/**
 * XC_VM — Логгер клиентских запросов (запись в файл для последующей вставки в БД)
 *
 * Записывает события клиентского стриминга в файл client_request.log.
 * Формат: base64-encoded JSON, одна строка на запись.
 *
 * Записи периодически обрабатываются cron-задачей и вставляются в таблицу
 * client_logs в базе данных.
 *
 * Типичные события: AUTH_FAILED, USER_EXPIRED, USER_BAN, IP_MISMATCH,
 *                   COUNTRY_DISALLOW, RESTREAM_DETECT, LINE_CREATE_FAIL и т.д.
 *
 * Извлечено из: StreamingUtilities::clientLog()
 *
 * @see LoggerInterface
 */

require_once __DIR__ . '/LoggerInterface.php';

class DatabaseLogger implements LoggerInterface
{
    /**
     * Путь к файлу лога клиентских запросов.
     *
     * @var string|null
     */
    private static ?string $logFile = null;

    /**
     * Настройка: включено ли сохранение клиентских логов.
     * Соответствует настройке client_logs_save из $rSettings.
     *
     * @var int|null null = не задано (проверяем через StreamingUtilities), 0 = выкл, 1+ = вкл
     */
    private static ?int $enabled = null;

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
     * Установить, включено ли логирование клиентских запросов.
     *
     * @param int $value 0 — выключено, 1+ — включено
     */
    public static function setEnabled(int $value): void
    {
        self::$enabled = $value;
    }

    /**
     * Получить текущий путь к файлу лога.
     *
     * @return string
     */
    public static function getLogFile(): string
    {
        if (self::$logFile !== null) {
            return self::$logFile;
        }

        if (defined('LOGS_TMP_PATH')) {
            return LOGS_TMP_PATH . 'client_request.log';
        }

        return '/tmp/xc_vm_client.log';
    }

    /**
     * Записать клиентское событие стриминга.
     *
     * Соответствует старому формату StreamingUtilities::clientLog().
     * Сигнатура через LoggerInterface, но внутренне хранит расширенные данные.
     *
     * @param string     $type    Действие (AUTH_FAILED, USER_EXPIRED, и т.д.)
     * @param string     $message Не используется для клиентских логов (передавать '')
     * @param string|int $extra   Дополнительные данные
     * @param int        $line    Не используется (совместимость с LoggerInterface)
     */
    public static function log(string $type, string $message, $extra = '', int $line = 0): void
    {
        // По умолчанию логирование включено (если не задано явно)
        if (self::$enabled !== null && self::$enabled == 0) {
            return;
        }

        $rUserAgent = (!empty($_SERVER['HTTP_USER_AGENT'])
            ? htmlentities($_SERVER['HTTP_USER_AGENT'])
            : '');

        $rQueryString = (!empty($_SERVER['QUERY_STRING'])
            ? htmlentities($_SERVER['QUERY_STRING'])
            : '');

        $rData = [
            'user_id'      => 0,
            'stream_id'    => 0,
            'action'       => $type,
            'query_string' => $rQueryString,
            'user_agent'   => $rUserAgent,
            'user_ip'      => '',
            'time'         => time(),
            'extra_data'   => (string) $extra,
        ];

        file_put_contents(
            self::getLogFile(),
            base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Записать клиентское событие стриминга (расширенная версия).
     *
     * Прямой аналог StreamingUtilities::clientLog() с полными параметрами.
     *
     * @param int    $streamID ID потока
     * @param int    $userID   ID пользователя
     * @param string $action   Действие (AUTH_FAILED, USER_EXPIRED, и т.д.)
     * @param string $ip       IP-адрес клиента
     * @param string $data     Дополнительные данные (JSON или строка)
     * @param bool   $bypass   Записать даже если логирование выключено
     */
    public static function clientLog(
        int $streamID,
        int $userID,
        string $action,
        string $ip,
        string $data = '',
        bool $bypass = false
    ): void {
        // Проверяем настройку: включено ли логирование
        if (!$bypass) {
            // Если задано через setEnabled
            if (self::$enabled !== null && self::$enabled == 0) {
                return;
            }

            // Если не задано — проверяем через StreamingUtilities (обратная совместимость)
            if (self::$enabled === null && class_exists('StreamingUtilities', false)) {
                if (isset(StreamingUtilities::$rSettings['client_logs_save'])
                    && StreamingUtilities::$rSettings['client_logs_save'] == 0) {
                    return;
                }
            }
        }

        $rUserAgent = (!empty($_SERVER['HTTP_USER_AGENT'])
            ? htmlentities($_SERVER['HTTP_USER_AGENT'])
            : '');

        $rQueryString = (!empty($_SERVER['QUERY_STRING'])
            ? htmlentities($_SERVER['QUERY_STRING'])
            : '');

        $rData = [
            'user_id'      => $userID,
            'stream_id'    => $streamID,
            'action'       => $action,
            'query_string' => $rQueryString,
            'user_agent'   => $rUserAgent,
            'user_ip'      => $ip,
            'time'         => time(),
            'extra_data'   => $data,
        ];

        file_put_contents(
            self::getLogFile(),
            base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . "\n",
            FILE_APPEND
        );
    }
}
