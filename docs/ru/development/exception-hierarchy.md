# Иерархия исключений

Все исключения XC_VM расширяют диапазон `XcVmException`, так что вызывающие абоненты могут перехватывать все дерево с помощью
один `catch` блокирует или нацелен на определенную подсистему.

---

## Дерево

```
\Exception
└── XcVmException
    ├── Container
    │   └── ContainerException          (PSR-11 ContainerExceptionInterface)
    │       ├── CircularDependencyException
    │       ├── ServiceCreationException
    │       └── NotFoundException       (PSR-11 NotFoundExceptionInterface)
    └── Module
        └── ModuleException
            ├── ModuleNotFoundException
            ├── ModuleLoadException
            ├── ModuleManifestException
            └── ModuleCycleException
```

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
| `ModuleManifestException` |`module.json` отсутствует, неправильно сформирован или не прошел проверку|
| `ModuleCycleException` |Граф зависимостей имеет цикл|

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
    // ModuleNotFoundException | ModuleLoadException | ...
}

// Catch container-specific failures
try {
    $container->get('missing');
} catch (ContainerException $e) {
    // CircularDependencyException | NotFoundException | ...
}
```

---

## Местоположение

```
src/Core/Exception/
├── XcVmException.php
├── Container/
│   ├── ContainerException.php
│   ├── CircularDependencyException.php
│   ├── ServiceCreationException.php
│   └── NotFoundException.php
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
