# Автозагрузчик — Регистрация классов

XC_VM использует собственный автозагрузчик (`src/autoload.php`) вместо Composer.
Классы обнаруживаются **автоматически** — ручная регистрация не нужна.

---

## Как это работает

```
Запрос → подключается autoload.php
              │
              ├── Файл кэша существует?
              │       ДА  → загрузить igbinary кэш → O(1) поиск
              │       НЕТ → warmCache(): сканировать все директории через token_get_all()
              │                          → построить карту ClassName → filePath
              │                          → сохранить в tmp/cache/autoload_map
              │
              └── PHP запрашивает класс
                      │
                      ├── 1. Явная карта (addClass())
                      ├── 2. Кэш (файловый или из предыдущего поиска)
                      └── 3. Живой поиск по директориям (fallback, кэшируется)
```

## Добавление нового класса

**Просто создайте файл.** Это всё.

Поместите PHP-файл в любую зарегистрированную директорию (или её поддиректорию):

| Директория | Назначение |
|-----------|---------|
| `src/core/` | Ядро фреймворка (Database, Cache, Auth, Http, Process и т.д.) |
| `src/domain/` | Бизнес-логика (Services, Repositories) |
| `src/infrastructure/` | Внешние адаптеры (DatabaseFactory, CacheReader, Redis) |
| `src/streaming/` | Стриминг-подсистема (Auth, Delivery, Codec, Health) |
| `src/modules/` | Опциональные модули (Plex, Watch, TMDB, Ministra и т.д.) |
| `src/public/` | Контроллеры и Views |
| `src/includes/` | Legacy-код |
| `src/includes/libs/` | Сторонние библиотеки |

### Пример

```php
// src/domain/Billing/InvoiceService.php
class InvoiceService {
    public static function generate($userId) { ... }
}
```

После создания файла **удалите кэш**, чтобы автозагрузчик обнаружил новые классы:

```bash
rm -f /home/xc_vm/tmp/cache/autoload_map
```

При следующем запросе `warmCache()` запустится автоматически, найдёт `InvoiceService` и закэширует.

## Инвалидация кэша

Файл кэша `tmp/cache/autoload_map` — бинарный файл (формат igbinary).
Его нужно удалять когда:

- Добавлен новый файл с классом
- Файл с классом переименован или перемещён
- Файл с классом удалён

```bash
# Удалить вручную
rm -f /home/xc_vm/tmp/cache/autoload_map

# Или через PHP
XC_Autoloader::clearCache();
```

> **Примечание:** Если запрошен класс, которого нет в кэше, автозагрузчик делает живой поиск по директориям и кэширует результат. Поэтому кэш нужно сбрасывать только при перемещении/переименовании/удалении — новые классы будут найдены через fallback.

## Ручная регистрация (редко)

Для особых случаев когда файл содержит несколько классов или имя файла не совпадает с именем класса:

```php
XC_Autoloader::addClass('DropboxClient', '/home/xc_vm/includes/libs/Dropbox.php');
XC_Autoloader::addClass('DropboxException', '/home/xc_vm/includes/libs/Dropbox.php');
```

Это нужно в основном для legacy-библиотек с несколькими классами в одном файле (например, `iptables.php`, `m3u.php`).

## Добавление новой директории

Отредактируйте `registerDirectories()` в `src/autoload.php`:

```php
private static function registerDirectories() {
    $base = self::$basePath;

    self::addDirectory($base . 'includes');
    self::addDirectory($base . 'includes/libs');
    self::addDirectory($base . 'core');
    self::addDirectory($base . 'domain');
    self::addDirectory($base . 'infrastructure');
    self::addDirectory($base . 'streaming');
    self::addDirectory($base . 'modules');
    self::addDirectory($base . 'public');

    // Добавьте новую директорию:
    self::addDirectory($base . 'my_new_dir');
}
```

Затем удалите кэш.

## Правила именования

| Правило | Пример |
|---------|--------|
| Имя файла **должно** совпадать с именем класса | `InvoiceService.php` → `class InvoiceService` |
| Один класс на файл (рекомендуется) | Файлы с несколькими классами требуют `addClass()` |
| PascalCase | `StreamService`, `DatabaseHandler` |
| Без namespace | `class StreamService { }` — без ключевого слова `namespace` |
| Без `declare(strict_types=1)` | Конвенция проекта |

## Дублирование имён классов

Если два файла определяют одинаковое имя класса, **побеждает первый найденный** по порядку сканирования директорий. Это хрупко — избегайте дубликатов. Используйте префиксы:

```
✗  public/Controllers/Admin/PlexController.php    ← конфликт
✗  modules/plex/PlexController.php                ← конфликт

✓  public/Controllers/Admin/AdminPlexController.php   ← уникально
✓  modules/plex/PlexController.php                    ← уникально
```

## Отладка

```php
// Все зарегистрированные директории
print_r(XC_Autoloader::getDirectories());

// Явная карта классов
print_r(XC_Autoloader::getClassMap());

// Принудительный полный пересканирование
XC_Autoloader::clearCache();
XC_Autoloader::warmCache();
```
