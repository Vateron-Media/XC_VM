# Отображение навигационной панели на панели модуля

Техническая документация по созданию и рендерингу навигационной панели в админ-панели.

## Цель

Навигационная панель создается декларативно из дерева `NavbarItem`, а не из жестко запрограммированных HTML-меню.

Древовидные источники:

1. Основные узлы из `CoreNavbarProvider::register()`.
2. Узлы модуля из `ModuleInterface::registerNavbar()`.

## Жизненный цикл

1. `ModuleLoader::bootAll()` вызывает `CoreNavbarProvider::register()`.
2. Затем для каждого загруженного модуля вызывается `registerNavbar()`.
3. В `Public/Views/admin/header.php` дерево отображается из `NavbarRegistry`.

## Рендеринг в заголовке

Рендеринг выполняется вспомогательными функциями:

1. `_xc_nav_visible()` - фильтрация видимости для узла.
2. `_xc_nav_label()` - разрешение текстовой метки.
3. `_xc_nav_children()` - рекурсивный рендеринг дочерних элементов.

Узлы верхнего уровня берутся из `NavbarRegistry::getTopLevel()`, дочерние узлы берутся из `NavbarRegistry::getChildren($key)`.

## Правила видимости

Проверки выполняются в `_xc_nav_visible()`:

1. `desktopOnly`: скрывает узел на мобильном устройстве.
2. `settingDisabled`: скрывает узел, когда включен флаг настройки.
3. `permissions`: ИЛИ-проверить с помощью `Authorization::check('adv', $permission)`.
4. Группа с `url='#'` отображается только в том случае, если виден хотя бы один дочерний элемент.
5. `divider` всегда передается и отображается как разделитель.

## Особенности рендеринга

1. `divider` отображается как разделитель без ссылки.
2. `submenuClass('megamenu')` включает отображение длинных списков в два столбца.
3. `noMobileSubmenu` отключает расширение дочернего подменю на мобильном устройстве.

## Как модуль добавляет элемент прейскуранта

Модуль добавляет элементы только через `registerNavbar()`:

```php
public function registerNavbar(NavbarRegistry $registry): void {
    NavbarRegistry::add((new NavbarItem('management.service_setup.my_module'))
        ->parent('management.service_setup')
        ->url('my_module')
        ->label('my_module')
        ->permissions(['my_module'])
        ->order(60));

    NavbarRegistry::add((new NavbarItem('management.logs.my_module_log'))
        ->parent('management.logs')
        ->url('my_module_logs')
        ->label('', 'My Module Logs')
        ->permissions(['my_module'])
        ->order(170));
}
```

## API построителя навигационных элементов

`NavbarItem` — это объект с плавным значением (`src/Core/Module/NavbarItem.php`) - параметры цепочки отключены `new NavbarItem($key)`:

|Метод|Цель|
| --- | --- |
| `new NavbarItem($key)` |создайте узел; `$key` - это его уникальный идентификатор `section.group.item`|
| `->parent($parentKey)` |присоединение к существующему узлу (опустить для узла верхнего уровня)|
| `->url($url)` |целевой путь; `'#'` делает его не навигационным заголовком **группа**|
| `->label($key, $fallback = '')` |клавиша перевода или `('', 'Literal')` для фиксированного текста|
| `->icon($icon)` |значок CSS-класса для элемента|
| `->permissions([...])` |ИЛИ - список разрешающих ключей; узел скрыт, если только у пользователя нет такого ключа|
| `->order($n)` |позиция сортировки внутри родительского элемента|
| `->desktopOnly()` |спрятаться на мобильном телефоне|
| `->noMobileSubmenu()` |не открывайте подменю этого узла на мобильном устройстве|
| `->submenuClass('megamenu')` |рендеринг в два столбца для длинных дочерних списков|
| `->settingDisabled($settingKey)` |скройте узел, если этот флажок настройки панели соответствует действительности|
| `->makeDivider()` |визуализируйте этот узел как разделитель (без ссылки)|

### Узел группы и разделитель

```php
public function registerNavbar(NavbarRegistry $registry): void {
    // A group header (url('#')) — shown only if at least one child is visible
    NavbarRegistry::add((new NavbarItem('management.my_group'))
        ->parent('management')
        ->url('#')
        ->label('my_group')
        ->order(50));

    // A divider inside that group
    NavbarRegistry::add((new NavbarItem('management.my_group.sep1'))
        ->parent('management.my_group')
        ->makeDivider()
        ->order(55));
}
```

> `settingDisabled('some_setting')` скрывает узел всякий раз, когда эта настройка верна (задает функцию за переключателем). Видимость также равна **область просмотра**: проверка `permissions` OR выполняется для текущего пользователя через `Authorization::check('adv', …)`, поэтому администратор и реселлер могут видеть разные подмножества одного и того же дерева.

## Практические правила для модулей

1. Используйте уникальные значения `key` в формате `section.group.item`.
2. Установите значение `parent` для существующего основного узла дерева или вашего собственного, уже добавленного узла.
3. Расположите элементы, используя `order`, внутри одного родительского элемента.
4. Используйте `label('translation_key')` для перевода текста.
5. Используйте `label('', 'Literal Text')` для фиксированного буквального текста.
6. Если в модуле нет пунктов меню, оставьте `registerNavbar()` пустым.

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/Core/Module/NavbarRegistry.php` |Собирает элементы навигационной панели от поставщиков|
| `src/Core/Module/NavbarItem.php` |Объект значения элемента навигационной панели|
| `src/Core/Module/CoreNavbarProvider.php` |Встроенные основные пункты меню|
| `src/Public/Views/admin/header.php` |Визуализирует дерево навигационной панели|
