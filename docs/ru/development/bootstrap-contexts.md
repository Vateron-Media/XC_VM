# <a id="bootstrap-contexts"></a>Контексты начальной загрузки

`XC_Bootstrap` - это единая точка входа для инициализации системы.
Каждый контекст загружает только те подсистемы, которые необходимы для его пути выполнения.
Контекст выражается в виде значения перечисления `BootContext`.

> `boot()` принимает `string|BootContext`, поэтому устаревшая строка `XC_Bootstrap::CONTEXT_*`
> константы (`CONTEXT_ADMIN`, `CONTEXT_CLI`, ...) все еще работают, но являются псевдонимами **`@deprecated`** —
> новый код должен передавать регистр перечисления (`BootContext::Admin`). Регистры перечисления равны **ПаскалЬкас**
> (`Minimal`, `Cli`, `Stream`, `Admin`), не в верхнем регистре.

---

## <a id="quick-reference"></a>Краткий справочник

|Случай перечисления|Типичное использование|
| --- | --- |
| `BootContext::Minimal` |Скрипты, которым нужны только пути /конфигурация|
| `BootContext::Cli` |Задания Cron и команды CLI|
| `BootContext::Stream` |Конечные точки потоковой передачи (`live`, `vod`, `timeshift`)|
| `BootContext::Admin` |Панель администратора/реселлера|

---

## <a id="context-details"></a>Детали контекста

### <a id="bootcontextminimal"></a>BootContext::Минимальный

Загружает константы, пути, конфигурацию, логгер и обработчики ошибок.
Нет подключения к базе данных.

Включает в себя:

- Автозагрузчик Composer PSR-4 (`vendor/autoload.php`)
- константы пути (`MAIN_HOME`, `INCLUDES_PATH`, ...)
- регистратор (`Logger::init()`)
- помощники по устранению ошибок (`generateError()`, `generate404()`)

Не включает: DB, Redis, сеансы, транслятор, API администратора.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::Minimal);
```

---

### <a id="bootcontextcli"></a>Загрузочный текст::Cli

Используется для задач cron и CLI.
Добавляет инициализацию базы данных и устаревшего ядра поверх `MINIMAL`.

Включает в себя:

- Подключение к базе данных
- `LegacyInitializer`
- необязательный повтор (`'redis' => true`)
- необязательный заголовок процесса (`'process' => '...'`)

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::Cli, [
    'cached' => true,
    'process' => 'xc_vm: my-job',
]);
```

---

### <a id="bootcontextstream"></a>BootContext::Поток

Облегченный контекст для конечных точек потоковой передачи с высокой нагрузкой.

Включает в себя:

- Подключение к базе данных (`cached=true`)
- защита от наводнений и проверка хостинга

Не включает: Redis, translator, API-интерфейсы администратора, сеансы.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::Stream, ['cached' => true]);
```

---

### <a id="bootcontextadmin"></a>BootContext::Администратор

Полная инициализация панели администратора/реселлера.

Включает в себя:

- защищенный сеанс (`SameSite=Strict`)
- Подключение к базе данных (`cached=false`)
- `LegacyInitializer`
- Переделать
- API-интерфейсы администратора/реселлера
- переводчик
- обработчик завершения работы
- постоянные состояния и глобальные параметры администратора

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::Admin);
```

---

## <a id="subsystem-matrix"></a>Матрица подсистемы

|Подсистема|Минимальный|Кли|Течение|Администратор|
| --- | :---: | :---: | :---: | :---: |
|Константы/пути|✅|✅|✅|✅|
|Лесоруб|✅|✅|✅|✅|
|Защита от наводнений|—|—|✅|✅|
|Проверка хостинга|—|—|✅|✅|
|База данных|—|✅|✅|✅|
|Инициализатор наследия|—|✅|—|✅|
|Переделать|—|выбирать|—|✅|
|Сессия|—|—|—|✅|
|API администратора|—|—|—|✅|
|Переводчик|—|—|—|✅|

---

## <a id="boot-options"></a>`boot()` параметры

```php
XC_Bootstrap::boot(BootContext $context, array $options = []);
```

|Вариант|Тип|По умолчанию|Описание|
| --- | --- | --- | --- |
| `cached` | `bool` |`true` для потока, `false` в противном случае|Использовать кэшированные настройки|
| `redis` | `bool` |`true` для администратора, `false` в противном случае|Подключите Redis|
| `process` | `string` | `''` |Название процесса для CLI|
| `shutdown` | `callable` |встроенный|Переопределить обратный вызов завершения работы|

---

## <a id="idempotency"></a>Идемпотентность

`boot()` выполняется один раз для каждого процесса. Повторные вызовы игнорируются.

```php
XC_Bootstrap::boot(BootContext::Admin);
XC_Bootstrap::boot(BootContext::Cli); // ignored
```

Для проведения тестов:

```php
XC_Bootstrap::reset();
```

---

## <a id="public-methods"></a>Общедоступные методы

```php
XC_Bootstrap::getContext(): ?string          // active context's string value, e.g. 'admin' (null before boot)
XC_Bootstrap::isBooted(): bool
XC_Bootstrap::isCli(): bool                   // true when the active context is Cli
XC_Bootstrap::isDevMode(): bool               // DEV_MODE flag (see Feature Flags)
XC_Bootstrap::getDatabase(): ?Database
XC_Bootstrap::getContainer(): ServiceContainer
```

## <a id="related-files"></a>Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/bootstrap.php` |Определяет MAIN_HOME, требует автозагрузки Composer, загружает контекст|
| `src/Core/Enum/BootContext.php` |Перечисление контекста загрузки|
| `src/Core/Init/LegacyInitializer.php` |Унаследованная инициализация для каждого контекста|
