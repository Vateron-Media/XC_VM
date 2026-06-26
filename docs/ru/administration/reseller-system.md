# Система реселлеров

Система реселлеров обеспечивает многоуровневое управление партнёрской сетью с выдачей линий на основе кредитов.
Реселлеры создают и управляют IPTV-линиями, устройствами MAG и Enigma2 в рамках выделенных кредитов и прав доступа.

---

## Обзор

```text
Admin
  └── выдаёт кредиты + права группы
        └── Реселлер
              ├── создаёт IPTV-линии (стоит кредитов)
              ├── создаёт устройства MAG (стоит кредитов)
              ├── создаёт устройства Enigma2 (стоит кредитов)
              └── создаёт суб-реселлеров (стоит кредитов)
                    └── суб-реселлер имеет свои линии и кредиты
```

Основная бизнес-логика находится в `src/Domain/User/ResellerAPI.php`. Веб-контроллеры — в `src/Public/Controllers/Reseller/`. REST API — в `src/Public/Controllers/Api/ResellerRestApiController.php`.

---

## Кредитная система

Кредиты — это валюта для всех операций реселлера. У каждого действия есть стоимость, и баланс реселлера должен её покрывать.

### Стоимость в кредитах

| Действие | Источник стоимости |
| --- | --- |
| Создание линии (официальная) | `package.official_credits` |
| Создание линии (триал) | `package.trial_credits` |
| Создание устройства MAG | как и линия |
| Создание устройства Enigma2 | как и линия |
| Создание суб-реселлера | `permissions.create_sub_resellers_price` |

### Переопределение цен

Реселлеры могут иметь индивидуальные цены по пакетам через JSON `override_packages` в записи пользователя:

```php
$rOverride = json_decode($rUserInfo['override_packages'], true);
if (isset($rOverride[$rPackage['id']]['official_credits'])) {
    $rCost = intval($rOverride[$rPackage['id']]['official_credits']);
}
```

### Перевод кредитов

Реселлеры могут переводить кредиты своим прямым подчинённым через API-действие `adjust_credits`. Оба баланса должны оставаться >= 0.

### Логирование

Все операции с кредитами фиксируются в `users_logs`:

| Поле | Описание |
| --- | --- |
| `owner` | ID пользователя-реселлера |
| `type` | `line`, `mag`, `enigma`, `user` |
| `action` | `new`, `extend`, `edit`, `adjust_credits` |
| `cost` | потрачено кредитов |
| `credits_after` | баланс после операции |
| `package_id` | использованный пакет |
| `date` | временная метка |

---

## Управление линиями

Линии — это IPTV-подписки пользователей. Типы:

| Тип | Флаги |
| --- | --- |
| Стандартная IPTV-линия | `is_mag=0, is_e2=0` |
| Устройство MAG | `is_mag=1` |
| Устройство Enigma2 | `is_e2=1` |

### Процесс создания

1. Проверка доступности пакета (должен быть в правах группы реселлера).
2. Проверка `credits >= cost`.
3. Генерация username/password, если разрешено правами.
4. Применение пакета: `exp_date`, `max_connections`, `bouquets`, `allowed_outputs`.
5. Установка ограничений: `allowed_ips` (JSON), `allowed_ua`, `bypass_ua`, `is_isplock`.
6. Вставка в таблицу `lines` через `REPLACE INTO`.
7. Синхронизация записей устройств (`mag_devices` или `enigma2_devices`).
8. Рассылка сигнала на стриминговые серверы.
9. Списание кредитов и запись транзакции.

### Назначение букетов

Каждый пакет указывает доступные букеты через JSON-массив `bouquets`.
Если включено право `allow_change_bouquets`, реселлер может выбрать подмножество букетов пакета. Иначе все букеты пакета назначаются автоматически.

---

## Управление устройствами

### Устройства MAG

Управляются через `MagService` (`src/Domain/Device/MagService.php`).
Поля привязки: `ver`, `device_id2`, `device_id`, `hw_version`, `image_version`, `stb_type`, `sn`.

### Устройства Enigma2

Управляются через `EnigmaService` (`src/Domain/Device/EnigmaService.php`).
Поля привязки: `token`, `lversion`, `cpu`, `enigma_version`, `modem_mac`, `local_ip`.

Оба типа устройств поддерживают `lock_device` (привязка к железу), `is_isplock` (привязка к ISP) и `forced_country`.

---

## Иерархия суб-реселлеров

Реселлеры могут создавать суб-реселлеров (если предоставлено право `create_sub_resellers`):

- Суб-реселлеры связаны через поле `owner_id`.
- Многоуровневость: суб-реселлер может создавать собственных суб-реселлеров.
- Каждое создание стоит `create_sub_resellers_price` кредитов.
- Назначаемый `member_group_id` должен быть в массиве разрешений `subresellers` родителя.

Запросы владения:

```php
Authorization::check('user', $rID)   // проверяет иерархию реселлера
Authorization::check('line', $rID)   // проверяет, владеют ли подчинённые реселлера линией
```

Методы:

```php
UserRepository::getResellers($rOwner, $rIncludeSelf)
UserRepository::getDirectReports()
AuthRepository::getGroupPermissions()  // рекурсивно строит all_reports
```

---

## Права доступа

Права берутся из таблицы `users_groups`, загружаются через `AuthRepository::getPermissions()`.

### Ключевые поля прав

| Право | Тип | Описание |
| --- | --- | --- |
| `is_reseller` | `bool` | пользователь является реселлером |
| `create_line` | `bool` | может создавать IPTV-линии |
| `create_mag` | `bool` | может создавать устройства MAG |
| `create_enigma` | `bool` | может создавать устройства Enigma2 |
| `create_sub_resellers` | `bool` | может создавать суб-реселлеров |
| `create_sub_resellers_price` | `int` | стоимость в кредитах за суб-реселлера |
| `allow_change_bouquets` | `bool` | может выбирать подмножество букетов |
| `allow_change_username` | `bool` | может задавать собственное имя пользователя |
| `allow_change_password` | `bool` | может задавать собственный пароль |
| `allow_restrictions` | `bool` | может задавать ограничения IP/UA |
| `can_view_vod` | `bool` | может просматривать VOD-контент |
| `reseller_client_connection_logs` | `bool` | может просматривать логи подключений |
| `minimum_username_length` | `int` | минимальная длина имени пользователя |
| `minimum_password_length` | `int` | минимальная длина пароля |

### Проверки на уровне страниц

`PageAuthorization::checkResellerPermissions()` сопоставляет страницы с правами:

| Страницы | Требуемое право |
| --- | --- |
| `user`, `users` | `create_sub_resellers` |
| `line`, `lines` | `create_line` |
| `mag`, `mags` | `create_mag` |
| `enigma`, `enigmas` | `create_enigma` |
| `epg_view`, `streams`, `movies` | `can_view_vod` |
| `live_connections`, `line_activity` | `reseller_client_connection_logs` |

### Границы

Что реселлеры **не могут** делать:

- Доступ к линиям/пользователям вне своей иерархии.
- Создавать или изменять пакеты.
- Доступ к настройкам только-для-администратора.
- Превышать свой баланс кредитов.
- Обходить ограничения групп пакетов.

---

## REST API

Файл: `src/Public/Controllers/Api/ResellerRestApiController.php`

Аутентификация через API-ключ. Действия:

| Действие | Описание |
| --- | --- |
| `user_info` | информация об аккаунте реселлера |
| `packages` | доступные пакеты |
| `get_lines` / `get_mags` / `get_enigmas` | список ресурсов |
| `create_line` / `edit_line` / `delete_line` | CRUD линий |
| `enable_line` / `disable_line` | переключение статуса линии |
| `create_mag` / `edit_mag` / `delete_mag` | CRUD MAG |
| `create_enigma` / `edit_enigma` / `delete_enigma` | CRUD Enigma |
| `convert_mag` / `convert_enigma` | конвертация типа устройства |
| `get_users` / `get_user` | список/просмотр суб-реселлеров |
| `create_user` / `edit_user` / `delete_user` | CRUD суб-реселлеров |
| `enable_user` / `disable_user` | переключение статуса суб-реселлера |
| `adjust_credits` | перевод кредитов суб-реселлеру |
| `activity_logs` / `live_connections` | данные подключений |
| `user_logs` | логи активности суб-реселлеров |

Класс `ResellerAPIWrapper` проверяет API-ключ, инициализирует сессию через `ResellerAPI` и возвращает отфильтрованные JSON-ответы.

---

## Сессия и bootstrap

### Сессия

Файл: `src/Infrastructure/Bootstrap/reseller_session.php`

- Таймаут 60 минут с отслеживанием последней активности.
- Детекция смены IP (если включена настройка `ip_logout`).
- Ключи сессии: `reseller` (ID пользователя), `rip`, `rcode`, `rverify`, `rlast_activity`.

### Bootstrap функций

Файл: `src/Infrastructure/Bootstrap/reseller_functions.php`

- Загружает базу данных и утилиты.
- Инициализирует `$rUserInfo` и `$rPermissions`.
- Проверяет целостность сессии (проверка хеша username/password).
- Устанавливает временную зону и языковые предпочтения.

---

## Маршруты

Файл: `src/Public/routes/reseller.php`

Ключевые маршруты:

```text
GET  /dashboard              → ResellerDashboardController
GET  /edit_profile            → ResellerEditProfileController
POST /post                    → ResellerPostController (обработчик форм)
GET  /api, POST /api          → ResellerApiController
GET  /table, POST /table      → ResellerTableController

GET  /lines                   → ResellerLinesController
GET  /line                    → ResellerLineController
GET  /mags                    → ResellerMagsController
GET  /mag                     → ResellerMagController
GET  /enigmas                 → ResellerEnigmasController
GET  /enigma                  → ResellerEnigmaController

GET  /users                   → ResellerUsersController
GET  /user                    → ResellerUserController
GET  /user_logs               → ResellerUserLogsController
GET  /live_connections        → ResellerLiveConnectionsController
GET  /line_activity           → ResellerLineActivityController
GET  /tickets                 → ResellerTicketsController
```

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/Domain/User/ResellerAPI.php` | основная бизнес-логика |
| `src/Public/Controllers/Api/ResellerRestApiController.php` | REST API |
| `src/Public/Controllers/Reseller/*.php` | веб-контроллеры |
| `src/Public/routes/reseller.php` | URL-маршрутизация |
| `src/Public/Views/reseller/*.php` | шаблоны представлений |
| `src/Infrastructure/ResellerApiDispatcher.php` | маршрутизация AJAX-действий |
| `src/Infrastructure/ResellerTableRenderer.php` | рендеринг DataTables |
| `src/Infrastructure/Bootstrap/reseller_session.php` | управление сессией |
| `src/Infrastructure/Bootstrap/reseller_functions.php` | инициализация |
| `src/Core/Auth/Authorization.php` | проверки владения |
| `src/Core/Auth/PageAuthorization.php` | контроль доступа на уровне страниц |
