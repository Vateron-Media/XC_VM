# Ministra: неиспользуемые (никогда не подключаемые) модули

Список JS-модулей портала в `src/Ministra/`, которые **никогда не грузятся** при
текущей конфигурации. Составлен для последующего **поштучного разбора** —
удалить / включить / оставить.

## Как определялось

`loader` подключает только:

- **`base_modules`** — клиентский список, всегда ([`xpcom.common.js` → `this.base_modules`](../../../src/Ministra/xpcom.common.js));
- **`all_modules`** — приходит с сервера, `action: get_modules` ([`PortalHandler.php`](../../../src/Ministra/PortalHandler.php));
- под-модули, которые грузят вышеперечисленные по имени (напр. `player` → `audioclub`/`karaoke`/`demo`, `tv` → `pvr`).

Модуль считается **мёртвым**, если его нет ни в `base_modules`, ни в `all_modules`,
и на его имя нет ссылок из живых модулей (единственные упоминания — он сам).

> ⚠️ Это не удалённый код, а **дремлющие легаси-фичи**: файлы лежат, но
> серверный `get_modules` их не отдаёт. Часть можно включить, изменив конфиг.

Статусы разбора: `TODO` — не решено · `DELETE` — удалить · `KEEP` — оставить · `ENABLE` — включить.

---

## 1. Infoportal (легаси инфо-экран Stalker)

Целая ветка «инфопортала» (новости/погода/гороскоп/игры). Корневой `infoportal`
мёртв → вся ветка не грузится.

**Вся ветка ✅ DELETED** (9 js + ~135 css + 15 иконок `mm_ico_info.png` + записи из `all_modules`).
Причина: не просто мёртвая, а **сломанная** — при включении `infoportal` крашит меню
(`main_menu.js:503 — cmd is not a function`: пустой submenu + `cmd=""`). См. историю разбора.

| Файл | Что это | Разбор |
|---|---|---|
| ~~`infoportal.js`~~ | корневой модуль (пункт меню + сабменю из `infoportal_sub`) | ✅ DELETED |
| ~~`anecdote.js`~~ | анекдоты (виджет инфопортала) | ✅ DELETED |
| ~~`cityinfo.js`~~ | справочник по городу | ✅ DELETED |
| ~~`horoscope.js`~~ | гороскоп | ✅ DELETED |
| ~~`weather.current.js`~~ | виджет текущей погоды | ✅ DELETED |
| ~~`weather.day.js`~~ | виджет погоды на день | ✅ DELETED |
| ~~`course.cbr.js`~~ | курс ЦБ РФ (виджет, себя не регистрировал) | ✅ DELETED |
| ~~`course.nbu.js`~~ | курс НБУ (виджет, себя не регистрировал) | ✅ DELETED |
| ~~`game.mastermind.js`~~ | мини-игра «быки и коровы» | ✅ DELETED |

> Безвредные орфаны на потом: `demo.js` пушит в `module.infoportal_sub` (теперь никто не читает);
> lang-ключи `cityinfo_*`/`anecdote_*` в `portal.php`; серверные экшены `case "course"`/`"weatherco"`
> в `PortalHandler`. Всё инертно — можно вычистить отдельно.

## 2. Apps (приложения)

| Файл | Что это (предположительно) | Разбор |
|---|---|---|
| `apps.js` | модуль «Приложения» | TODO |
| `app_skeleton.js` | каркас/шаблон приложения | TODO |
| ~~`magiccast.js`~~ | редирект на внешний Infomir-сервис `magiccast.magapps.net`; передавал прокси-креды юзера третьей стороне | ✅ **DELETED** (js + 15 css + 15 иконок) |

## 3. Отдельные модули

| Файл | Что это (предположительно) | Разбор |
|---|---|---|
| ~~`youtube.js`~~ | лаунчер YouTube: нативное приложение Infomir (Android-MAG) ИЛИ веб-враппер `external/youtube/` — а его в репо НЕТ (404) | ✅ **DELETED** (js + 15 иконок; см. «Будущее» ниже) |
| `service_management.js` | управление подписками/услугами | TODO |
| `remotepvr.js` | удалённый PVR (серверная запись); в `all_modules` есть `records`/`pvr_local`, но не `remotepvr` | TODO |
| `widget.audio.js` | аудио-виджет (грузит `audioclub`, но сам не подключается) | TODO |
| `widget.radio.js` | радио-виджет | TODO |
| `JsHttpRequest-debug.js` | debug-сборка AJAX-библиотеки; ядро грузит `JsHttpRequest.js` | TODO |

---

## 4. Мёртвые по backend (аудит запросов клиента 5.6.10)

Отдельный проход: сверили **все `action`, которые шлёт клиент**, против трёх диспетчеров
`portal.php` (`handlePreInit` → `handleStbPublic` → `handleAuthenticated`). Нашли типы
запросов, у которых **нет обработчика** (ответ пустой) → фича мертва по backend.

| type / файл | action'ы | Разбор |
|---|---|---|
| ~~`karaoke.js`~~ | `get_ordered_list`/`get_abc`/`get_fav_ids`/`set_fav` (`type:karaoke`) | ✅ **DELETED** (js + 15 css + 15 `mm_ico_karaoke.png` + 8 lang-ключей `karaoke_*`). Не грузился (нет в `base_modules`/`all_modules`) + нет backend. «Только радио». Инертные `cur_place=="karaoke"` в player.js/xpcom.common.js — upstream-гарды, не трогаем |
| ~~`pvr.js`~~ (`function Pvr`) | `get_new_id`/`get_ordered_list` (`type:pvr`) | ✅ **DELETED** (только файл). Legacy-PVR, не грузился, `new Pvr` нигде. Живая локальная запись — это **`pvr_local`+`records`** (их css/lang `pvr_*` НЕ трогали) |
| `video_master` (ветки в `vclub.js`/`sclub.js`) | `get_storages_for_video`, `check_video_price`, `rent_video` | KEEP инертным. **Не файл** — ветки внутри живых VOD-модулей. Аренда/скачивание VOD «на диск»; недостижимо (`get_storages`→`[]`). Вырезать = развести 5.6.10-файлы, риск. Оставить |
| `vclub_advertising` (ветки в `vclub.js`/`sclub.js`) | `set_ad_ended_time` | KEEP инертным. Телеметрия VOD-преролла. Реклама выключена (`get_ad`→`[]`) → событие не наступает, запрос не уходит. Безвредно |

> Заглушки (`handlePreInit`) для нереализованных фич отдают безопасные `true`/`[]`/`""` и
> клиента устраивают — трогать не надо: `remote_pvr`, `media_favorites`, `tvreminder`,
> `downloads`, `get_ad`, `get_storages`, гео (`get_countries/cities/timezones`),
> `account_info/get_terms|payment|agreement_info`.

## Пограничный случай (НЕ мёртвый)

| Файл | Почему живой | Разбор |
|---|---|---|
| `traceroute.js` | нет в списках модулей, **но** вызывается из `external/settings/g_netw.html` (страница сетевых настроек) | KEEP? |

## Для контраста — живые под-модули

Не в списках, но реально грузятся родителями (НЕ трогать):
`audioclub` ← `player` · `karaoke` ← `player` · `demo` ← `player` · `pvr` ← `tv` ·
`series_switch` / `duration_input` / `layer.*` ← `base_modules`.

---

## Чеклист действий

- [ ] Пройтись по каждому модулю выше, проставить `DELETE` / `KEEP` / `ENABLE`.
- [ ] Для `DELETE`: удалить `.js` + соответствующие `template/*/<module>*.css` + слова в языковых пакетах.
- [ ] Для `ENABLE`: добавить имя в `all_modules` (`PortalHandler::get_modules`) и проверить в браузер-эмуляторе.
- [ ] Прогнать браузер-эмулятор после чистки — убедиться, что нет `Error loading script`.
