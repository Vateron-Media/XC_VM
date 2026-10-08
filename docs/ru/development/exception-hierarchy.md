# Иерархия исключений

Исключения фреймворка XC_VM расширяют `XcVmException` — пустую базу **маркер**
(`class XcVmException extends \RuntimeException {}`, это не добавляет никаких дополнительных данных) — таким образом, вызывающие абоненты могут
охватите все семейство одним `catch (XcVmException)` или нацелитесь на определенную подсистему.

> **Масштаб.** Эта типизированная иерархия охватывает только **Контейнер DI** и **модульная система**.
> Это не вся панель целиком: конечные точки потоковой передачи/аутентификации сообщают о сбоях через
> `generateError()` (без исключений), и большая часть кода домена/CLI выдает простой
> исключения `\RuntimeException` или SPL — они по-прежнему совпадают с `catch (XcVmException)` только тогда, когда
> класс фактически расширяет его.

---

## Дерево

```
\Exception
└── \RuntimeException
    └── XcVmException
        ├── Container
        │   └── ContainerException      (PSR-11 ContainerExceptionInterface)
        │       ├── CircularDependencyException
        │       ├── ServiceCreationException
        │       └── NotFoundException   (PSR-11 NotFoundExceptionInterface) *
        └── Module
            └── ModuleException
                ├── ModuleNotFoundException
                ├── ModuleLoadException
                ├── ModuleManifestException
                └── ModuleCycleException
```

> \* `NotFoundException` расширяет `ContainerException` (таким образом, он принадлежит этому дереву), но он
> физически находится в `src/Core/Container/Psr/NotFoundException.php` под пространством имен
> `XcVm\Core\Container\Psr` — **нет** в `Core/Exception/Container/`.

---

## Исключения для контейнеров

|Класс|Когда его бросают|
| ----- | ----------- |
| `ContainerException` |База данных обо всех неисправностях контейнеров|
| `CircularDependencyException` |Заводской график службы содержит цикл|
| `ServiceCreationException` |Заводской вызов был произведен при создании сервиса|
| `NotFoundException` |`get($id)` запрос на незарегистрированную услугу|

`NotFoundException` реализует оба интерфейса PSR-11, поэтому контейнер совместим:

```php
try {
    $service = $container->get('unknown');
} catch (NotFoundException $e) {
    // PSR-11 NotFoundExceptionInterface
}
```

---

## Исключения из модулей

|Класс|Когда его бросают|
| ----- | ----------- |
| `ModuleException` |База данных для всех отказов модулей|
| `ModuleNotFoundException` |Отсутствует необходимый модуль зависимостей|
| `ModuleLoadException` |Файл модуля не может быть загружен или класс не найден|
| `ModuleManifestException` |`module.json` не может быть использовано: это не объект JSON, `dependencies` или `optional_dependencies` не список имен, или `environment` не `main`, `lb` или `any`. Выброшенный `ModuleLoader::readManifest()` и перехваченный загрузчиком, который регистрирует его и пропускает модуль: он не экранируется `loadAll()`.|
| `ModuleCycleException` |Граф зависимостей имеет топологическую сортировку, генерируемую циклом `ModuleLoader`, с указанием пути цикла (`a -> b -> a`) в сообщении. Он экранирует `loadAll()` для цикла, который уже находится на диске. (В некоторых блоках документов `@throws` указано `\RuntimeException`; это всего лишь базовый тип — `ModuleCycleException` расширяет его с помощью `XcVmException`.)|

`ModuleManager` не устанавливает такой модуль на место: загрузка, установка из хранилища и обновление
отклоняются с помощью простого `\RuntimeException` — `Модуль '<name>' содержит модуль.json, который не может
be loaded (…).` or `Module '<name>' would close a dependency cycle (a -> b -> a).` — and the
установленные файлы и записанная версия сохраняются.

---

## Перехват по подсистемам

```php
// Catch any XC_VM exception
try {
    $loader->loadAll();
} catch (XcVmException $e) {
    logger()->error($e->getMessage());
}

// Catch only module-related failures
try {
    $loader->loadAll();
} catch (ModuleException $e) {
    // ModuleCycleException: a module with an unusable manifest or a
    // missing dependency is skipped and logged, not thrown
}

// Catch container-specific failures
try {
    $container->get('missing');
} catch (ContainerException $e) {
    // CircularDependencyException | NotFoundException | ...
}
```

---

## Добавление или выбор исключения

- **Который нужно выбросить:** используйте наиболее конкретный существующий тип (например, `ModuleManifestException`
для неудачного `module.json`). Если ничего не подходит и это сбой на уровне фреймворка, выбросьте
`XcVmException` (или новый подкласс), чтобы его можно было отслеживать как одно семейство. Домен/бизнес
ошибки, не связанные с работой фреймворка, могут привести к появлению простого сообщения `\RuntimeException` /
`\InvalidArgumentException`.
- **Добавление категории:** создайте класс в соответствии с `src/Core/Exception/<Subsystem>/`, расширьте
база подсистемы (`ContainerException` / `ModuleException`) — или `XcVmException` для нового
подсистему — и добавьте ее в дерево выше. Регистрация не требуется; это простой PHP.

---

## Местоположение

```
src/Core/Exception/
├── XcVmException.php
├── Container/
│   ├── ContainerException.php
│   ├── CircularDependencyException.php
│   └── ServiceCreationException.php
└── Module/
    ├── ModuleException.php
    ├── ModuleNotFoundException.php
    ├── ModuleLoadException.php
    ├── ModuleManifestException.php
    └── ModuleCycleException.php
```

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/Core/Exception/` |Базовые классы исключений и иерархия проекта|
