# Рендер navbar в панели модулей

Техническая документация по формированию и рендерингу navbar в админ-панели.

## Назначение

Navbar строится декларативно из дерева `NavbarItem`, а не из hardcoded HTML-меню.

Источник дерева:

1. Core-узлы из `CoreNavbarProvider::register()`.
2. Модульные узлы из `ModuleInterface::registerNavbar()`.

## Жизненный цикл

1. `ModuleLoader::bootAll()` вызывает `CoreNavbarProvider::register()`.
2. Затем для каждого загруженного модуля вызывается `registerNavbar()`.
3. В `Public/Views/admin/header.php` дерево рендерится из `NavbarRegistry`.

## Рендер в header

Рендер выполняется helper-функциями:

1. `_xc_nav_visible()` — фильтрация видимости узла.
2. `_xc_nav_label()` — получение текстовой подписи.
3. `_xc_nav_children()` — рекурсивный вывод дочерних пунктов.

Верхний уровень берётся через `NavbarRegistry::getTopLevel()`, дочерние узлы — через `NavbarRegistry::getChildren($key)`.

## Правила видимости

Проверки выполняются в `_xc_nav_visible()`:

1. `desktopOnly`: скрывает узел на мобильных.
2. `settingDisabled`: скрывает узел при включённом settings-флаге.
3. `permissions`: OR-проверка через `Authorization::check('adv', $permission)`.
4. Группа с `url='#'` показывается только если есть хотя бы один видимый потомок.
5. `divider` всегда пропускается в рендер как разделитель.

## Особенности рендера

1. `divider` выводится как разделитель без ссылки.
2. `submenuClass('megamenu')` включает двухколоночный вывод длинных списков.
3. `noMobileSubmenu` выключает раскрытие дочернего меню на мобильных.

## Как модулю добавить кнопку

Модуль добавляет пункты только через `registerNavbar()`:

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

1. Используйте уникальные `key` в формате `section.group.item`.
2. Указывайте существующий `parent` из core-дерева или своего уже добавленного узла.
3. Позиционируйте пункты через `order` внутри одного parent.
4. Используйте `label('translation_key')` для переводимых строк.
5. Используйте `label('', 'Literal Text')` для фиксированного текста.
6. Если модуль не добавляет меню, оставляйте `registerNavbar()` пустым.

## Связанные файлы

| Файл | Роль |
| --- | --- |
| `src/Core/Module/NavbarRegistry.php` | Собирает пункты navbar от провайдеров |
| `src/Core/Module/NavbarItem.php` | Value-объект пункта navbar |
| `src/Core/Module/CoreNavbarProvider.php` | Встроенные пункты меню ядра |
| `src/Public/Views/admin/header.php` | Рендерит дерево navbar |
