<?php

namespace XcVm\Core\Container;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Container\Psr\ContainerInterface;
use XcVm\Core\Container\Psr\NotFoundException;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Http\Request;
use XcVm\Core\Module\ModuleInterface;

/**
 * Минимальный DI-контейнер (Service Container)
 *
 * Заменяет паттерн `global $db` / `SettingsManager::getAll()` / `API::$db`
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
 *               return new \XcVm\Module\Plex\PlexService($c->get('db'), $c->get('settings'));
 *           });
 *       }
 *   }
 *
 * @package XC_VM_Core_Container
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServiceContainer implements ContainerInterface {

    private static ?self $instance = null;

    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    private array $isFactory = [];

    private array $creating = [];

    /** @var array<string, string[]> */
    private array $tags = [];

    /**
     * Decoration chains: id => priority => decorator[]
     * Each decorator is a class-string or callable(inner, container): mixed
     * @var array<string, array<int, array<class-string|callable>>>
     */
    private array $decorators = [];

    /**
     * Services that modules are not allowed to decorate.
     * @var string[]
     */
    private array $protectedServices = ['db', 'settings', 'config', 'auth'];

    // ─────────────────────────────────────────────────────────
    //  Singleton
    // ─────────────────────────────────────────────────────────

    /**
     * Получить единственный экземпляр.
     *
     * @internal Bootstrap only. Modules receive ServiceContainer via boot(ServiceContainer $c).
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Сбросить контейнер (для тестов).
     */
    public static function resetInstance(): void {
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
    private function __construct() {
    }

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
    public function set(string $id, mixed $value): static {
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
    public function factory(string $id, callable $factory): static {
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
    public function tag(string $id, string $tag): static {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }
        if (!in_array($id, $this->tags[$tag], true)) {
            $this->tags[$tag][] = $id;
        }

        return $this;
    }

    /**
     * Wrap a service with a decorator.
     *
     * The decorator receives the inner service as first constructor argument (class-string)
     * or as the first callable argument (callable form).
     *
     * Multiple decorators on the same service are applied in priority order:
     * highest priority wraps outermost (called first by callers).
     *
     * Example:
     *   $c->decorate('stream.service', \FingerprintDecorator::class, priority: 20);
     *   $c->decorate('stream.service', \LoggingDecorator::class, priority: 10);
     *   // Call order: \FingerprintDecorator → \LoggingDecorator → original
     *
     * @param string               $id        Service identifier
     * @param class-string|callable $decorator Class-string or callable($inner, $container): mixed
     * @param int                  $priority  Higher = outer layer (default 0)
     * @throws \RuntimeException If the service is protected or not registered as a factory
     */
    public function decorate(string $id, string|callable $decorator, int $priority = 0): static {
        if (in_array($id, $this->protectedServices, true)) {
            throw new \XcVm\Core\Exception\Container\ContainerException(
                "ServiceContainer: сервис '{$id}' защищён от декорирования модулями."
            );
        }

        if (!isset($this->factories[$id]) && !array_key_exists($id, $this->resolved)) {
            throw new \XcVm\Core\Exception\Container\ContainerException(
                "ServiceContainer: невозможно декорировать незарегистрированный сервис '{$id}'."
            );
        }

        $this->decorators[$id][$priority][] = $decorator;
        unset($this->resolved[$id]); // rebuild chain on next get()

        return $this;
    }

    /**
     * Return decorator layers for a service (for debugging).
     *
     * @param string $id
     * @return array<int, array<class-string|callable>> priority => decorators[]
     */
    public function getDecoratorChain(string $id): array {
        return $this->decorators[$id] ?? [];
    }

    // ─────────────────────────────────────────────────────────
    //  Получение
    // ─────────────────────────────────────────────────────────

    /**
     * Получить сервис по идентификатору.
     *
     * @param string $id Идентификатор
     * @return mixed
     * @throws NotFoundException           Если сервис не зарегистрирован
     * @throws \RuntimeException            Если обнаружена циклическая зависимость или фабрика бросила исключение
     */
    public function get(string $id): mixed {
        // 1. Уже разрешён (singleton) — мгновенный возврат
        if (array_key_exists($id, $this->resolved) && empty($this->isFactory[$id])) {
            return $this->resolved[$id];
        }

        // 2. Есть фабрика — вызываем
        if (isset($this->factories[$id])) {
            // Детекция циклических зависимостей
            if (isset($this->creating[$id])) {
                $this->throwCircularDependency($id);
            }

            $this->creating[$id] = true;

            try {
                $service = call_user_func($this->factories[$id], $this);
                $service = $this->applyDecorators($id, $service);
            } catch (\XcVm\Core\Exception\XcVmException $e) {
                unset($this->creating[$id]);
                throw $e;
            } catch (\Exception $e) {
                unset($this->creating[$id]);
                throw new \XcVm\Core\Exception\Container\ServiceCreationException(
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

        throw new NotFoundException(
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
    public function getOrDefault(string $id, mixed $default = null): mixed {
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
    public function getTagged(string $tag): array {
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
    public function has(string $id): bool {
        return array_key_exists($id, $this->resolved) || isset($this->factories[$id]);
    }

    /**
     * Список всех зарегистрированных идентификаторов.
     *
     * @return string[]
     */
    public function keys(): array {
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
    public function remove(string $id): static {
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
    public function register(array $services): static {
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
    public function __get(string $id): mixed {
        return $this->get($id);
    }

    /**
     * Магическая проверка: isset($container->db)
     *
     * @param string $id
     * @return bool
     */
    public function __isset(string $id): bool {
        return $this->has($id);
    }

    // ─────────────────────────────────────────────────────────
    //  Decoration
    // ─────────────────────────────────────────────────────────

    /**
     * Apply registered decorators to a freshly created service.
     *
     * Decorators are sorted by priority descending so the highest-priority
     * decorator becomes the outermost wrapper (first to intercept callers).
     *
     * @param string $id      Service identifier
     * @param mixed  $service The base service instance
     * @return mixed Decorated service (or original if no decorators registered)
     */
    private function applyDecorators(string $id, mixed $service): mixed {
        if (empty($this->decorators[$id])) {
            return $service;
        }

        $buckets = $this->decorators[$id];
        krsort($buckets); // highest priority first = outermost wrapper last in application order

        // Build inside-out: iterate from lowest to highest priority
        // so the highest-priority decorator ends up as the outermost layer.
        $layers = array_merge(...array_reverse(array_values($buckets)));

        foreach ($layers as $decorator) {
            if (is_string($decorator)) {
                $service = new $decorator($service);
            } else {
                $service = $decorator($service, $this);
            }
        }

        return $service;
    }

    // ─────────────────────────────────────────────────────────
    //  Internal throw helpers (never return type — R2-4)
    // ─────────────────────────────────────────────────────────

    /**
     * Throw a \XcVm\Core\Exception\Container\CircularDependencyException describing the resolution chain.
     *
     * @param string $id Service id whose creation closed the cycle.
     * @return never
     * @throws \XcVm\Core\Exception\Container\CircularDependencyException Always.
     */
    private function throwCircularDependency(string $id): never {
        throw new \XcVm\Core\Exception\Container\CircularDependencyException(
            "ServiceContainer: циклическая зависимость при создании сервиса '{$id}'. "
            . "Цепочка: " . implode(' → ', array_keys($this->creating)) . " → {$id}"
        );
    }

    // ─────────────────────────────────────────────────────────
    //  Отладка
    // ─────────────────────────────────────────────────────────

    /**
     * Дамп содержимого контейнера (для отладки).
     *
     * @return array
     */
    public function dump(): array {
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
    private function getTagsFor(string $id): array {
        $result = [];
        foreach ($this->tags as $tag => $ids) {
            if (in_array($id, $ids, true)) {
                $result[] = $tag;
            }
        }
        return $result;
    }
}
