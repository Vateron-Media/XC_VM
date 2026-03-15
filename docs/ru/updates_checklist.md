# ✅ Чеклист подготовки релиза XC_VM

<p align="center">
  В этом документе описан процесс создания релиза <b>XC_VM</b> — пошаговое руководство для разработчиков по обновлению версии, сборке архивов и публикации на GitHub.
</p>

---

## 📚 Навигация

- [🔢 1. Обновить версию](#1-обновить-версию)
- [🧹 2. Удалённые файлы](#2-удалённые-файлы)
- [⚙️ 3. Сборка архивов](#3-сборка-архивов)
- [📝 4. Changelog](#4-changelog)
- [🚀 5. GitHub релиз](#5-github-релиз)

---

## 🔢 1. Обновить версию

* Установить **новое значение `XC_VM_VERSION`** в следующих файлах:

**Файлы для редактирования:**

```
src/core/Config/AppConfig.php
```

**Auto-update команда:**

```bash
find -type f -name "*.php" -exec sed -i \
"s/define('XC_VM_VERSION', '[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', 'X.Y.Z');/g" {} \;
```

**Закоммитить изменения с сообщением:**

```bash
git add .
git commit -m "Bump version to X.Y.Z"
git push
```

> 💡 **Совет:** Замените `X.Y.Z` на актуальную версию, например, `1.2.3`.

---

## 🧹 2. Удалённые файлы

* Выполнить команду для генерации списка удаленных файлов:

  ```bash
  make delete_files_list
  ```

* Открыть файл `dist/deleted_files.txt`.
* Скопировать содержимое в `src/cli/Commands/UpdateCommand.php` в массив `$rCleanupFiles` внутри фазы `post-update`.

> ⚠️ **Важно:** Убедитесь, что пути указаны корректно, чтобы избежать удаления важных файлов.

**Закоммитить изменения с сообщением:**

```bash
git add .
git commit -m "Added deletion of old files before release"
git push
```

---

## ⚙️ 3. Сборка архивов

> 🤖 **Автоматически:** Сборка выполняется через GitHub Actions workflow `.github/workflows/build-release.yml` при публикации релиза. Файлы автоматически прикрепляются к релизу.

При необходимости **локальной сборки** выполните команды:

```bash
make new
make lb
make main
make main_update
make lb_update
```

После сборки в директории `dist/` должны быть:

  - `loadbalancer.tar.gz` — установочный архив LB
  - `loadbalancer_update.tar.gz` — архив обновления LB
  - `XC_VM.zip` — установочный архив MAIN
  - `update.tar.gz` — архив обновления MAIN
  - `hashes.md5` — файл с хеш-суммами

---

## 📝 4. Changelog

Сначала сгенерируйте файл с изменениями из git:

```bash
git log --pretty=format:"- %s (%h)" X.Y.Z..main > dist/changes.md
```

*   **Перейдите по ссылке и добавьте изменения текущего релиза:**
    [https://github.com/Vateron-Media/XC_VM_Update/blob/main/changelog.json](https://github.com/Vateron-Media/XC_VM_Update/blob/main/changelog.json)

* Добавить изменения текущего релиза в формате JSON:

  ```json
  [
    {
        "version": "X.Y.Z",
        "changes": [
          "Описание изменения 1",
          "Описание изменения 2"
        ]
    }
  ]
  ```

> 💬 **Рекомендация:** Держите описания изменений краткими и информативными, фокусируясь на ключевых улучшениях и фиксах.

---

## 🚀 5. GitHub релиз

* Создать новый релиз на [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases) **без прикрепления файлов**.
* Указать changelog в описании релиза.
* После публикации GitHub Actions автоматически соберёт и прикрепит к релизу:

  - `loadbalancer.tar.gz` — установочный архив LB
  - `loadbalancer_update.tar.gz` — архив обновления LB
  - `XC_VM.zip` — установочный архив MAIN
  - `update.tar.gz` — архив обновления MAIN
  - `hashes.md5` — файл с хеш-суммами

> ✅ **Завершение:** Дождитесь завершения workflow (вкладка Actions) и проверьте, что все файлы доступны для скачивания и хеш-суммы совпадают.

---