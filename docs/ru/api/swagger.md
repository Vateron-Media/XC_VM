# Интерактивный справочник API (Swagger)

Интерактивный, всегда актуальный Swagger UI для всех API XC_VM, сформированный из спецификаций OpenAPI 3.0. Переключайтесь между API через **вкладки API** вверху страницы или открывайте нужный напрямую по ссылкам ниже.

| API | Описание | Авторизация | Открыть |
| --- | --- | --- | --- |
| **Admin API** | Администрирование (совместимо с XUI.ONE) — линии, пользователи, стримы, VOD, сериалы, серверы, настройки (104 эндпоинта) | `api_key` + код доступа | [Открыть ↗](_media/swagger-ui.html?spec=admin ':ignore :target=_blank') |
| **System API** | Внутренний `/api.php` — управление стримами/VOD, статистика, процессы, файлы, соединения (31 действие) | `password` (`live_streaming_pass`) | [Открыть ↗](_media/swagger-ui.html?spec=system ':ignore :target=_blank') |
| **Player API** | XtreamCodes-плеер — Live TV, VOD, сериалы, EPG | `username` + `password` | [Открыть ↗](_media/swagger-ui.html?spec=player ':ignore :target=_blank') |
| **Playlist API** | `/playlist` — авторизация и генерация плейлистов | `username`/`password` или `token` | [Открыть ↗](_media/swagger-ui.html?spec=playlist ':ignore :target=_blank') |

---

## Как пользоваться «Try it out»

1. Переключайте API **вкладками API**, а для каждого API — вкладками **Documentation / Interactive (Swagger)**.
2. Разверните эндпоинт → **Try it out** → заполните параметры → **Execute**, чтобы увидеть реальный URL запроса, команду cURL и живой ответ.
3. Для Admin API нажмите **Authorize 🔓** и вставьте API-ключ — далее он добавляется во все запросы автоматически.

> **О CORS:** прямые вызовы «Try it out» из браузера к вашему серверу могут блокироваться политикой CORS. Это ожидаемо — используйте сгенерированную команду cURL, Postman или Insomnia. Ошибка не означает, что API сломан.

---

## Исходные спецификации

Любую спецификацию можно импортировать в Postman, Insomnia или любой инструмент OpenAPI 3.0:

- [`admin-api.openapi.yaml`](_media/admin-api.openapi.yaml ':ignore :target=_blank')
- [`system-api.openapi.yaml`](_media/system-api.openapi.yaml ':ignore :target=_blank')
- [`player-api.openapi.yaml`](_media/player-api.openapi.yaml ':ignore :target=_blank')
- [`playlist-api.openapi.yaml`](_media/playlist-api.openapi.yaml ':ignore :target=_blank')
