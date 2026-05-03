# Ministra: браузерная эмуляция STB

Короткая справка для запуска Ministra в обычном браузере (без реальной MAG-приставки) и диагностики ошибки `your device is not active`.

---

## Когда использовать

- Нужно проверить загрузку `index.html` и вызовы `portal.php` через token-path.
- Нужно быстро отладить handshake/get_profile в браузере.
- Нужно понять, почему устройство получает статус неактивного.

---

## Поддерживаемые URL

Обычно используются два варианта (в зависимости от nginx-конфига):

- `http://HOST/ACCESS_CODE/`
- `http://HOST/c/`

Для браузерной эмуляции важно, чтобы открывался именно `index.html`, а API-шаги шли в `portal.php` внутри того же префикса.

Для STB Emulator входная точка тоже должна быть базовым префиксом (или `portal.php` без query-параметров), а не готовым запросом `action=handshake`.

---

## Черный экран в STB Emulator

Если в эмуляторе указан URL вида:

```text
http://HOST/ACCESS_CODE/portal.php?type=stb&action=handshake&mac=...&token=&prehash=...&JsHttpRequest=1-xml
```

это ошибка конфигурации. Такой URL возвращает JSON-ответ handshake и не является экраном портала, из-за чего в эмуляторе обычно будет черный экран.

Используйте один из вариантов:

- `http://HOST/ACCESS_CODE/`
- `http://HOST/c/`
- `http://HOST/ACCESS_CODE/portal.php` (только если эмулятор требует именно путь к `portal.php`, но без `type/action/token/prehash` в URL)

Параметры `type`, `action`, `JsHttpRequest`, `token`, `prehash` должен формировать сам клиент (эмулятор) на каждом шаге API.

---

## Обязательные GET-параметры

Если включена проверка устройства, передавайте идентификаторы явно:

| Параметр | Назначение |
| --- | --- |
| `mac` | MAC устройства для handshake |
| `sn` | Серийный номер устройства |
| `stb_type` | Модель STB (например `MAG250`) |
| `device_id` | Первый device id |
| `device_id2` | Второй device id |
| `hw_version` | Аппаратная версия |

Без этих значений сервер может отклонить `get_profile` как невалидное устройство.

---

## Полезные GET-параметры

| Параметр | Когда нужен |
| --- | --- |
| `debug_key` | Обход ограничения по `allowed_stb_types` на клиенте |
| `ver` | Если включен lock по образу/версии |
| `image_version` | Если сравнивается версия образа |
| `access_token` | Для теста повторного входа с готовым token |
| `auth_via_query` | Debug-режим: дублировать token в query-параметр, если Authorization режется прокси/nginx |
| `debug` | Клиентский debug-режим (в текущей сборке и так включен по умолчанию) |

---

## Пример URL

```text
http://192.168.110.251/HgBjUjSI/?mac=00:1A:79:11:22:33&sn=062014N000001&stb_type=MAG250&device_id=ABC123&device_id2=DEF456&hw_version=1.7-BD-00&debug_key=1
```

При необходимости добавьте:

```text
&ver=ImageDescription%3Aemu&image_version=218&auth_via_query=1
```

Для STB Emulator в настройках профиля укажите только portal URL, например:

```text
http://192.168.110.251/HgBjUjSI/
```

---

## Что означает your device is not active

Сообщение появляется, когда профиль приходит с `status = 1` (устройство не прошло аутентификацию/проверку).

Частые причины:

1. MAC не найден в `mag_devices`.
2. Не совпадают `sn`, `device_id`, `device_id2`, `hw_version` при `lock_device = 1`.
3. Модель не проходит whitelist `allowed_stb_types`.
4. Токен handshake невалиден или не принят на `get_profile`.

---

## Быстрый чеклист

1. Открыть портал через корректный префикс (`/ACCESS_CODE/` или `/c/`).
2. Для STB Emulator не использовать URL с `action=handshake` в настройке портала.
3. Передать все обязательные поля (`mac`, `sn`, `stb_type`, `device_id`, `device_id2`, `hw_version`).
4. Проверить, что handshake вернул token и следующий `get_profile` уходит с Authorization Bearer.
5. Если Authorization не доходит до PHP, временно включить `auth_via_query=1`.
6. При ограничениях по типу STB добавить `debug_key=1`.
7. Если блок не снимается, проверить запись устройства в БД (`mag_devices`) и флаг `lock_device`.

---

## Где смотреть реализацию

- Клиентские параметры и вызовы API: `src/ministra/xpcom.common.js`
- Инициализация debug/get-параметров: `src/ministra/index.html`
- Серверная проверка устройства и профиль: `src/ministra/portal.php`
- Handshake/get_profile orchestration: `src/modules/ministra/PortalHandler.php`
