# Обзор архитектуры

## Тип проекта

Структурированный PHP-монолит с модульным слоем расширений.

- Без DDD, Hexagonal или Clean Architecture — намеренное решение.
- Разделение по контекстам с минимумом абстракций: `Controller → Service → Repository`.
- Два артефакта сборки из одной кодовой базы: **MAIN** (полная панель) и **LB** (load balancer).

---

## Структура src

| Путь | Роль |
| ---- | ---- |
| `src/core/` | Инфраструктурные примитивы: DI-контейнер, события, HTTP, конфиг, auth, логирование |
| `src/domain/` | Бизнес-контексты: Stream, VOD, Line, User, Server, Security и др. |
| `src/modules/` | Опциональный слой расширений — загружается `ModuleLoader` |
| `src/public/` | Front controller, router, controllers, views, assets |
| `src/cli/` | Консольные команды и точки входа для cron |
| `src/ministra/` | Stalker Portal — изолированная подсистема (`BoundaryInterface`) |

---

## Модель рантайма

Зависимости направлены внутрь — модули могут использовать core и domain, но не наоборот.

```
public/index.php
    └── XC_Bootstrap::boot(BootContext::ADMIN)
            └── ServiceContainer (DI)
                    ├── EventDispatcher (PSR-14)
                    ├── ModuleLoader → loadAll() → bootAll()
                    └── Router → dispatch()
```

Domain-классы получают базу данных через инъекцию `setDb()` (вызывается из
`bootstrap.php::wireDomainDatabase()`). `global $db` в web-пути запроса отсутствует.

---

## Система модулей

Модули — изолированные директории в `src/modules/` с манифестом `module.json`
и классом, расширяющим `BaseModule`. Полный справочник: [Система модулей](modules.md).

```
src/modules/my-module/
├── module.json           # метаданные
├── MyModuleModule.php    # extends BaseModule, namespace XcVm\Module\MyModule
└── ...
```

---

## Контексты Bootstrap

Четыре контекста определяют набор инициализируемых подсистем. Подробнее: [Контексты Bootstrap](bootstrap-contexts.md).

| Контекст | Применение |
| -------- | ---------- |
| `BootContext::MINIMAL` | Скрипты, которым нужны только пути/конфиг |
| `BootContext::CLI` | Cron-задачи и CLI-команды |
| `BootContext::STREAM` | Стриминговые эндпоинты |
| `BootContext::ADMIN` | Панель администратора / реселлера |

---

## Варианты сборки (MAIN vs LB)

| | MAIN | LB |
| --- | ---- | -- |
| Панель администратора | ✅ | ❌ |
| Стриминг | ✅ | ✅ |
| Система модулей | ✅ | подмножество |

Управляется enum `ServerEnvironment` и полем `environment` в `module.json` (`main` / `lb` / `any`).

---

## Ключевые точки расширения

| Механизм | Как использовать |
| --------- | ---------------- |
| PSR-14 события | `EventDispatcher::listen()` или атрибут `#[ListensTo]` |
| Декорирование сервисов | `$container->decorate('id', callable, priority)` |
| Stream middleware | Реализовать `StreamMiddlewareProviderInterface` |
| Cron-записи | Переопределить `getCronEntries()` в классе модуля |
| DB-миграции | Реализовать `MigratableInterface::getMigrations()` |

---

## Правила для контрибьюторов

1. Модули не должны изменять файлы ядра.
2. Запрещены `eval`, monkey patching и подмена файлов во время выполнения.
3. Любой модуль можно отключить через `config/modules.php` без изменений ядра.
4. Защищённые сервисы (`db`, `settings`, `config`, `auth`) нельзя декорировать.
5. EN и RU документация обновляются в одном коммите.
