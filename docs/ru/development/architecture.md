# Обзор архитектуры

## Тип проекта

Структурированный монолит PHP с модульным дополнительным слоем.

- Никакого DDD, никакой гексагональности, никакой чистой архитектуры — намеренно.
- Split by context with minimal abstractions: `Controller → Service → Repository`.
- Два артефакта сборки из одной кодовой базы: **главный** (полная панель) и **фунт** (подмножество load balancer).

---

## Исходное дерево

|Путь|Роль|
| ---- | ---- |
| `src/Core/` |Примитивы фреймворка: Контейнер DI, события, HTTP/маршрутизатор, конфигурация, авторизация, ведение журнала|
| `src/Domain/` |Бизнес-контексты: Поток, VOD, линия, пользователь, сервер, безопасность и т.д.|
| `src/Infrastructure/` |Внешние адаптеры: `DatabaseFactory`, считыватели кэша, Redis, TMDb|
| `src/Streaming/` |Потоковая подсистема: bootstrap, аутентификация, доставка, балансировщик, защита|
| `src/Modules/` |Дополнительный слой расширения — загружается с помощью `ModuleLoader`|
| `src/Public/` |Передний контроллер, маршрутизатор, контроллеры, представления, ресурсы|
| `src/Cli/` |Консольные команды и точки входа в cron|
| `src/Ministra/` | Stalker Portal — in core; served at `/home/xc_vm/Ministra` |

---

## Модель времени выполнения

Зависимости перетекают друг в друга — модули могут использовать ядро и домен, но никогда наоборот.

```
Public/index.php
    └── XC_Bootstrap::boot(BootContext::Admin)
            └── ServiceContainer (DI)
                    ├── EventDispatcher (PSR-14)
                    ├── ModuleLoader → loadAll() → bootAll()
                    └── Router → dispatch()
```

Классы домена и модуля принимают **нет** и `$db` в своем конструкторе. Они
`use \XcVm\Infrastructure\Database\DatabaseAware` и вызываем `self::db()`, который лениво
устраняет общее соединение. `bootstrap.php::wireDomainDatabase()` устанавливает это соединение
**однажды** для каждой загрузки (через `DatabaseAware::setDb()`) — нет проводки для каждого класса и нет
`global $db` в пути веб-запроса.

---

## Модульная система

Модули представляют собой изолированные каталоги под `src/Modules/` с манифестом `module.json`
и класс, расширяющий `BaseModule`. Полную справочную информацию (и связанные страницы о жизненном цикле / точках расширения) смотрите в [Разработка модуля](module-authoring.md).

```
src/Modules/my-module/
├── module.json          # metadata
├── MyModuleModule.php   # extends BaseModule, namespace XcVm\Module\MyModule
└── ...
```

---

## Контексты начальной загрузки

Четыре контекста определяют, какие подсистемы инициализируются. Смотрите [Контексты начальной загрузки](bootstrap-contexts.md).

|Контекст|Используется для|
| ------- | -------- |
| `BootContext::Minimal` |Скрипты, которым нужны только пути / конфигурация|
| `BootContext::Cli` |Задания Cron и команды CLI|
| `BootContext::Stream` |Конечные точки потоковой передачи|
| `BootContext::Admin` |Панель администратора/реселлера|

---

## Варианты сборки (ОСНОВНАЯ и LB)

| |главный|фунт|
| --- | ---- | -- |
|Панель администратора|✅|❌|
|Потоковый|✅|✅|
|Модульная система|✅|подмножество|

Управляется перечислением `ServerEnvironment` и каждым полем `module.json` `environment`
(`main` / `lb` / `any`). At boot, `ModuleLoader::getCurrentEnvironment()` resolves the node's
окружение из константы `SERVER_TYPE` (`'lb'` → `ServerEnvironment::LoadBalancer`, иначе
`ServerEnvironment::Main`); модуль, у которого `environment` не соответствует узлу, пропускается, поэтому
LB получает **подмножество** модулей.

---

## Ключевые моменты расширения

|Механизм|Как использовать|
| --------- | ---------- |
|События PSR-14|`EventDispatcher::listen()` / `#[ListensTo]` — смотрите [Система событий](event-system.md)|
|Оформление сервиса|`$container->decorate('id', callable, priority)` — смотрите [Точки расширения модуля](module-extension-points.md#di-container-and-service-decoration)|
|Потоковое промежуточное программное обеспечение|Реализовать `StreamMiddlewareProviderInterface` — см. [Точки расширения модуля](module-extension-points.md#stream-middleware)|
|Записи Cron|`getCronEntries()` в классе module — смотрите [Точки расширения модуля](module-extension-points.md#cron-task)|
|Миграции баз данных|`MigratableInterface::getMigrations()` — смотрите [Точки расширения модуля](module-extension-points.md#versioned-migrations-migratableinterface)|

---

## Правила для участников

1. Модули не должны изменять основные файлы.
2. Никаких `eval`, исправлений ошибок или замены файлов во время выполнения.
3. Любой модуль можно отключить с помощью `config/modules.php`, не прикасаясь к ядру.
4. Защищенные сервисы (`db`, `settings`, `config`, `auth`) не могут быть оформлены.
5. Синхронизируйте документы EN и RU в одном и том же коммите.
