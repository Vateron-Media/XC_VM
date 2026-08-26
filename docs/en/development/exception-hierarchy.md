# Exception Hierarchy

XC_VM's framework exceptions extend `XcVmException` — an empty **marker** base
(`class XcVmException extends \RuntimeException {}`, it adds no extra data) — so callers can
catch the whole family with one `catch (XcVmException)` or target a specific subsystem.

> **Scope.** This typed hierarchy covers the **DI container** and **module system** only.
> It is not the whole panel: streaming/auth endpoints report failures through
> `generateError()` (not exceptions), and much domain/CLI code throws plain
> `\RuntimeException` or SPL exceptions — those still match `catch (XcVmException)` only when
> the class actually extends it.

---

## Tree

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

> \* `NotFoundException` extends `ContainerException` (so it belongs in this tree), but it
> physically lives at `src/Core/Container/Psr/NotFoundException.php` under the namespace
> `XcVm\Core\Container\Psr` — **not** in `Core/Exception/Container/`.

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
| `ModuleCycleException` | Dependency graph has a cycle — thrown by `ModuleLoader`'s topological sort with the cycle path (`a -> b -> a`) in the message. (Some `@throws` docblocks say `\RuntimeException`; that's just the base type — `ModuleCycleException` extends it via `XcVmException`.) |

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

## Adding or choosing an exception

- **Which to throw:** use the most specific existing type (e.g. `ModuleManifestException`
  for a bad `module.json`). If nothing fits and it's a framework-level failure, throw
  `XcVmException` (or a new subclass) so it stays catchable as one family. Domain/business
  errors that aren't framework concerns may throw a plain `\RuntimeException` /
  `\InvalidArgumentException`.
- **Adding a category:** create the class under `src/Core/Exception/<Subsystem>/`, extend the
  subsystem base (`ContainerException` / `ModuleException`) — or `XcVmException` for a new
  subsystem — and add it to the tree above. No registration is needed; it's plain PHP.

---

## Location

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

## Related files

| File | Role |
| --- | --- |
| `src/Core/Exception/` | Exception base classes and the project hierarchy |
