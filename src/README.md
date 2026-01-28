# Техническая документация - Напоминание об обновлении WhatsApp

## 🇷🇺 РУССКАЯ ВЕРСИЯ

### Обзор

Были реализованы две основные функции:

1. **Напоминание об обновлении WhatsApp** - Кнопка в таблице линий для отправки напоминаний об обновлении через WhatsApp.
2. **Исправление сортировки букетов** - Таблица букетов в области реселлера теперь отсортирована по ID (как в админке).

---

### Функция 1: Напоминание об обновлении WhatsApp

#### Что было изменено?

**A) Изменение метки: "Contact Email" → "WhatsApp"**

Метка была изменена в формах создания линии, но поле базы данных `contact` остается неизменным.

**Затронутые файлы:**
- `line.php` (Admin) - Line ~190
- `linereseller.php` (Reseller) - Line ~244

```php
// BEFORE:
'<label class="col-md-4 col-form-label" for="contact">Contact Email</label>'

// AFTER:
'<label class="col-md-4 col-form-label" for="contact">WhatsApp <i class="mdi mdi-whatsapp text-success"></i></label>'
```

---

**B) Расширен SQL-запрос - Добавлено поле `contact`**

Поле `contact` теперь загружается в запросе DataTables.

**Затронутые файлы:**
- `table.php` (Admin) - Line ~124
- `tablereseller.php` (Reseller) - Line ~118

```php
// BEFORE:
$rQuery = "SELECT `lines`.`id`, `lines`.`username`, `lines`.`password`, ...

// AFTER:
$rQuery = "SELECT `lines`.`id`, `lines`.`username`, `lines`.`password`, `lines`.`contact`, ...
```

---

**C) Кнопка WhatsApp в столбце действий**

В столбец действий добавлена новая зеленая кнопка.

**Затронутые файлы:**
- `table.php` (Admin) - Lines ~275-277, ~309-311
- `tablereseller.php` (Reseller) - Lines ~262-265

```php
// NULL-safe variable creation (prevents JavaScript errors with empty values)
$rWhatsAppContact = !empty($rRow["contact"]) ? addslashes($rRow["contact"]) : '';
$rWhatsAppExp = $rRow["exp_date"] ? $rRow["exp_date"] : 'null';

// Button HTML
$rButtons .= "<button type=\"button\" class=\"btn btn-success waves-effect waves-light btn-xs\" 
    onClick=\"openWhatsApp('" . addslashes($rRow["username"]) . "', '" . $rWhatsAppContact . "', " . $rWhatsAppExp . ");\" 
    data-toggle=\"tooltip\" data-placement=\"top\" data-original-title=\"WhatsApp Reminder\">
    <i class=\"mdi mdi-whatsapp\"></i>
</button> ";
```

---

**D) Модальное окно WhatsApp + JavaScript**

Модальное окно с выбором языка и автоматической генерацией сообщений.

**Затронутые файлы:**
- `lines.php` (Admin) - inserted before `</body>`
- `linesreseller.php` (Reseller) - inserted before `</body>`

**Как это работает:**

1. Вызывается `openWhatsApp(username, contact, expTimestamp)`
2. Проверяет, существует ли `contact` (показывает предупреждение, если нет)
3. Вычисляет дату истечения срока действия и оставшиеся дни из временной метки Unix
4. Открывает модальное окно с выбором языка (DE/EN/TR)
5. `updateWhatsAppMessage()` генерирует сообщение на основе выбранного языка
6. `sendWhatsApp()` открывает WhatsApp Web с отформатированным сообщением

**Шаблоны сообщений:**
- Немецкий: Формальное обращение на "Sie"
- Английский: Стандартный деловой английский
- Турецкий: Формальное обращение на "Sayın"

---

### Функция 2: Сортировка букетов (Reseller)

#### Проблема

В области реселлера таблица букетов на вкладке "Review Purchase" была отсортирована по **Названию букета** (столбец 1), а в области администратора - по **ID** (столбец 0).

#### Решение

**Затронутый файл:**
- `footerreseller.php` - Lines ~270 and ~616

```javascript
// BEFORE:
order: [[ 1, "asc" ]]  // Sort by Bouquet Name

// AFTER:
order: [[ 0, "asc" ]]  // Sort by ID
```

**Дополнительно в `linereseller.php`:**

Заголовок таблицы был изменен для отображения "ID":

```php
// BEFORE:
'<th class="text-center"></th>'  // Empty header

// AFTER:
'<th class="text-center">ID</th>'  // ID header like Admin
```

---

### Обзор файлов

| File | Folder | Changes |
|------|--------|---------|
| `line.php` | `/admin/` | Label "Contact Email" → "WhatsApp" |
| `linereseller.php` | `/reseller/` | Label + ID header in Review table |
| `lines.php` | `/admin/` | WhatsApp Modal + JavaScript |
| `linesreseller.php` | `/reseller/` | WhatsApp Modal + JavaScript |
| `table.php` | `/admin/` | SQL Query + WhatsApp Button |
| `tablereseller.php` | `/reseller/` | SQL Query + WhatsApp Button |
| `footerreseller.php` | `/reseller/` | Bouquet sorting `[1] → [0]` |

---

### Изменения в базе данных не требуются

Существующее поле `contact` в таблице `lines` продолжает использоваться. Его просто нужно заполнить номерами WhatsApp в международном формате (например, `+491234567890`).

---