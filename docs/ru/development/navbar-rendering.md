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
public function registerNavbar(): void {
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
