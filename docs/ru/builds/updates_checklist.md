# XC_VM Контрольный список для подготовки релиза

Пошаговое руководство по подготовке и публикации релиза XC_VM.

---

## 1. Журнал изменений

**Создание журнала фиксации (только рабочие фиксации):**

```bash
PREV_TAG=$(git describe --tags --abbrev=0)
mkdir -p dist
git log --pretty=format:"- %s (%h)" "$PREV_TAG"..main > dist/changes.md
```

> ⚠️ `mkdir -p dist` is required: `dist/` does not exist on a fresh clone and `make new` wipes it — without it the redirect fails with "No such file or directory".

**Обновить `changelog.json`** в корневом каталоге репозитория — этот файл содержит только изменения для предстоящего выпуска:

```json
{
    "version": "X.Y.Z",
    "changes": [
        "Description of change 1",
        "Description of change 2"
    ]
}
```

Панель автоматически извлекает этот файл из тега release через `GithubReleases::getChangelog()`.

> 💬 Делайте описания краткими — сосредоточьтесь на улучшениях и исправлениях, с которыми сталкиваются пользователи.

---

## 2. Подготовить базовый уровень выпуска

Сначала завершите всю работу с функциями / исправлениями / документами и убедитесь, что она уже включена в `main`.

Установите переменную version один раз и повторно используйте ее во всех приведенных ниже командах:

```bash
VERSION="X.Y.Z"
```

> ❗️ Не создавайте отдельную версию-измените фиксацию/push на этом шаге.
> В противном случае `dist/changes.md` будет включать дополнительные фиксации релиза и потребует внесения дополнительных правок.

### Восстановление переведенной документации

Документация написана ** только на английском языке** (`docs/en`). Дерево на русском языке
(`docs/ru`) - это ** сгенерированный, зафиксированный** артефакт, обновляемый локально перед каждым
release — перевод намеренно **не** выполняется в CI (он медленный); только CI
создает зафиксированное дерево. Если `docs/en` изменено с момента последнего выпуска:

```bash
make docs-translate      # regenerate docs/ru from docs/en (free, no API key)
make docs-build          # strict build — fails on any broken link/anchor
```

- `make docs-translate` повторно переводятся только те файлы на английском языке, содержимое которых
изменен (для каждого файлового кэша), так что при постепенном выпуске это происходит быстро.
- **Review and commit the regenerated `docs/ru`** — it is included in the single
снимите фиксацию (шаг 5). Никогда не редактируйте вручную `docs/ru`.
- Нажатие кнопки изменения документов запускает `pages.yml`, которая создает и публикует
страницы сайта на GitHub.

---

## 3. Удаленные файлы

Перед сборкой сгенерируйте список файлов для удаления при обновлении:

```bash
make generate_deleted_files
```

Это запускает `git diff` между `LAST_TAG` и `HEAD`, извлекает удаленные файлы из `src/`, удаляет префикс `src/` и записывает результат в `src/migrations/deleted_files.txt`.

Если `LAST_TAG` не может быть определено автоматически (нет сети / нет выпусков), передайте его явно:

```bash
make generate_deleted_files LAST_TAG=1.2.16
```

**Просмотрите созданный файл ** — убедитесь, что по ошибке в списке нет важных файлов:

```bash
cat src/migrations/deleted_files.txt
```

После проверки `make main` / `make lb` упакует файл в архив с помощью `delete_files_list` / `lb_delete_files_list`.

Во время `php console.php update post-update` `MigrationRunner::runFileCleanup()` считывает его и автоматически удаляет перечисленные файлы.

> ❗️ Строки, начинающиеся с `#`, являются комментариями и будут проигнорированы. Вы можете закомментировать файлы, которые хотите сохранить.

---

## 4. Предварительная проверка

Перед публикацией проверьте, работает ли сборка:

**Проверка качества ** (CI использует тот же набор, что и на бирке — убедитесь, что он зеленый):

```bash
make dev-tools && make phpstan && make cs && make gates
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist
make dev-clean   # remove the dev tools afterwards, restoring the prod-only vendor/
```

> ➡ ️ Тестовая установка Docker перенесена на шаг 6 — для этого требуется встроенный `dist/XC_VM.zip`.

** Проверка безопасности:** выполняется автоматически при нажатии / PR через `.github/workflows/security-scan.yml` (Semgrep) — никаких действий вручную.

---

## 5. Обновите версию и создайте единую фиксацию выпуска

Отредактируйте константу версии, отключите флаг доступа phpMiniAdmin и снимите пароль в:

```text
src/Core/Config/AppConfig.php
```

**Быстрые команды:**

```bash
sed -i "s/define('DB_ACCESS_ENABLED', true);/define('DB_ACCESS_ENABLED', false);/" src/Core/Config/AppConfig.php
sed -i "s/define('DB_ACCESS_PWD', *\"[^\"]*\");/define('DB_ACCESS_PWD', \"\");/" src/Core/Config/AppConfig.php
sed -i "s/define('XC_VM_VERSION', *'[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', '${VERSION}');/" src/Core/Config/AppConfig.php
```

**Создайте одну окончательную фиксацию/толчок выпуска:**

```bash
git add src/Core/Config/AppConfig.php changelog.json src/migrations/deleted_files.txt
git add docs/en docs/ru   # include any doc edits + the regenerated ru (step 2)
git commit -m "Prepare release ${VERSION}"
git push
```

> ❗️ Это устраняет необходимость в многократных фиксациях релиза.

---

## 6. Создавать архивы

> 🤖 **Рабочие сборки** обрабатываются с помощью GitHub Actions (`.github/workflows/build-release.yml`) при публикации релиза. Ресурсы добавляются автоматически.

**Для локальных сборок:**

```bash
make new
make lb
make main
```

После построения `dist/` должно содержать:

|Файл|Описание|
| --- | --- |
| `XC_VM.zip` |ОСНОВНОЙ установщик (установить скрипт + xc_vm.tar.gz)|
| `xc_vm.tar.gz` |ОСНОВНОЙ архив (установка и обновление)|
| `loadbalancer.tar.gz` |Архив LB (установка и обновление)|
| `hashes.md5` |Контрольные суммы MD5|

> Один и тот же архив используется как для чистой установки, так и для обновлений.
> Скрипт обновления (`src/update`) отфильтровывает двоичные/конфигурационные каталоги во время выполнения, используя жестко заданный список `UPDATE_EXCLUDE_DIRS` внутри самого скрипта Python.

**Проверка целостности:**

```bash
cd dist && md5sum -c hashes.md5
```

**Тестовая установка Docker** (см. `tools/test-install/`) — только после сборки, поскольку для этого требуется `dist/XC_VM.zip`:

```bash
bash tools/test-install/test_release.sh
```

При этом создается образ, контейнер запускается с systemd и программа установки запускается автоматически.
`dist/XC_VM.zip` монтируется в контейнер как том, доступный только для чтения.

> ✅ Убедитесь, что панель загружается при `http://localhost:8880` и работает вход в систему администратора.

---

## 7. Релиз на GitHub

1. Перейти к [Релизам на GitHub](https://github.com/Vateron-Media/XC_VM/releases)
2. Создайте новый релиз с тегом, указанным на первом шаге
3. Вставьте список изменений в качестве описания выпуска
4. Публиковать ** без прикрепления файлов ** — действия GitHub создадут и прикрепят их

После публикации рабочий процесс будет автоматически запущен:

- Собрать все архивы + контрольные суммы
- Прикрепите их к фиксатору
- Отправьте уведомление в Telegram через `release-notifier.yml`

> ✅ Дождитесь завершения рабочего процесса действий, затем убедитесь, что все файлы доступны для загрузки.

---

## 8. После выпуска

- [ ] Убедитесь, что все 4 ресурса присоединены к релизу
- [ ] Выполнить `md5sum -c hashes.md5` для загруженных файлов
- [ ] Проверьте, отправлено ли уведомление Telegram
- [ ] Тесно связанные с GitHub проблемы / вехи

---

## Ссылка на команду

Все целевые объекты `make`, использованные во время подготовки релиза, в одном месте.

**Проверка качества** — сначала запустите `make dev-tools`, затем `make dev-clean`, когда закончите:

|Команда|Цель|
| --- | --- |
| `make dev-tools` |Установите инструменты разработки (PHPStan, phpcs) через `composer install`|
| `make phpstan` |Статический анализ (также выявляет синтаксические ошибки)|
| `make phpstan-baseline` |Восстановите базовую линию PHPStan|
| `make cs` |Проверка стиля кода - импорт/гигиена пространства имен (phpcs + Slevomat)|
| `make cs-fix` |Примените исправления в стиле кода на месте|
| `make gates` |PSR-4 регрессионные параметры (для использования в процедурных целях, LB-архив, только для продукта поставщика)|
| `make dev-clean` |Снова удалите инструменты разработки, восстановив только производственную версию `vendor/`.|
| `php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist` |Модульные тесты|

**Подготовка к выпуску и сборка:**

|Команда|Цель|
| --- | --- |
| `make generate_deleted_files` |Регенерировать `src/migrations/deleted_files.txt`|
| `make new` |Сброс выходного каталога `dist/` (запуск перед сборкой)|
| `make lb` |Создайте архив LoadBalancer в виде `dist/`|
| `make main` |Соберите ОСНОВНОЙ архив в `dist/`|
| `bash tools/test-install/test_release.sh` |Установочный тест Docker для встроенного выпуска|

**Documentation** (English source in `docs/en`; `docs/ru` is generated + committed):

|Команда|Цель|
| --- | --- |
| `make docs-venv` |Одноразовый: локальный venv (сборка + переводы)|
| `make docs-translate` |Восстановить `docs/ru` из `docs/en` (перед выпуском)|
| `make docs-build` |Строгая сборка MkDocs в `./build/site` (что запускает CI)|
| `make docs-serve` |Предварительный просмотр документов в режиме реального времени на `http://127.0.0.1:8000`|
