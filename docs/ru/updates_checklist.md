# ✅ Чеклист подготовки релиза XC_VM

Пошаговое руководство по подготовке и публикации релиза XC_VM.

---

## 1. Подготовить базу для релиза

Сначала завершите все рабочие изменения (feature/fix/docs) и убедитесь, что они уже в `main`.

Задайте переменную версии один раз и используйте её во всех командах ниже:

```bash
VERSION="X.Y.Z"
```

> ⚠️ Не делайте отдельный commit/push с bump версии на этом шаге.
> Иначе `dist/changes.md` будет включать лишние релизные коммиты, и вам придётся делать дополнительные правки.

---

## 🧹 2. Удалённые файлы

Перед сборкой сгенерируйте список файлов для удаления при обновлении:

```bash
make generate_deleted_files
```

Команда запускает `git diff` между `LAST_TAG` и `HEAD`, находит удалённые файлы под `src/`, убирает префикс `src/` и записывает результат в `src/migrations/deleted_files.txt`.

Если `LAST_TAG` не определяется автоматически (нет сети / нет релизов), передайте явно:

```bash
make generate_deleted_files LAST_TAG=1.2.16
```

**Проверьте сгенерированный файл** — убедитесь, что в списке нет критичных файлов:

```bash
cat src/migrations/deleted_files.txt
```

После валидации `make main` / `make lb` упакуют файл в архив через `delete_files_list` / `lb_delete_files_list`.

При `php console.php update post-update` вызывается `MigrationRunner::runFileCleanup()`, который автоматически удаляет перечисленные файлы.

> ⚠️ Строки, начинающиеся с `#`, являются комментариями и будут проигнорированы. Можно закомментировать файлы, которые нужно сохранить.

---

## 🧪 3. Предрелизная проверка

Перед публикацией убедитесь, что сборка работает:

**Проверка синтаксиса PHP:**

```bash
make syntax_check
```

**Тестовая установка в Docker** (см. `tools/test-install/`):

```bash
bash tools/test-install/test_release.sh
```

Скрипт собирает образ, запускает контейнер с systemd и автоматически выполняет установку.
`dist/XC_VM.zip` монтируется в контейнер как read-only volume.

> ✅ Убедитесь, что панель открывается по `http://localhost:8880` и вход в админку работает.

**Security-сканирование** (запускается автоматически при push через `.github/workflows/security-scan.yml`):

```bash
tools/php_syntax_check.sh
tools/run_scan.sh
```

---

## 📝 4. Changelog

**Сгенерировать лог коммитов (только рабочие изменения):**

```bash
PREV_TAG=$(git describe --tags --abbrev=0)
git log --pretty=format:"- %s (%h)" "$PREV_TAG"..main > dist/changes.md
```

**Обновить `changelog.json`** в корне репозитория — этот файл содержит только изменения для предстоящего релиза:

```json
{
    "version": "X.Y.Z",
    "changes": [
        "Описание изменения 1",
        "Описание изменения 2"
    ]
}
```

Панель получает этот файл из тега релиза автоматически через `GithubReleases::getChangelog()`.

> 💬 Описания должны быть краткими — фокус на пользовательских улучшениях и исправлениях.

---

## 🔢 5. Обновить версию и сделать единый release commit

Изменить константу версии и отключить режим разработки в:

```text
src/core/Config/AppConfig.php
```

**Быстрые команды:**

```bash
sed -i "s/define('DEVELOPMENT', true);/define('DEVELOPMENT', false);/" src/core/Config/AppConfig.php
sed -i "s/define('XC_VM_VERSION', *'[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', '${VERSION}');/" src/core/Config/AppConfig.php
```

**Сделать один финальный commit/push для релиза:**

```bash
git add src/core/Config/AppConfig.php changelog.json src/migrations/deleted_files.txt
git commit -m "Prepare release ${VERSION}"
git push
```

> ⚠️ Это устраняет необходимость в нескольких релизных коммитах.

---

## ⚙️ 6. Сборка архивов

> 🤖 **Production-сборки** выполняются через GitHub Actions (`.github/workflows/build-release.yml`) при публикации релиза. Файлы прикрепляются автоматически.

**Для локальной сборки:**

```bash
make new
make lb
make main
```

После сборки в `dist/` должны быть:

| Файл | Описание |
| --- | --- |
| `XC_VM.zip` | Установочный пакет MAIN (install скрипт + xc_vm.tar.gz) |
| `xc_vm.tar.gz` | Архив MAIN (установка и обновление) |
| `loadbalancer.tar.gz` | Архив LB (установка и обновление) |
| `hashes.md5` | Контрольные суммы MD5 |

> Один и тот же архив используется как для чистой установки, так и для обновлений.
> Скрипт обновления (`src/update`) исключает каталоги бинарников/конфигов во время выполнения по hardcoded-списку `UPDATE_EXCLUDE_DIRS` внутри самого Python-скрипта.

**Проверка целостности:**

```bash
cd dist && md5sum -c hashes.md5
```

---

## 🚀 7. GitHub релиз

1. Перейти на [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases)
2. Создать новый релиз с тегом из первого шага
3. Вставить changelog в описание релиза
4. Опубликовать **без прикрепления файлов** — GitHub Actions соберёт и прикрепит их

После публикации workflow автоматически:

- Соберёт все архивы + контрольные суммы
- Прикрепит их к релизу
- Отправит Telegram-уведомление через `release-notifier.yml`

> ✅ Дождитесь завершения Actions и проверьте, что все файлы доступны для скачивания.

---

## 📢 8. После релиза

- [ ] Проверить, что все 4 файла прикреплены к релизу
- [ ] Скачать и проверить `md5sum -c hashes.md5`
- [ ] Убедиться, что Telegram-уведомление отправлено
- [ ] Закрыть связанные GitHub issues/milestones
