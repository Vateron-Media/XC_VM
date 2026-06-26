# Аутентификация и сессии

XC_VM поддерживает три контекста аутентификации — admin, reseller и player — каждый со своими изолированными ключами сессии, отдельными процессами входа и независимой логикой валидации. Этот документ охватывает полный жизненный цикл аутентификации — от входа до валидации сессии и применения политик безопасности.

---

## Обзор процесса входа

Все три контекста следуют похожему высокоуровневому паттерну с контекстно-зависимыми отличиями в валидации и хранении сессии.

### Процесс Admin / Reseller

```text
POST-запрос с учётными данными
  -> Проверки BruteforceGuard (flood / brute-force)
  -> Опциональная проверка reCAPTCHA
  -> Поиск учётных данных через UserRepository::getAuthUserByCredentials()
  -> Валидация access code / группы
  -> Проверка прав (is_admin или is_reseller)
  -> Проверка статуса пользователя (включён/отключён)
  -> Повторное хеширование пароля + запись сессии + лог входа
```

### Процесс Player

```text
POST-запрос с учётными данными
  -> Поиск через UserRepository::getUserInfo()
  -> Проверки типа линии (отклоняются E2, MAG, Stalker)
  -> Проверка даты истечения
  -> Проверки статусов admin_enabled / enabled
  -> Проверки IP allowlist, ограничений по стране, user agent, ISP
  -> Запись сессии + редирект
  -> BruteforceGuard::checkFlood() при любой ошибке
```

---

## Authenticator

Файл: `src/Core/Auth/Authenticator.php`

### `Authenticator::login(array $data, bool $bypassRecaptcha = false): array`

Метод входа администратора. Шаги по порядку:

1. Валидация reCAPTCHA (если включена настройка `recaptcha_enable` и проверка не обходится).
2. Поиск учётных данных через `UserRepository::getAuthUserByCredentials()`.
3. Проверка группы по access code — `member_group_id` пользователя должен быть в списке разрешённых групп текущего access code, либо access code не должен существовать.
4. Проверка прав — для группы пользователя должно быть `is_admin = true`.
5. Проверка статуса — `$rUserInfo['status'] == 1` (включён).
6. При успехе: повторное хеширование пароля, обновление `last_login` и `ip` в базе данных, запись ключей сессии, логирование входа.

Значения сессии, записываемые при успехе:

```php
$_SESSION['hash']   = $rUserInfo['id'];       // ID пользователя
$_SESSION['ip']     = $rIP;                   // IP клиента на момент входа
$_SESSION['code']   = AuthRepository::getCurrentCode(); // Текущий access code
$_SESSION['verify'] = md5($rUserInfo['username'] . '||' . $rCrypt); // Хеш верификации
```

### `Authenticator::resellerLogin(array $data): array`

Метод входа реселлера. Идентичная структура с `login()`, со следующими отличиями:

- reCAPTCHA проверяется всегда, когда включена (без параметра обхода).
- Проверка прав требует `is_reseller` вместо `is_admin`.
- Возвращает `STATUS_NOT_RESELLER`, если у пользователя нет прав реселлера.
- Логи входа записываются с типом `RESELLER` вместо `ADMIN`.

Значения сессии, записываемые при успехе:

```php
$_SESSION['reseller'] = $rUserInfo['id'];       // ID пользователя
$_SESSION['rip']      = $rIP;                   // IP клиента на момент входа
$_SESSION['rcode']    = AuthRepository::getCurrentCode(); // Текущий access code
$_SESSION['rverify']  = md5($rUserInfo['username'] . '||' . $rCrypt); // Хеш верификации
```

### Константы статусов входа

Определяются в `src/bootstrap.php` через `XC_Bootstrap::defineStatusConstants()`:

| Константа | Значение | Смысл |
| --- | --- | --- |
| `STATUS_FAILURE` | 0 | Общая ошибка (неверные учётные данные или универсальный сбой) |
| `STATUS_SUCCESS` | 1 | Вход успешен |
| `STATUS_DISABLED` | 5 | Аккаунт отключён |
| `STATUS_NOT_ADMIN` | 6 | У пользователя нет прав администратора |
| `STATUS_INVALID_CAPTCHA` | 12 | reCAPTCHA не пройдена |
| `STATUS_INVALID_CODE` | 13 | Несовпадение access code / группы |
| `STATUS_NOT_RESELLER` | 35 | У пользователя нет прав реселлера |

### Хеширование пароля

```php
Authenticator::hashPassword(string $password, ?string $salt = null, int $rounds = 20000): string
```

Использует `crypt()` с SHA-512 (`$6$`). Формат соли: `$6$rounds=20000$<salt>$`, где `<salt>` — 16 hex-символов, полученных из `openssl_random_pseudo_bytes(16)`. Пароли повторно хешируются при каждом успешном входе, что меняет соль.

```php
Authenticator::checkPassword(string $password, string $storedHash): string
```

Проверяет открытый пароль против сохранённого хеша через `crypt($password, $storedHash)` с timing-safe сравнением через `hash_equals()`. Сохранённый хеш содержит алгоритм, число раундов и соль, поэтому `crypt()` воспроизводит правильный хеш для сравнения.

---

## Аутентификация плеера

Файл: `src/Public/Controllers/Player/PlayerLoginController.php`

Процесс входа плеера принципиально отличается от admin/reseller. Он аутентифицирует «линии» конечных пользователей (IPTV-подписки), а не операторов панели.

### Процесс входа

`PlayerLoginController::processLogin()` выполняет следующие проверки по порядку:

1. **Поиск учётных данных** — `UserRepository::getUserInfo()` (отличается от `getAuthUserByCredentials`, используемого admin/reseller).
2. **Отклонение по типу линии** — линии E2, MAG и Stalker отклоняются с конкретными кодами ошибок.
3. **Проверка истечения** — `exp_date` должна быть null или в будущем.
4. **Проверка admin_enabled** — `admin_enabled == 0` возвращает `CLIENT_BANNED`.
5. **Проверка enabled** — `enabled == 0` возвращает `CLIENT_DISABLED`.
6. **IP allowlist** — если у пользователя задан `allowed_ips`, IP клиента должен совпадать (определяется через `gethostbyname`).
7. **Ограничение по стране** — два режима:
   - Per-user: если задан `forced_country` и не равен `ALL`, страна GeoIP должна совпадать.
   - Глобальный: если per-user переопределение отсутствует, проверяется глобальная настройка `allow_countries` (если не содержит `ALL`).
8. **Проверка user agent** — если у пользователя задан `allowed_ua`, HTTP user agent должен совпадать.
9. **Проверка ISP** — флаг `isp_violate` отклоняет подключение.
10. **Проверка ISP-сервера** — если `isp_is_server` равно true и пользователь не рестример, подключение отклоняется.

Каждый сбой запускает `BruteforceGuard::checkFlood()` перед возвратом кода ошибки.

### Коды ошибок плеера

| Константа | Значение | Смысл |
| --- | --- | --- |
| `CLIENT_INVALID` | 0 | Неверный username или password |
| `CLIENT_IS_E2` | 1 | Линии Enigma не разрешены |
| `CLIENT_IS_MAG` | 2 | Линии MAG не разрешены |
| `CLIENT_IS_STALKER` | 3 | Линии Stalker не разрешены |
| `CLIENT_EXPIRED` | 4 | Срок линии истёк |
| `CLIENT_BANNED` | 5 | Линия забанена (admin_enabled = 0) |
| `CLIENT_DISABLED` | 6 | Линия отключена (enabled = 0) |
| `CLIENT_DISALLOWED` | 7 | Не пройдена проверка IP/страны/UA/ISP |

### Ключи сессии плеера

```php
$_SESSION['phash']   = $rUserInfo['id'];
$_SESSION['pverify'] = md5($rUserInfo['username'] . '||' . $rUserInfo['password']);
```

Контекст плеера хранит только два ключа сессии. В отличие от admin и reseller, нет ключей `activity`, `ip` или `code`. Это значит, что сессия плеера не имеет принудительного таймаута по неактивности и детекции смены IP на уровне сессии.

---

## Валидация сессии при загрузке страницы

После начального входа каждая загрузка авторизованной страницы повторно проверяет сессию. Это происходит в bootstrap-файлах, а не в `SessionManager`.

### Валидация admin-сессии

Файл: `src/Public/Views/admin/functions.php`

Когда `$_SESSION['hash']` установлен, при каждой загрузке страницы выполняются следующие проверки:

1. **Поиск пользователя** — `UserRepository::getRegisteredUserById($_SESSION['hash'])`. Если пользователь больше не существует, сессия завершается.
2. **Проверка прав** — `AuthRepository::getPermissions()` должен вернуть валидный набор с `is_admin == true`.
3. **Проверка IP** — сравнивает текущий IP с `$_SESSION['ip']`:
   - Если включена настройка `ip_subnet_match`: сравнивает только первые три октета (например, `192.168.1.*` совпадает с `192.168.1.*`).
   - Если `ip_subnet_match` отключена: требует точного совпадения IP.
   - Если IP не совпадает и включена настройка `ip_logout`: сессия завершается.
   - Если IP не совпадает и `ip_logout` отключена: `$_SESSION['ip']` молча обновляется на новый IP.
4. **Проверка verify-хеша** — `$_SESSION['verify']` должен быть равен `md5($rUserInfo['username'] . '||' . $rUserInfo['password'])`. Это обеспечивает инвалидацию сессии при смене пароля.

Если любая проверка проваливается, сессия очищается через `SessionManager::clearContext('admin')`, и пользователь перенаправляется на главную страницу.

### Валидация reseller-сессии

Файл: `src/Infrastructure/Bootstrap/reseller_functions.php`

Идентичная логика валидации admin, но использует ключи сессии реселлера:

- Проверяет `$_SESSION['reseller']` для ID пользователя.
- Использует `$_SESSION['rip']` для сравнения IP.
- Использует `$_SESSION['rverify']` для verify-хеша.
- Валидирует право `is_reseller` вместо `is_admin`.

Поведение IP subnet matching и IP logout — то же, что для admin.

### Таймаут admin-сессии

Файл: `src/Public/Views/admin/session.php`

Отдельная проверка таймаута сессии работает для admin-сессий. Если оба `$_SESSION['hash']` и `$_SESSION['last_activity']` установлены и с момента `last_activity` прошло более 60 минут, ключи сессии (`hash`, `ip`, `code`, `verify`, `last_activity`) сбрасываются. При каждом валидном запросе `$_SESSION['last_activity']` обновляется, а сессия закрывается для записи.

### Валидация сессии плеера

Контекст плеера не выполняет проверку IP, subnet matching или таймаут по активности на уровне сессии. Сохраняются только `phash` и `pverify`, и валидация полагается на прикладной слой для повторной проверки этих значений по базе данных.

---

## SessionManager

Файл: `src/Core/Auth/SessionManager.php`

Унифицированное API сессий, абстрагирующее различные имена ключей сессий в разных контекстах. Предполагается как замена legacy-файлов `admin/session.php` и `reseller/session.php`.

### Карта ключей по контекстам

| Логический ключ | Admin `$_SESSION` | Reseller `$_SESSION` | Player `$_SESSION` |
| --- | --- | --- | --- |
| `auth` | `hash` | `reseller` | `phash` |
| `activity` | `last_activity` | `rlast_activity` | — |
| `ip` | `ip` | `rip` | — |
| `code` | `code` | `rcode` | — |
| `verify` | `verify` | `rverify` | `pverify` |

Контекст плеера намеренно опускает сопоставления `activity`, `ip` и `code`.

### Методы

**`start(string $context, int $timeout = 60): void`**

Стартует PHP-сессию (если ещё не стартована), устанавливает активный контекст и запускает `checkTimeout()` для прекращения устаревших сессий. Контекст должен быть `'admin'`, `'reseller'` или `'player'`.

**`requireAuth(?string $loginUrl = null): void`**

Проверяет наличие авторизованной сессии. Если запрос идёт напрямую на `session.php`, возвращает JSON-ответ `{"result": true/false}` (используется для AJAX-опроса сессии). Иначе перенаправляет неавторизованных пользователей на страницу входа. При успехе вызывает `touch()` для обновления временной метки активности.

**`isAuthenticated(): bool`**

Неблокирующая проверка. Возвращает `true`, если сессия стартована и ключ `auth` установлен.

**`getUser(): ?string`**

Возвращает значение, сохранённое в ключе `auth` (ID пользователя для admin/reseller или ID линии для player), либо `null`, если не авторизован.

**`getValue(string $name): mixed`**

Возвращает значение сессии по логическому имени (`auth`, `activity`, `ip`, `code`, `verify`). Логическое имя сопоставляется с актуальным ключом `$_SESSION` в зависимости от текущего контекста.

**`setValue(string $name, mixed $value): void`**

Устанавливает значение сессии по логическому имени.

**`login(string $hash, ?string $ip = null): void`**

Создаёт авторизованную сессию, устанавливая значения `auth` и `activity`. Опционально сохраняет IP клиента.

**`destroy(): void`**

Очищает все ключи сессии для текущего контекста. Если ни один другой контекст не активен (проверяется противоположный admin/reseller), уничтожает всю PHP-сессию.

**`clearContext(string $context): void`**

Очищает все ключи сессии для конкретного контекста, не уничтожая сессию. Прямая замена legacy `destroySession($type)`.

**`touch(): void`**

Обновляет `$_SESSION[$activityKey]` текущей временной меткой и вызывает `session_write_close()` для освобождения блокировки сессии.

**`getContext(): ?string`**

Возвращает строку текущего контекста (`'admin'`, `'reseller'`, `'player'`) или `null`, если не установлен.

### Поведение таймаута

`SessionManager::DEFAULT_TIMEOUT` — 60 минут. Метод `checkTimeout()` (вызывается автоматически из `start()`) сравнивает прошедшее время с момента `last_activity`. Если таймаут превышен, все контекстно-зависимые ключи сессии сбрасываются, что фактически означает выход.

Поскольку контекст плеера не имеет ключа `activity` в карте ключей, проверки таймаута к сессиям плеера не применяются.

---

## BruteforceGuard

Файл: `src/Core/Auth/BruteforceGuard.php`

Централизованное rate-limiting и защита от брутфорса. Все методы используют файловое состояние в `FLOOD_TMP_PATH` (`/home/xc_vm/tmp/flood/`). Разрешённые IP (IP серверов) и IP из настройки `flood_ips_exclude` всегда исключаются.

### `checkFlood(?string $ip = null, bool $useCachedMode = false): null`

Rate-limit запросов по IP в настраиваемом временном окне.

- **Настройки:** `flood_limit` (макс. запросов), `flood_seconds` (размер окна).
- **Файл состояния:** `FLOOD_TMP_PATH . $ip` — хранит JSON-объект со счётчиком `requests` и временной меткой `last_request`.
- **Поведение:** Отслеживает количество запросов во временном окне. Если счётчик превышает `flood_limit`, IP блокируется (вставляется в таблицу `blocked_ips` или сигнализируется через Redis в cached/streaming режиме). Файл состояния удаляется после блокировки.
- **Используется:** Player login (вызывается при каждой неудачной попытке входа), endpoint'ы стриминга.

### `checkBruteforce(?string $ip = null, ?string $mac = null, ?string $username = null, bool $useCachedMode = false): null`

Определяет атаки brute-force по числу уникальных MAC-адресов или имён пользователей с одного IP.

- **Настройки:** `bruteforce_mac_attempts`, `bruteforce_username_attempts` (макс. уникальных значений), `bruteforce_frequency` (временное окно в секундах).
- **Файл состояния:** `FLOOD_TMP_PATH . $ip . '_mac'` или `FLOOD_TMP_PATH . $ip . '_user'` — хранит попытки как пары `{term: timestamp}`.
- **Поведение:** Истёкшие попытки (вне окна частоты) удаляются через `truncateAttempts()`. Если число уникальных терминов превышает лимит, IP блокируется.
- **Используется:** Endpoint'ы аутентификации стриминга.

### `checkAuthFlood(array $user, ?string $ip = null): null`

Rate-limit запросов аутентификации для конкретной комбинации user+IP. Предназначен для троттлинга повторных попыток без полной блокировки.

- **Настройки:** `auth_flood_limit` (макс. попыток), `auth_flood_seconds` (окно), `auth_flood_sleep` (задержка в секундах при блокировке).
- **Файл состояния:** `FLOOD_TMP_PATH . $userId . '_' . $ip` — хранит попытки как индексированные временные метки плюс опциональную метку `block_until`.
- **Поведение:** Когда число попыток превышает лимит, устанавливается метка `block_until`. Последующие запросы в период блокировки задерживаются на `auth_flood_sleep` секунд (через `sleep()`). Не блокирует IP навсегда. Пользователи-рестримеры (`is_restreamer`) исключены.
- **Используется:** Аутентификация стриминга.

### `truncateAttempts(array $attempts, int $frequency, bool $list = false): array`

Отфильтровывает истёкшие попытки из массива отслеживания. Когда `$list = true`, обрабатывает массив как индексированный (для `checkAuthFlood`); иначе — как ассоциативный по ключу-термину (для `checkBruteforce`).

### Механизм блокировки

Когда IP блокируется:

- **Обычный режим:** Вставка в таблицу `blocked_ips` с указанием причины (`FLOOD ATTACK` или `BRUTEFORCE MAC/USER ATTACK`) и обновление кэша `BlocklistService`.
- **Cached/streaming режим (`$useCachedMode = true`):** Устанавливается Redis-сигнал (`bruteforce_attack/$ip` или `flood_attack/$ip`) через `RedisManager::setSignal()` для блокировки в стриминговом контексте без записи в БД.
- В обоих режимах создаётся маркер-файл `FLOOD_TMP_PATH . 'block_' . $ip` (touch) для быстрых проверок на уровне файловой системы.

---

## Безопасность сессии

### Конфигурация cookie

В контексте admin-bootstrap session-cookies устанавливаются со строгой политикой SameSite:

```php
$params['samesite'] = 'Strict';
session_set_cookie_params($params);
session_start();
```

### Verify-хеш

Verify-хеш (`$_SESSION['verify']` / `$_SESSION['rverify']` / `$_SESSION['pverify']`) вычисляется как:

```php
md5($username . '||' . $hashedPassword)
```

Это значение проверяется при каждой загрузке страницы против текущих значений в базе данных. Если администратор меняет пароль пользователя (что меняет сохранённый хеш), все существующие сессии этого пользователя автоматически инвалидируются, поскольку verify-хеш больше не совпадёт.

Для admin- и reseller-входов пароль повторно хешируется во время входа, поэтому используется `$rCrypt` (новый хеш). Для player-входов напрямую используется существующий сохранённый хеш `$rUserInfo['password']`.

### Обработка смены IP

Две настройки управляют поведением при смене IP:

| Настройка | Эффект |
| --- | --- |
| `ip_logout` | Когда включена, завершает сессию при смене IP клиента (точное совпадение или подсеть, в зависимости от `ip_subnet_match`). |
| `ip_subnet_match` | Когда включена, сравнивает только первые три октета IP вместо точного совпадения. Позволяет пользователям с динамическими IP в одной подсети сохранять сессию. |

Когда `ip_logout` отключена и IP меняется, сохранённый IP сессии молча обновляется на новый.

---

## Логирование входа

Когда включена настройка `save_login_logs`, все попытки входа (успешные и неудачные) фиксируются в таблице `login_logs`:

```sql
INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`)
VALUES($type, $codeId, $userId, $status, $ip, $timestamp);
```

| Столбец | Описание |
| --- | --- |
| `type` | `ADMIN` или `RESELLER` |
| `access_code` | ID текущего access code |
| `user_id` | ID пользователя (0 для неверных учётных данных) |
| `status` | `SUCCESS`, `INVALID_LOGIN`, `INVALID_CODE`, `NOT_ADMIN`, `DISABLED` |
| `login_ip` | IP-адрес клиента |
| `date` | Unix timestamp |

Логины плееров не пишутся в `login_logs`.

---

## Авторизация (после входа)

После аутентификации два дополнительных слоя авторизации контролируют, к чему пользователь имеет доступ:

### `Authorization`

Файл: `src/Core/Auth/Authorization.php`

Авторизация на уровне объекта. Проверяет, есть ли у текущего пользователя право на доступ к конкретному ресурсу (пользователь, поток и т.д.) на основе иерархии владения реселлера и прав группы.

- `Authorization::hasResellerPermissions($type)` — проверяет один флаг права в `$rPermissions`.
- `Authorization::check($type, $id)` — валидирует доступ к конкретному ресурсу по типу и ID.

### `PageAuthorization`

Файл: `src/Core/Auth/PageAuthorization.php`

Контроль доступа на уровне страниц. Определяет, разрешает ли набор прав группы текущего пользователя доступ к конкретной странице админ- или reseller-панели.

- `PageAuthorization::checkResellerPermissions($page)` — сопоставляет имена страниц с требуемыми флагами прав и возвращает, разрешён ли доступ.

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/Core/Auth/Authenticator.php` | Логика входа admin и reseller, хеширование пароля |
| `src/Core/Auth/SessionManager.php` | Унифицированное API сессий с маппингом ключей контекстов |
| `src/Core/Auth/BruteforceGuard.php` | Rate-limiting и защита от брутфорса |
| `src/Core/Auth/Authorization.php` | Проверки авторизации на уровне объектов |
| `src/Core/Auth/PageAuthorization.php` | Контроль доступа на уровне страниц |
| `src/Public/Controllers/Player/PlayerLoginController.php` | Процесс входа плеера с проверками безопасности |
| `src/Public/Views/admin/functions.php` | Валидация admin-сессии при каждой загрузке страницы |
| `src/Public/Views/admin/session.php` | Таймаут admin-сессии и AJAX-проверка сессии |
| `src/Infrastructure/Bootstrap/reseller_functions.php` | Валидация reseller-сессии при каждой загрузке страницы |
| `src/Domain/User/UserRepository.php` | Поиск учётных данных (`getAuthUserByCredentials`) |
| `src/bootstrap.php` | Определения констант статуса, bootstrap-контексты |
| `src/Core/Config/Paths.php` | Определение `FLOOD_TMP_PATH` |
