# Navbar rendering in module panel

Technical documentation for building and rendering the navbar in the admin panel.

## Purpose

The navbar is built declaratively from a `NavbarItem` tree, not from hardcoded HTML menus.

Tree sources:

1. Core nodes from `CoreNavbarProvider::register()`.
2. Module nodes from `ModuleInterface::registerNavbar()`.

## Lifecycle

1. `ModuleLoader::bootAll()` calls `CoreNavbarProvider::register()`.
2. Then `registerNavbar()` is called for each loaded module.
3. In `Public/Views/admin/header.php`, the tree is rendered from `NavbarRegistry`.

## Rendering in header

Rendering is done by helper functions:

1. `_xc_nav_visible()` - visibility filtering for a node.
2. `_xc_nav_label()` - text label resolution.
3. `_xc_nav_children()` - recursive rendering of child items.

Top-level nodes come from `NavbarRegistry::getTopLevel()`, child nodes come from `NavbarRegistry::getChildren($key)`.

## Visibility rules

Checks are performed in `_xc_nav_visible()`:

1. `desktopOnly`: hides node on mobile.
2. `settingDisabled`: hides node when a setting flag is enabled.
3. `permissions`: OR-check via `Authorization::check('adv', $permission)`.
4. A group with `url='#'` is shown only if at least one child is visible.
5. `divider` is always passed through and rendered as separator.

## Rendering specifics

1. `divider` renders as separator without a link.
2. `submenuClass('megamenu')` enables two-column rendering for long lists.
3. `noMobileSubmenu` disables child submenu expansion on mobile.

## How a module adds a menu item

A module adds items only through `registerNavbar()`:

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

## Practical rules for modules

1. Use unique `key` values in `section.group.item` format.
2. Set `parent` to an existing core tree node or your own already-added node.
3. Position items using `order` inside one parent.
4. Use `label('translation_key')` for translatable text.
5. Use `label('', 'Literal Text')` for fixed literal text.
6. If the module has no menu items, keep `registerNavbar()` empty.

## Related files

| File | Role |
| --- | --- |
| `src/Core/Module/NavbarRegistry.php` | Collects navbar items from providers |
| `src/Core/Module/NavbarItem.php` | Navbar item value object |
| `src/Core/Module/CoreNavbarProvider.php` | Built-in core menu items |
| `src/Public/Views/admin/header.php` | Renders the navbar tree |
