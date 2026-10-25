# <a id="interactive-api-reference-swagger"></a>Интерактивный справочник по API (Swagger)

Интерактивный, всегда синхронизированный Swagger Пользовательский интерфейс для каждого XC_VM API, созданный на основе спецификаций OpenAPI 3.1, взятых из исходного кода: каждая операция ссылается на файл и строку, из которых она была считана (`src/...:line`). Используйте **Вкладки API** в верхней части страницы для переключения между API-интерфейсами или откройте один из них напрямую по ссылкам ниже.

|интерфейс прикладного программирования|Описание|Автор|Открыть|
| --- | --- | --- | --- |
| **Player API** |`player_api.php` вход в систему, прямой эфир, VOD, сериалы и EPG; устаревшее руководство `panel_api.php`; `xmltv.php` (15 операций)|`username` + `password`, `token` или код активации|[Открыть ↗](../../_media/swagger-ui.html?spec=player)|
| **Playlist API** | `get.php` playlists — M3U, M3U Plus, Enigma2 bouquets, set-top box formats; also `/playlist` |`username` + `password` или `token`|[Открыть ↗](../../_media/swagger-ui.html?spec=playlist)|
| **Streaming** |Прямой эфир, VOD, серия, продолжение, HLS сегменты и клавиши, миниатюры, субтитры (36 операций)|Учетные данные строки в пути или токен строки / воспроизведения|[Открыть ↗](../../_media/swagger-ui.html?spec=streaming)|
| **MAG Portal** |Stalker/Ministra портал для MAG и совместимых с ним телеприставок (91 операция)|Рукопожатие MAC + токен на предъявителя|[Открыть ↗](../../_media/swagger-ui.html?spec=mag)|
| **Enigma2** |`enigma2.php` XML-плейлисты и `xplugin.php` сопутствующий API (13 операций)|`username` + `password`; MAC устройства + токен|[Открыть ↗](../../_media/swagger-ui.html?spec=enigma2)|
| **Activation** |`active_code.php` активация и управление кодом, а также страница самоактивации `/portal` (15 операций)|Код активации или администратор / реселлер `api_key`|[Открыть ↗](../../_media/swagger-ui.html?spec=activation)|
| **Admin API** |Администрирование — строки, устройства, пользователи, группы, пакеты, потоки, VOD, серии, серверы, настройки, журналы (187 действий)|`api_key` + Код доступа к API администратора|[Открыть ↗](../../_media/swagger-ui.html?spec=admin)|
| **Reseller API** |Линии, устройства, субсчета, коды активации и кредиты реселлера (49 действий)|`api_key` + Код доступа к API реселлера|[Открыть ↗](../../_media/swagger-ui.html?spec=reseller)|
| **System API** |Действия узла `/api.php` (теперь выполняются по шине кластера; HTTP отвечает на 404) и конечных точек потока `/admin/*` (37 операций)|Подписано `node.rpc`; повторный билет или `live_streaming_pass`|[Открыть ↗](../../_media/swagger-ui.html?spec=system)|
| **Cluster API** |Только от главного узла к ГЛАВНОЙ плоскости управления `/cluster/v1/<op>` (22 операции)|Подписанные заголовки `X-XCVM-*`|[Открыть ↗](../../_media/swagger-ui.html?spec=cluster)|
| **Monitoring** |`/metrics` (Операции "Прометей"), `/healthz`, `/progress` (3)|`metrics_token` предъявитель для `/metrics`|[Открыть ↗](../../_media/swagger-ui.html?spec=monitoring)|

---

## <a id="using-try-it-out"></a>Используя "Попробуй это"

1. Откройте спецификацию, затем используйте **Вкладки API** для переключения API и вкладки **Документация / Интерактивность (развязность)** для каждого API.
2. Разверните любую конечную точку → **Попробуйте это сделать** → параметры заполнения → **Выполнять**, чтобы увидеть реальный URL-адрес запроса, команду cURL и оперативный ответ.
3. Для API—интерфейсов администратора, реселлера и мониторинга нажмите **Авторизовать 🔓** и вставьте свой ключ API или токен metrics - затем он автоматически прикрепляется к каждому запросу.

> **Примечание CORS:** прямые вызовы "Попробуйте" из браузера на ваш сервер могут быть заблокированы CORS. Это ожидаемо — вместо этого используйте сгенерированную команду cURL, Postman или Insomnia. Ошибка не означает, что API не работает.

---

## <a id="notes-on-answers"></a>Примечания к ответам

**Admin API**

- `api_key` принимает API-ключ учетной записи или один из его [API-токенов](../guides/permissions-and-rbac.md#api-tokens), который может быть ограничен для чтения или для строк, устройств и кодов активации.
- API-ключ действует с разрешениями, указанными в группе его владельцев. В таблице в разделе [Admin API keys](../guides/permissions-and-rbac.md#admin-api-keys) указаны разрешения, запрашиваемые для каждого действия; действие, на которое у группы нет разрешения, отвечает `STATUS_NO_PERMISSIONS`. API с активным кодом отвечает аналогично, когда он вызывается с помощью ключа API администратора.
- `delete_user`, `disable_user`, `enable_user` и `adjust_credits` ответьте `STATUS_FAILURE` для учетной записи администратора, если только ключ не принадлежит полноправному администратору.
- `get_user`, `create_user` и `edit_user` отвечают на вызов учетной записи без ее `password` и с ее `api_key` только в том случае, если это учетная запись, к которой принадлежит вызывающий ключ.
- `create_line`, `edit_line`, `create_mag`, `edit_mag`, `create_enigma` и `edit_enigma` отвечайте на `STATUS_INVALID_USERNAME` или `STATUS_INVALID_PASSWORD`, если имя пользователя или пароль содержат `/`. Строка или устройство, у которых уже есть такое значение, сохраняет его и остается доступным для редактирования, пока это значение не будет отправлено без изменений или не будет удалено. API реселлера отвечает тем же для строк; см. [Система реселлера](../administration/reseller-system.md#rest-api).
- `edit_line` сохраняет имя пользователя и пароль строки, если запрос не отправляет их. При вводе пустого значения запрашивается сгенерированное значение.
- Каждое действие `edit_*` сохраняет каждое поле, пропущенное запросом, в том виде, в каком оно сохранено в записи, поэтому запрос может отправлять только те поля, которые он изменяет. Отправьте поле, чтобы изменить его; отправьте его пустым, чтобы очистить (отправленный пустой список - это пустой список, отправленная пустая дата - это нет). Переключатель (флажок в форме панели) включен в положении `1` и выключен в положении `0` или пуст. Поля именуются так, как их отображает форма панели: списки в виде массивов (`bouquets[]`, `category_id[]`, `domain_name[]`, `http_broadcast_ports[]`), или в виде массива JSON, где форма отображает одно (`bouquets_selected`, `groups_selected`, `permissions_selected`, `packages_selected`, `server_tree_data`). Ключ HMAC сохраняется до тех пор, пока не будет отправлено `keygen`; адрес RTMP сохраняет свой пароль, пока он не будет отправлен. `edit_settings` изменяются только настройки - имена запросов, значение параметра stream по умолчанию (`user_agent`, `http_proxy`, `cookie`, `headers`) и включенное сопоставление жанров.
- For `edit_line`: `exp_date` empty, or `no_expire` on, removes the expiry (a `no_expire` of `0` or empty is off); `isp_clear` clears the stored ISP. `exp_date` takes a date string, not the timestamp `get_line` answers. `bouquets_selected` is a JSON array, outputs are `access_output[]`. `is_stalker`, `is_restreamer`, `is_trial`, `is_isplock` and `bypass_ua` are off at `0` or empty. `enabled` and `admin_enabled` are kept: send `enabled=1` / `admin_enabled=1` to switch a disabled or banned line back on (an edit used to do that whatever it sent).
- `edit_user`: `credits` устанавливает баланс на это значение; не указывайте его, чтобы сохранить баланс. Значение, равное балансу на момент `get_user` (шесть значащих цифр), также сохраняет баланс.
- `create_group` и `edit_group`: `group_id` в тексте игнорируются. Новая группа получает следующий идентификатор, а при редактировании группа изменяется на `id`.
- `create_transcode_profile` и `edit_transcode_profile` отвечают на `STATUS_INVALID_INPUT`, когда `video_codec_cpu`, `video_codec_gpu`, `audio_codec` или поле `preset_*` не является отдельным именем, или поле `video_profile_*` не является именем с необязательным ` -level N`.
- `install_server` устанавливает балансировщик нагрузки и `install_proxy` прокси-сервер. Для обоих нужны `ssh_port`, `root_username` и `root_password`, и ответьте на `STATUS_INVALID_INPUT` без одного из них.
- строки `activity_logs` (`player`) и `live_connections` (`user_agent`) содержат название устройства в том виде, в каком оно сохранено, с кодировкой объектов HTML (`&amp;`, `&quot;`). В таблицах панели отображается расшифрованное название.

**Player API**

- Вход в систему с использованием кода активации в качестве имени пользователя или токена означает `user_info.status` `Expired` ("Срок действия учетной записи истек"), когда срок действия подписки на код истек, и `Disabled` ("Учетная запись отключена"), когда действие кода приостановлено или отозвано. Другие отклоненные коды отвечают: "Имя пользователя или пароль неверны"..

---

## <a id="raw-specifications"></a>Исходные технические характеристики

Каждая спецификация может быть импортирована в Postman, Insomnia или любой другой инструментарий OpenAPI 3.1:

- [`player-api.openapi.yaml`](../../_media/player-api.openapi.yaml)
- [`playlist-api.openapi.yaml`](../../_media/playlist-api.openapi.yaml)
- [`streaming-api.openapi.yaml`](../../_media/streaming-api.openapi.yaml)
- [`mag-portal-api.openapi.yaml`](../../_media/mag-portal-api.openapi.yaml)
- [`enigma2-api.openapi.yaml`](../../_media/enigma2-api.openapi.yaml)
- [`activation-api.openapi.yaml`](../../_media/activation-api.openapi.yaml)
- [`admin-api.openapi.yaml`](../../_media/admin-api.openapi.yaml)
- [`reseller-api.openapi.yaml`](../../_media/reseller-api.openapi.yaml)
- [`system-api.openapi.yaml`](../../_media/system-api.openapi.yaml)
- [`cluster-api.openapi.yaml`](../../_media/cluster-api.openapi.yaml)
- [`monitoring-api.openapi.yaml`](../../_media/monitoring-api.openapi.yaml)
