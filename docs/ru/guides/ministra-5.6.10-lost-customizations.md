# Ministra 5.6.10 — потерянные кастомизации (чек-лист восстановления)

При обновлении 5 ядровых клиентских файлов на **чистый 5.6.10** наши xc_vm-кастомизации
не переносились автоматически (форк разошёлся слишком сильно для надёжного 3-way merge
без общего предка). Этот файл фиксирует, что именно нужно вернуть при ревью.

**Старые (кастомные) версии** каждого файла лежат в git:

```bash
git show HEAD:src/Ministra/xpcom.common.js   # наша версия до вставки чистого 5.6.10
git diff HEAD -- src/Ministra/xpcom.common.js # что изменилось (наше → 5.6.10)
```

Легенда: ✅ восстановлено · ❌ ещё нет · ⚠️ проверить/решить.

---

## xpcom.common.js

- ✅ **API endpoint** — бэкенд `portal.php`, а не классический `server/load.php`; свой
  regex `portal_path`: `/(http?|https?):\/\/([^\/]*)\/([\w\/]+)*\/(.)*/` (терпит `?query`
  в URL). Блок `get_server_params()`. Без этого API-запросы уходят в никуда и сервер
  отдаёт `index.html` → `Unexpected token '<'`.
- ✅ **Debug / браузерная эмуляция STB** — переделано «более адекватно»: единый гейт
  `get_debug_param()` по `?debug_key`; подмена `mac`, `sn`, `stb_type`, `hw_version`,
  `ver`, `image_version` (init), `device_id`/`device_id2` (do_auth), `auth_via_query`
  (load). См. `docs/ru/guides/ministra-browser-emulation.md`.
- ❌ **Родительский контроль в `load_channels`** — обработка `genre.censored` +
  `module.tv.parent_password_promt` (запрос родительского пароля на скрытых жанрах) и
  метод `load_fav_channels`. В 5.6.10 логика родит.контроля другая.
- ✅/⚠️ **Auth-поток в `load()`** — оказался **уже в 5.6.10** (ветка `"Access denied."`
  → `stb.cut_off()`, `connection_problem`/`play_last` присутствуют). НЕ потерян. Отдельно:
  5.6.10 не защищает `_debug(result.text)` от **null/пустого ответа** — необработанные
  action нашего portal.php (`get_types_list` и т.п.) отдают пустое тело → в консоли
  `Cannot read properties of null (reading 'text')` (некритично, портал грузится). Фикс на
  выбор: отдавать `{"js":""}` для неизвестных action в portal.php, либо guard
  `result && result.text` в xpcom.
- ✅ **`get_types_list` (новый экшен 5.6.10)** — клиент 5.6.10 при старте (`this.get_types_list()`,
  ~строка 688) отдельным запросом `type=stb&action=get_types_list` тянет белый список типов
  STB и кладёт его в `this.allowed_stb_types` (**именно это поле** читает проверка типа
  приставки — не копия из профиля). В старом клиенте вызова не было, наш `portal.php`/
  `PortalHandler::handleStbPublic` его не обрабатывал → ответ пустой → `allowed_stb_types = []`
  → любая приставка = «not supported» (в браузере скрыто за `debug_key`). Добавлен
  `case "get_types_list"` в `handleStbPublic` — отдаёт `{allowed_stb_types, strict_stb_type_check}`
  из `$rSettings`. Требует **деплой `PortalHandler.php`** на сервер.
- ✅ **`"layer.sclub_info"`** в `base_modules` (после `layer.vclub_info`) — восстановлено.
  Без него `sclub.js` падал с `ReferenceError: sclub_info is not defined`.
- ⚠️ **Списки моделей STB / `allowed_stb_types`** — наша обработка `aurahd` и проверки
  `allowed_stb_types` (частично закомментировано `cut_off('stb_type_not_supported')`).
  Сверить, нужна ли правка поверх 5.6.10.
- ⚠️ **`check_image_version`** — у нас вызов закомментирован. Решить, оставлять ли так.

---

## tv.js

- ❌ **9 веток `if (loader.template == "xc_vm")`**:
  - `preview_pos_map` — свои координаты превью для темы xc_vm (720/1080/3840).
  - расчёт `preview_pos` — без 1080-скейлинга в xc_vm.
  - вызов `this.init_search_box()` перед `this.show`.
  - EXIT-хендлер — `stb.player.stop()` вместо `this.check_for_play()`.
  - `set_short_container` — включение синей кнопки.
  - `color_buttons_map` — кнопка поиска (`radio_search`) вместо `tv_move`.
- **Кастомные методы, которых НЕТ в 5.6.10** — подсистема поиска по каналам:
  - ✅ `hide_player()` / `show_player()` — восстановлены (их звал `tv_archive.js:321`
    → `hide_player is not a function`; в не-xc_vm они no-op).
  - ✅ `init_search_box()` / `search_menu_switcher()` — **сохранены** в 5.6.10 tv.js
    (774/815), вызов из xc_vm-ветки (226) рабочий. Потеряна только привязка кнопки
    поиска в `color_buttons_map` (см. ниже).
  - ✅ **`color_buttons_map` (xc_vm)** — восстановлено (гибрид). В теме xc_vm: если
    `tv_quality_filter` включён → кнопка «Качество» (фича 5.6.10), иначе → «Поиск»
    (`radio_search` → `tv.search_menu_switcher`, кастом форка). Не-xc_vm темы — без
    изменений (`tv_move`). Требует **деплой** tv.js.
- ✅ **Сортировка `snumber` — НЕ нужна** (не регресс). Форк делал
  `sortBy("snumber")` + `sortIDs()` (переномерация каналов 1..n на клиенте). Backend
  XC_VM теперь сам отдаёт `"number" => $rNumber++` (последовательная нумерация), а
  `snumber` не шлёт вовсе → `sortBy("number")` из 5.6.10 корректен. `sortIDs` устарел.
- ⚠️ **`row_callback_timeout = 500`** (в 5.6.10 — `50`). Проверить нужное значение.
- ✅ **Логотипы каналов** (`block_name == "logo"`) — восстановлено. На xc_vm `data`
  (tv.js `handling_block`) и `item.logo` (player.js превью) — **готовые URL**, а 5.6.10
  строил классический stalker-путь `.../misc/logos/120/` (tv.js) и
  `.../misc/logos/{320|240}/` (player.js) → битая картинка. Вернули `<img src="<url>">`,
  сохранив у player.js фичу 5.6.10 (`class="timeshift_mode"`). Требует **деплой** tv.js + player.js.
- ✅ **Родительский контроль (запрос пароля при открытии канала)** — восстановлена
  логика форка в 3 гардах (`~448` EPG-кнопка, `~668` полный экран, `~1255` превью
  `check_for_play_in_preview`). 5.6.10 сменил гард `genre.alias != "for adults"` →
  `genre.censored != 1` и **добавил** проверку домашнего жанра канала
  (`item_genre_censored`, инициализирован `= 1` — fail-closed). Из-за этого канал из
  adult-жанра (или чей `tv_genre_id` не найден в `module.tv.genres` — favorites/«все»)
  просил пароль на **каждом** открытии. Вернули `genre.alias != "for adults"` и убрали
  `item_genre_censored` + баннер `word["Channel is locked"]`. Требует **деплой `tv.js`**.
- ⚠️ **Родит. превью-блок** «Channel is locked» — сознательно НЕ возвращаем из 5.6.10
  (локи обрабатываются через `load_channels` + гарды выше), иначе двойной запрос пароля.
- ℹ️ Guard скрытия модалок (`password_input`/`parent_password_promt`) — уже есть в
  5.6.10, отдельно возвращать не нужно.

---

## player.js

- ❌ **Кастомный preview/playback** для темы xc_vm.
- ❌ **Свой поток родительского контроля** (`password_input.callback`, `unlocked`,
  `_play_now`) — отличается от 5.6.10.
- ⚠️ **Мульти-аудио / titles** — у нас своя реализация (`infoCurtitle`, `titles.length`).
  Сверить с 5.6.10, чтобы не потерять/не задвоить.

---

## account.js

- ❌ **Минимальный экран аккаунта** — показываем только `Phone` + `result["message"]`
  вместо расширенного экрана 5.6.10.
- ❌ **Переключение UI-режима** — `set_modern` / `set_legacy` (через `stb.load` + reboot)
  на кнопках green/yellow вместо стандартных payment/agreement/terms.
- ❌ **Состояния цветных кнопок** — `red.enable()` / `blue.disable()` (у 5.6.10 иначе).
- ⚠️ Мы **намеренно убрали**: экран оплаты по `key.blue` из blocking, расширенные поля
  (`fname`/`ls`/`password`/`tariff_plan`/`account_balance`/`end_date`), поток
  `external_payment_page_url` (web-window), вкладки agreement/terms. НЕ возвращать, если
  хотим сохранить наш минимальный экран.

---

## time_shift.js

- ✅ **Формат сегментов `.ts`** — восстановлено: `/([^\/]*)\.ts/` в 4 regex (строки
  134/255/464/637) + литерал `".ts"` (542), точно по HEAD. Было `.mp[g,4]`/`.mpg` (5.6.10).
- ⚠️ **Убраны ветки `nimble_dvr`** — у нас DVR на `.ts` (flussonic-style); nimble не
  используется. Решить, оставлять ли новый nimble-код 5.6.10 (инертен, если не включён).
- ⚠️ **Перемотка** — сверить `cur_file_date.setSeconds(pos + cur_file_date.getSeconds())`
  (наше) против `setSeconds(pos)` + `.valueOf()` (5.6.10, фикс #33139). Возможен разный
  результат сик-позиции на `.ts`.

---

## index.html (НЕ трогали — оставлен наш)

Для справки: свой inline-загрузчик `loadRequiredFiles` (вместо `../server/api/load_js.php`),
принудительный `resolution_prefix = "_720"`, `<title>Portal</title>`. `var debug = 1` на
эмуляцию больше не влияет (гейт теперь `?debug_key`).

---

## Исправления 404 (по ходу браузерного теста)

- ✅ **preload-картинки** — `PortalHandler.php` (`get_preload_images`) отдавал хардкоженный
  список из 33 png, но современная тема на спрайтах → 15 из 33 давали 404. Список теперь
  фильтруется по `is_file(__DIR__ . "/" . $rPath)` — отдаются только существующие
  (self-healing для любой темы).
- ✅ **`fonts_720.css` 404** — 5.6.10 в `xpcom.common.js` вызывал `loader.append_style("fonts")`,
  но кастомные шрифты мы удалили (см. решение по .otf). Строку убрал. Если захотите
  вернуть шрифты — вернуть и эту строку, и `fonts*.css`/`fonts/`.
- ℹ️ Требуется **деплой** `PortalHandler.php` и `xpcom.common.js` на сервер, чтобы фиксы
  подхватились (на 192.168.110.251 пока старый код).

---

## Как восстанавливать

1. Открыть diff: `git diff HEAD -- src/Ministra/<file>` (наше слева `-`, 5.6.10 справа `+`).
2. Для каждого пункта выше найти блок в `git show HEAD:src/Ministra/<file>` и перенести в
   текущую (5.6.10) версию, **сохраняя** новые фичи 5.6.10 вокруг.
3. После правок: `node --check src/Ministra/<file>`.
4. Тест в браузере (эмуляция): handshake → get_profile → загрузка модулей.
5. Помечать пункты ✅ по мере переноса.
