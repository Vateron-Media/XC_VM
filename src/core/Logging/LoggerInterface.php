<?php

/**
 * Контракт логирования
 *
 * Единый интерфейс для всех реализаций логгеров (файл, БД, и т.д.).
 * Каждая реализация сама решает, куда и в каком формате писать.
 *
 * @see FileLogger      Логирование в файл (ошибки PDO, EPG, и т.д.)
 * @see DatabaseLogger  Логирование клиентских запросов стриминга
 *
 * @package XC_VM_Core_Logging
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

interface LoggerInterface {
    /**
     * Записать лог-сообщение.
     *
     * @param string     $type    Тип события (например: 'pdo', 'epg', 'AUTH_FAILED')
     * @param string     $message Текст сообщения
     * @param string|int $extra   Дополнительные данные (trace, query, и т.д.)
     * @param int        $line    Номер строки (опционально)
     *
     * @return void
     */
    public static function log(string $type, string $message, $extra = '', int $line = 0): void;
}
