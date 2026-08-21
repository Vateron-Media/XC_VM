# Контексты начальной загрузки

`XC_Bootstrap` - это единая точка входа для инициализации системы.
Каждый контекст загружает только те подсистемы, которые необходимы для его пути выполнения.
Контекст выражается в виде значения перечисления `BootContext`.

---

## Краткий справочник

|Случай перечисления|Типичное использование|
| --- | --- |
| `BootContext::MINIMAL` |Скрипты, которым нужны только пути /конфигурация|
| `BootContext::CLI` |Задания Cron и команды CLI|
| `BootContext::STREAM` |Конечные точки потоковой передачи (`live`, `vod`, `timeshift`)|
| `BootContext::ADMIN` |Панель администратора/реселлера|

---

## Детали контекста

### BootContext::МИНИМАЛЬНЫЙ

Загружает константы, пути, конфигурацию, логгер и обработчики ошибок.
Нет подключения к базе данных.

Включает в себя:

- Composer PSR-4 автозагрузчик (`vendor/autoload.php`)
- константы пути (`MAIN_HOME`, `INCLUDES_PATH`, ...)
- регистратор (`Logger::init()`)
- помощники по устранению ошибок (`generateError()`, `generate404()`)

Не включает: базу данных, Redis, сеансы, транслятор, API-интерфейсы администратора.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::MINIMAL);
```

---

### Загрузочный текст::CLI

Используется для задач cron и CLI.
Добавляет инициализацию базы данных и устаревшего ядра поверх `MINIMAL`.

Включает в себя:

- Подключение к базе данных
- `LegacyInitializer`
- необязательно Redis (`'redis' => true`)
- необязательный заголовок процесса (`'process' => '...'`)

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::CLI, [
    'cached' => true,
    'process' => 'xc_vm: my-job',
]);
```

---

### BootContext::ПОТОК

Облегченный контекст для конечных точек потоковой передачи с высокой нагрузкой.

Включает в себя:

- Подключение к базе данных (`cached=true`)
- защита от наводнений и проверка хостинга

Исключает: Redis, переводчик, API-интерфейсы администратора, сеансы.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::STREAM, ['cached' => true]);
```

---

### BootContext::АДМИНИСТРАТОР

Полная инициализация панели администратора/реселлера.

Включает в себя:

- защищенный сеанс (`SameSite=Strict`)
- Подключение к базе данных (`cached=false`)
- `LegacyInitializer`
- Redis
- API-интерфейсы администратора/реселлера
- переводчик
- обработчик завершения работы
- постоянные состояния и глобальные параметры администратора

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::ADMIN);
```

---

## Матрица подсистемы

|Подсистема|минимальный|КЛИ|течение|администратор|
| --- | :---: | :---: | :---: | :---: |
|Константы/пути|✅|✅|✅|✅|
|Лесоруб|✅|✅|✅|✅|
|Защита от наводнений|—|—|✅|✅|
|Проверка хостинга|—|—|✅|✅|
|База данных|—|✅|✅|✅|
|Инициализатор наследия|—|✅|—|✅|
| Redis |—|выбирать|—|✅|
|Сессия|—|—|—|✅|
|API администратора|—|—|—|✅|
|Переводчик|—|—|—|✅|

---

## `boot()` параметры

```php
XC_Bootstrap::boot(BootContext $context, array $options = []);
```

|Вариант|Тип|По умолчанию|Описание|
| --- | --- | --- | --- |
| `cached` | `bool` |`true` для ПОТОКА, `false` в противном случае|Использовать кэшированные настройки|
| `redis` | `bool` |`true` для АДМИНИСТРАТОРА, `false` в противном случае|Подключить Redis|
| `process` | `string` | `''` |Название процесса для CLI|
| `shutdown` | `callable` |встроенный|Переопределить обратный вызов завершения работы|

---

## Идемпотентность

`boot()` выполняется один раз для каждого процесса. Повторные вызовы игнорируются.

```php
XC_Bootstrap::boot(BootContext::ADMIN);
XC_Bootstrap::boot(BootContext::CLI); // ignored
```

Для проведения тестов:

```php
XC_Bootstrap::reset();
```

---

## Общедоступные методы

```php
XC_Bootstrap::getContext(): ?BootContext
XC_Bootstrap::isBooted(): bool
XC_Bootstrap::isCli(): bool
XC_Bootstrap::getDatabase(): ?Database
XC_Bootstrap::getContainer(): ServiceContainer
```

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/bootstrap.php` |Определяет MAIN_HOME, требует автозагрузки Composer, загружает контекст|
| `src/Core/Enum/BootContext.php` |Перечисление контекста загрузки|
| `src/Core/Init/LegacyInitializer.php` |Унаследованная инициализация для каждого контекста|
