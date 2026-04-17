# Аутентификация и сессии

Система аутентификации XC_VM обслуживает три типа пользователей: **администраторы**, **реселлеры** и **плееры**. Для каждого типа существует отдельный контекст сессии с изолированными ключами.

---

## Обзор

```
POST /admin/login
        │
        ▼
[BruteforceGuard::checkLogin()]  — проверка rate-limit
        │
        ▼
[Authenticator::login()]
  ├── reCAPTCHA (если включена)
  ├── Проверка учётных данных (UserRepository)
  ├── Проверка access code (2FA)
  ├── Проверка is_admin
  └── Запись сессии + лог входа
        │
        ▼
[SessionManager::start('admin')]
[SessionManager::requireAuth()]  — на каждой защищённой странице
```

---

## Аутентификация (`Authenticator`)

`src/core/Auth/Authenticator.php`

### `Authenticator::login(array $rData, bool $bypassRecaptcha = false): array`

Выполняет полный процесс аутентификации. Возвращает массив со статусом.

**Коды статусов:**

| Статус                  | Описание                                  |
| ----------------------- | ----------------------------------------- |
| `STATUS_SUCCESS`        | Успешный вход, сессия записана            |
| `STATUS_FAILURE`        | Неверные учётные данные                   |
| `STATUS_INVALID_CAPTCHA`| reCAPTCHA не пройдена                     |
| `STATUS_INVALID_CODE`   | Неверный или недопустимый access code     |
| `STATUS_NOT_ADMIN`      | Пользователь не является администратором  |
| `STATUS_DISABLED`       | Аккаунт отключён                          |

**Логика входа:**

1. Если включена reCAPTCHA (`recaptcha_enable`) — проверить `g-recaptcha-response`
2. Найти пользователя по `username` + `password` (UserRepository)
3. Если установлен access code — проверить, что группа пользователя разрешена
4. Проверить `is_admin = true` в правах группы
5. Если `status = 1` (активен) — записать хеш пароля, IP, время входа, создать сессию
6. Записать лог входа в таблицу `login_logs` (если `save_login_logs` включён)

**Сессионные ключи после успешного входа (admin):**

```php
$_SESSION['hash']   = $userId;
$_SESSION['ip']     = $clientIP;
$_SESSION['code']   = $accessCode;
$_SESSION['verify'] = md5($username . '||' . $hashedPassword);
```

### `Authenticator::hashPassword(string $password): string`

Хеширует пароль для хранения и проверки.

---

## Управление сессиями (`SessionManager`)

`src/core/Auth/SessionManager.php`

Унифицированный менеджер сессий для всех типов пользователей.

### Контексты и ключи сессии

| Ключ (логический) | Admin (`$_SESSION`)    | Reseller (`$_SESSION`) | Player (`$_SESSION`) |
| ----------------- | ---------------------- | ---------------------- | -------------------- |
| `auth`            | `hash`                 | `reseller`             | `phash`              |
| `activity`        | `last_activity`        | `rlast_activity`       | —                    |
| `ip`              | `ip`                   | `rip`                  | —                    |
| `code`            | `code`                 | `rcode`                | —                    |
| `verify`          | `verify`               | `rverify`              | `pverify`            |

### Использование

```php
// В начале защищённой страницы администратора:
SessionManager::start('admin');
SessionManager::requireAuth();

// В начале защищённой страницы реселлера:
SessionManager::start('reseller');
SessionManager::requireAuth();
```

### `SessionManager::start(string $context, int $timeout = 60): void`

Инициализирует PHP-сессию и устанавливает контекст. Если сессия уже запущена — повторный `session_start()` не вызывается.

### `SessionManager::requireAuth(): void`

Проверяет наличие и актуальность сессии. При неудаче — перенаправляет на страницу входа. Проверяет:

- Наличие ключа `auth` в сессии
- Соответствие IP (если включена проверка)
- Таймаут неактивности (по умолчанию 60 минут)

### Таймаут

Таймаут по умолчанию — **60 минут** (`DEFAULT_TIMEOUT`). Можно переопределить:

```php
SessionManager::start('admin', 120); // 2 часа
```

---

## Защита от брутфорса (`BruteforceGuard`)

`src/core/Auth/BruteforceGuard.php`

Централизованная защита от перебора паролей и flood-атак.

Работает на основе IP-адреса клиента и счётчиков неудачных попыток. Интегрируется с:

- `NetworkUtils::getUserIP()` — получение IP клиента
- `ServerRepository::getAllowedIPs()` — белый список IP (не ограничиваются)
- `BlocklistService::getBlockedIPs()` — чёрный список IP

При превышении лимита попыток IP блокируется через файл `FLOOD_TMP_PATH/block_{IP}`, который проверяется `RequestGuard` на каждом запросе.

---

## Авторизация страниц (`PageAuthorization`)

После аутентификации доступ к конкретным страницам проверяется через `PageAuthorization`. Подробнее — в документации [Права доступа и RBAC](permissions-and-rbac.md).

---

## Параметры безопасности сессии

При инициализации в `CONTEXT_ADMIN` bootstrap устанавливает:

```php
$params['samesite'] = 'Strict';
session_set_cookie_params($params);
session_start();
```

Это предотвращает CSRF-атаки через атрибут `SameSite=Strict` на cookie сессии.

---

## Журнал входов

Все попытки входа (успешные и неудачные) записываются в таблицу `login_logs` при включённой настройке `save_login_logs`:

| Поле         | Описание                                             |
| ------------ | ---------------------------------------------------- |
| `type`       | Тип входа: `'ADMIN'`, `'RESELLER'`                   |
| `access_code`| ID использованного access code                       |
| `user_id`    | ID пользователя (0 при неверных данных)              |
| `status`     | Статус: `SUCCESS`, `INVALID_LOGIN`, `INVALID_CODE`, `NOT_ADMIN`, `DISABLED` |
| `login_ip`   | IP клиента                                           |
| `date`       | UNIX timestamp                                       |

---

## Связанные файлы

| Файл                                  | Назначение                              |
| ------------------------------------- | --------------------------------------- |
| `src/core/Auth/Authenticator.php`     | Логика входа и хеширования паролей      |
| `src/core/Auth/SessionManager.php`    | Управление сессиями всех контекстов     |
| `src/core/Auth/BruteforceGuard.php`   | Защита от перебора                      |
| `src/core/Auth/Authorization.php`     | Авторизация на уровне объектов          |
| `src/core/Auth/PageAuthorization.php` | Авторизация на уровне страниц           |
| `src/bootstrap.php`                   | Запускает сессию в CONTEXT_ADMIN        |
