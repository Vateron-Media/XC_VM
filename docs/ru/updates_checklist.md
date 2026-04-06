# ✅ Чеклист подготовки релиза XC_VM

<p align="center">
  Пошаговое руководство по подготовке и публикации релиза <b>XC_VM</b>.
</p>

---

## 📚 Навигация

- [🔢 1. Обновить версию](#1-обновить-версию)
- [🧹 2. Удалённые файлы (автоматически)](#2-удалённые-файлы-автоматически)
- [🧪 3. Предрелизная проверка](#3-предрелизная-проверка)
- [⚙️ 4. Сборка архивов](#4-сборка-архивов)
- [📝 5. Changelog](#5-changelog)
- [🚀 6. GitHub релиз](#6-github-релиз)
- [📢 7. После релиза](#7-после-релиза)

---

## 🔢 1. Обновить версию

Изменить константу версии в:

```
src/core/Config/AppConfig.php
```

**Быстрая команда:**

```bash
sed -i "s/define('XC_VM_VERSION', *'[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', 'X.Y.Z');/" src/core/Config/AppConfig.php
```

**Закоммитить:**

```bash
git add src/core/Config/AppConfig.php
git commit -m "Bump version to X.Y.Z"
git push
```

> 💡 Замените `X.Y.Z` на актуальную версию.

---

## 🧹 2. Удалённые файлы (автоматически)

Очистка файлов **полностью автоматизирована**. Ручных шагов не требуется.

**Как это работает:**
1. `make main_update` / `make lb_update` внутри вызывает `make delete_files_list`
2. Генерируется `dist/migrations/deleted_files.txt` (diff удалённых файлов с последнего тега)
3. Файл упаковывается в архив обновления как `migrations/deleted_files.txt`
4. При `php console.php update post-update` вызывается `MigrationRunner::runFileCleanup()`, который автоматически удаляет перечисленные файлы

> ⚠️ **Только проверка:** после сборки просмотрите `dist/migrations/deleted_files.txt` — убедитесь, что в списке нет критичных файлов.

---

## 🧪 3. Предрелизная проверка

Перед публикацией убедитесь, что сборка работает:

**Проверка синтаксиса PHP:**

```bash
make syntax_check
```

**Тестовая установка в Docker** (см. `tools/test-install/`):

```bash
cd tools/test-install
docker compose up -d --build
docker exec -it xc_test bash /opt/auto_install.sh
```

> ✅ Убедитесь, что панель открывается по `http://localhost:8880` и вход в админку работает.

**Security-сканирование** (запускается автоматически при push через `.github/workflows/security-scan.yml`):

```bash
tools/php_syntax_check.sh
tools/run_scan.sh
```

---

## ⚙️ 4. Сборка архивов

> 🤖 **Production-сборки** выполняются через GitHub Actions (`.github/workflows/build-release.yml`) при публикации релиза. Файлы прикрепляются автоматически.

**Для локальной сборки:**

```bash
make new
make lb
make main
make main_update
make lb_update
```

После сборки в `dist/` должны быть:

| Файл | Описание |
|------|----------|
| `XC_VM.zip` | Установочный архив MAIN |
| `update.tar.gz` | Архив обновления MAIN |
| `loadbalancer.tar.gz` | Установочный архив LB |
| `loadbalancer_update.tar.gz` | Архив обновления LB |
| `hashes.md5` | Контрольные суммы MD5 |

**Проверка целостности:**

```bash
cd dist && md5sum -c hashes.md5
```

---

## 📝 5. Changelog

**Сгенерировать лог коммитов:**

```bash
PREV_TAG=$(curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest \
  | grep -Po '"tag_name":\s*"\K[^"]+')
echo "Предыдущий релиз: $PREV_TAG"
git log --pretty=format:"- %s (%h)" "$PREV_TAG"..main > dist/changes.md
```

**Обновить публичный changelog** по ссылке:
[XC_VM_Update/changelog.json](https://github.com/Vateron-Media/XC_VM_Update/blob/main/changelog.json)

```json
{
    "version": "X.Y.Z",
    "changes": [
      "Описание изменения 1",
      "Описание изменения 2"
    ]
}
```

> 💬 Описания должны быть краткими — фокус на пользовательских улучшениях и исправлениях.

---

## 🚀 6. GitHub релиз

1. Перейти на [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases)
2. Создать новый релиз с тегом `X.Y.Z`
3. Вставить changelog в описание релиза
4. Опубликовать **без прикрепления файлов** — GitHub Actions соберёт и прикрепит их

После публикации workflow автоматически:
- Соберёт все 4 архива + контрольные суммы
- Прикрепит их к релизу
- Отправит Telegram-уведомление через `release-notifier.yml`

> ✅ Дождитесь завершения Actions и проверьте, что все файлы доступны для скачивания.

---

## 📢 7. После релиза

- [ ] Проверить, что все 5 файлов прикреплены к релизу
- [ ] Скачать и проверить `md5sum -c hashes.md5`
- [ ] Убедиться, что Telegram-уведомление отправлено
- [ ] Обновить `changelog.json` в репозитории `XC_VM_Update` (если ещё не сделано)
- [ ] Закрыть связанные GitHub issues/milestones
