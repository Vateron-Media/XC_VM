<?php

/**
 * XC_VM — Контракт логирования
 *
 * Единый интерфейс для всех реализаций логгеров (файл, БД, и т.д.).
 * Каждая реализация сама решает, куда и в каком формате писать.
 *
 * @see FileLogger      Логирование в файл (ошибки PDO, EPG, и т.д.)
 * @see DatabaseLogger  Логирование клиентских запросов стриминга
 */
interface LoggerInterface
{
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
