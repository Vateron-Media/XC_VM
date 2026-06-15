# Иерархия исключений

Все исключения XC_VM расширяют `XcVmException`, что позволяет перехватывать
всё дерево одним `catch`-блоком или точечно таргетировать отдельную подсистему.

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

## Исключения контейнера

| Класс | Когда бросается |
| ----- | --------------- |
| `ContainerException` | Базовый класс для всех сбоев контейнера |
| `CircularDependencyException` | В графе фабрик сервиса обнаружен цикл |
| `ServiceCreationException` | Фабричный callable выбросил исключение при создании сервиса |
| `NotFoundException` | `get($id)` вызван для незарегистрированного сервиса |

`NotFoundException` реализует оба PSR-11-интерфейса, обеспечивая совместимость:

```php
try {
    $service = $container->get('unknown');
} catch (NotFoundException $e) {
    // PSR-11 NotFoundExceptionInterface
}
```

---

## Исключения модулей

| Класс | Когда бросается |
| ----- | --------------- |
| `ModuleException` | Базовый класс для всех сбоев модуля |
| `ModuleNotFoundException` | Обязательная зависимость-модуль отсутствует |
| `ModuleLoadException` | Файл модуля не может быть загружен или класс не найден |
| `ModuleManifestException` | `module.json` отсутствует, некорректен или не проходит валидацию |
| `ModuleCycleException` | Граф зависимостей содержит цикл |

---

## Перехват по подсистеме

```php
// Перехватить любое исключение XC_VM
try {
    $loader->loadAll();
} catch (XcVmException $e) {
    logger()->error($e->getMessage());
}

// Перехватить только ошибки модулей
try {
    $loader->loadAll();
} catch (ModuleException $e) {
    // ModuleNotFoundException | ModuleLoadException | ...
}

// Перехватить только ошибки контейнера
try {
    $container->get('missing');
} catch (ContainerException $e) {
    // CircularDependencyException | NotFoundException | ...
}
```

---

## Расположение

```
src/core/Exception/
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
