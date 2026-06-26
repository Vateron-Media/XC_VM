# Exception Hierarchy

All XC_VM exceptions extend `XcVmException` so callers can catch the entire tree with
one `catch` block or target a specific subsystem.

---

## Tree

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

## Container exceptions

| Class | When thrown |
| ----- | ----------- |
| `ContainerException` | Base for all container failures |
| `CircularDependencyException` | A service's factory graph contains a cycle |
| `ServiceCreationException` | Factory callable threw while creating a service |
| `NotFoundException` | `get($id)` called for an unregistered service |

`NotFoundException` implements both PSR-11 interfaces so the container is compliant:

```php
try {
    $service = $container->get('unknown');
} catch (NotFoundException $e) {
    // PSR-11 NotFoundExceptionInterface
}
```

---

## Module exceptions

| Class | When thrown |
| ----- | ----------- |
| `ModuleException` | Base for all module failures |
| `ModuleNotFoundException` | Required dependency module is missing |
| `ModuleLoadException` | Module file cannot be loaded or class not found |
| `ModuleManifestException` | `module.json` is missing, malformed, or fails validation |
| `ModuleCycleException` | Dependency graph has a cycle |

---

## Catching by subsystem

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

## Location

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

## Related files

| File | Role |
| --- | --- |
| `src/Core/Exception/` | Exception base classes and the project hierarchy |
