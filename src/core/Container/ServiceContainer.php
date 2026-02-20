<?php

/**
 * XC_VM — Минимальный DI-контейнер (Service Container)
 *
 * Заменяет паттерн `global $db` / `CoreUtilities::$rSettings` / `API::$db`
 * единым реестром сервисов с ленивой инициализацией.
 *
 * ──────────────────────────────────────────────────────────────────
 * Концепция:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Контейнер хранит сервисы в двух формах:
 *
 *   1. Фабрика (callable) — функция, которая создаёт сервис.
 *      Вызывается ОДИН раз при первом get(). Результат кэшируется.
 *
 *   2. Готовое значение — уже созданный объект или скалярное значение.
 *      Доступно сразу без вызова фабрики.
 *
 *   Все сервисы — синглтоны по умолчанию (один экземпляр на запрос).
 *   Для фабрик, возвращающих новый экземпляр каждый раз, используйте factory().
 *
 * ──────────────────────────────────────────────────────────────────
 * Использование:
 * ──────────────────────────────────────────────────────────────────
 *
 *   $c = ServiceContainer::getInstance();
 *
 *   // Регистрация фабрики (ленивая, создаётся при первом get)
 *   $c->set('db', function($c) {
 *       $cfg = $c->get('config');
 *       return DatabaseHandler::create($cfg);
 *   });
 *
 *   // Регистрация готового значения
 *   $c->set('config', $_INFO);
 *
 *   // Получение сервиса
 *   $db = $c->get('db');
 *
 *   // Фабрика (новый экземпляр при каждом вызове)
 *   $c->factory('request', function($c) {
 *       return new Request($_GET, $_POST);
 *   });
 *
 *   // Проверка наличия
 *   if ($c->has('redis')) { ... }
 *
 * ──────────────────────────────────────────────────────────────────
 * Обратная совместимость:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Старый код использует `global $db`. Контейнер не ломает это —
 *   bootstrap.php регистрирует $db и в контейнер, и в global scope.
 *   Новый код использует $container->get('db').
 *   По мере миграции global $db выводится из употребления.
 *
 * ──────────────────────────────────────────────────────────────────
 * Для модулей:
 * ──────────────────────────────────────────────────────────────────
 *
 *   class PlexModule implements ModuleInterface {
 *       public function boot(ServiceContainer $container): void {
 *           $db    = $container->get('db');
 *           $cache = $container->get('cache');
 *           $container->set('plex.service', function($c) {
 *               return new PlexService($c->get('db'), $c->get('settings'));
 *           });
 *       }
 *   }
 */

class ServiceContainer {

    /**
     * Единственный экземпляр контейнера (singleton)
     * @var ServiceContainer|null
     */
    private static $instance = null;

    /**
     * Зарегистрированные фабрики: id => callable
     * @var array<string, callable>
     */
    private $factories = [];

    /**
     * Разрешённые (готовые) сервисы: id => mixed
     * @var array<string, mixed>
     */
    private $resolved = [];

    /**
     * Сервисы, помеченные как «фабричные» (новый экземпляр каждый раз)
     * @var array<string, bool>
     */
    private $isFactory = [];

    /**
     * Флаг: сервис сейчас в процессе создания (для детекции циклов)
     * @var array<string, bool>
     */
    private $creating = [];

    /**
     * Теги для группировки сервисов: tag => [id, id, ...]
     * @var array<string, string[]>
     */
    private $tags = [];

    // ─────────────────────────────────────────────────────────
    //  Singleton
    // ─────────────────────────────────────────────────────────

    /**
     * Получить единственный экземпляр.
     *
     * @return ServiceContainer
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Сбросить контейнер (для тестов).
     */
    public static function resetInstance() {
        if (self::$instance !== null) {
            self::$instance->factories = [];
            self::$instance->resolved  = [];
            self::$instance->isFactory = [];
            self::$instance->creating  = [];
            self::$instance->tags      = [];
        }
        self::$instance = null;
    }

    /**
     * Приватный конструктор (singleton).
     */
    private function __construct() {}

    // ─────────────────────────────────────────────────────────
    //  Регистрация
    // ─────────────────────────────────────────────────────────

    /**
     * Зарегистрировать сервис.
     *
     * Если $value — callable (замыкание или [class, method]),
     * он будет вызван ОДИН раз при первом get(). Результат кэшируется.
     *
     * Если $value — не callable, сохраняется как готовое значение.
     *
     * @param string $id    Уникальный идентификатор (например, 'db', 'settings')
     * @param mixed  $value Фабрика (callable) или готовое значение
     * @return $this
     */
    public function set($id, $value) {
        // Удаляем ранее разрешённый сервис при перерегистрации
        unset($this->resolved[$id]);
        unset($this->isFactory[$id]);

        if (is_callable($value) && !is_string($value) && !is_array($value)) {
            // Замыкание → ленивая фабрика (singleton)
            $this->factories[$id] = $value;
        } else {
            // Готовое значение → сразу в resolved
            $this->resolved[$id] = $value;
        }

        return $this;
    }

    /**
     * Зарегистрировать фабричный сервис (новый экземпляр при каждом get).
     *
     * @param string   $id      Идентификатор
     * @param callable $factory Фабрика: function(ServiceContainer $c): mixed
     * @return $this
     */
    public function factory($id, $factory) {
        unset($this->resolved[$id]);
        $this->factories[$id] = $factory;
        $this->isFactory[$id] = true;

        return $this;
    }

    /**
     * Добавить тег к сервису.
     *
     * Теги позволяют группировать связанные сервисы и получать их пакетно.
     * Используются модульной системой для сбора event subscribers,
     * cron-задач, маршрутов и т.д.
     *
     * @param string $id  Идентификатор сервиса
     * @param string $tag Имя тега (например, 'event.subscriber', 'cron')
     * @return $this
     */
    public function tag($id, $tag) {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }
        if (!in_array($id, $this->tags[$tag], true)) {
            $this->tags[$tag][] = $id;
        }

        return $this;
    }

    // ─────────────────────────────────────────────────────────
    //  Получение
    // ─────────────────────────────────────────────────────────

    /**
     * Получить сервис по идентификатору.
     *
     * @param string $id Идентификатор
     * @return mixed
     * @throws RuntimeException Если сервис не найден или обнаружена циклическая зависимость
     */
    public function get($id) {
        // 1. Уже разрешён (singleton) — мгновенный возврат
        if (array_key_exists($id, $this->resolved) && empty($this->isFactory[$id])) {
            return $this->resolved[$id];
        }

        // 2. Есть фабрика — вызываем
        if (isset($this->factories[$id])) {
            // Детекция циклических зависимостей
            if (isset($this->creating[$id])) {
                throw new RuntimeException(
                    "ServiceContainer: циклическая зависимость при создании сервиса '{$id}'. "
                    . "Цепочка: " . implode(' → ', array_keys($this->creating)) . " → {$id}"
                );
            }

            $this->creating[$id] = true;

            try {
                $service = call_user_func($this->factories[$id], $this);
            } catch (Exception $e) {
                unset($this->creating[$id]);
                throw new RuntimeException(
                    "ServiceContainer: ошибка при создании сервиса '{$id}': " . $e->getMessage(),
                    0,
                    $e
                );
            }

            unset($this->creating[$id]);

            // Фабричные сервисы не кэшируются
            if (empty($this->isFactory[$id])) {
                $this->resolved[$id] = $service;
            }

            return $service;
        }

        throw new RuntimeException(
            "ServiceContainer: сервис '{$id}' не зарегистрирован. "
            . "Доступные сервисы: " . implode(', ', $this->keys())
        );
    }

    /**
     * Получить сервис или вернуть значение по умолчанию.
     *
     * @param string $id      Идентификатор
     * @param mixed  $default Значение по умолчанию (если сервис не найден)
     * @return mixed
     */
    public function getOrDefault($id, $default = null) {
        if ($this->has($id)) {
            return $this->get($id);
        }
        return $default;
    }

    /**
     * Получить все сервисы с указанным тегом.
     *
     * @param string $tag Имя тега
     * @return array Массив сервисов [id => service]
     */
    public function getTagged($tag) {
        $services = [];
        if (isset($this->tags[$tag])) {
            foreach ($this->tags[$tag] as $id) {
                if ($this->has($id)) {
                    $services[$id] = $this->get($id);
                }
            }
        }
        return $services;
    }

    /**
     * Проверить, зарегистрирован ли сервис.
     *
     * @param string $id Идентификатор
     * @return bool
     */
    public function has($id) {
        return array_key_exists($id, $this->resolved) || isset($this->factories[$id]);
    }

    /**
     * Список всех зарегистрированных идентификаторов.
     *
     * @return string[]
     */
    public function keys() {
        return array_unique(
            array_merge(
                array_keys($this->resolved),
                array_keys($this->factories)
            )
        );
    }

    /**
     * Удалить сервис из контейнера.
     *
     * @param string $id Идентификатор
     * @return $this
     */
    public function remove($id) {
        unset(
            $this->factories[$id],
            $this->resolved[$id],
            $this->isFactory[$id]
        );

        // Удалить из тегов
        foreach ($this->tags as $tag => &$ids) {
            $ids = array_values(array_filter($ids, function ($v) use ($id) {
                return $v !== $id;
            }));
        }

        return $this;
    }

    // ─────────────────────────────────────────────────────────
    //  Массовая регистрация
    // ─────────────────────────────────────────────────────────

    /**
     * Зарегистрировать несколько сервисов из массива.
     *
     * @param array $services Массив [id => value/callable, ...]
     * @return $this
     */
    public function register(array $services) {
        foreach ($services as $id => $value) {
            $this->set($id, $value);
        }
        return $this;
    }

    // ─────────────────────────────────────────────────────────
    //  ArrayAccess-подобный синтаксис (без implements — не нужны интерфейсы)
    // ─────────────────────────────────────────────────────────

    /**
     * Магический доступ: $container->db вместо $container->get('db')
     *
     * @param string $id
     * @return mixed
     */
    public function __get($id) {
        return $this->get($id);
    }

    /**
     * Магическая проверка: isset($container->db)
     *
     * @param string $id
     * @return bool
     */
    public function __isset($id) {
        return $this->has($id);
    }

    // ─────────────────────────────────────────────────────────
    //  Отладка
    // ─────────────────────────────────────────────────────────

    /**
     * Дамп содержимого контейнера (для отладки).
     *
     * @return array
     */
    public function dump() {
        $result = [];
        foreach ($this->keys() as $id) {
            $status = 'pending';
            $type   = 'unknown';

            if (array_key_exists($id, $this->resolved)) {
                $status = 'resolved';
                $type   = is_object($this->resolved[$id])
                    ? get_class($this->resolved[$id])
                    : gettype($this->resolved[$id]);
            } elseif (isset($this->factories[$id])) {
                $status = !empty($this->isFactory[$id]) ? 'factory' : 'lazy';
                $type   = 'callable';
            }

            $result[$id] = [
                'status' => $status,
                'type'   => $type,
                'tags'   => $this->getTagsFor($id),
            ];
        }

        ksort($result);
        return $result;
    }

    /**
     * Получить все теги для сервиса.
     *
     * @param string $id
     * @return string[]
     */
    private function getTagsFor($id) {
        $result = [];
        foreach ($this->tags as $tag => $ids) {
            if (in_array($id, $ids, true)) {
                $result[] = $tag;
            }
        }
        return $result;
    }
}
