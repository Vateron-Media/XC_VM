<?php

namespace XcVm\Core\Module;

/**
 * NavbarItem — value object representing a single node in the admin nav tree.
 *
 * Fluent builder pattern:
 *   (new NavbarItem('management.service_setup.watch'))
 *       ->parent('management.service_setup')
 *       ->url('watch')
 *       ->label('folder_watch')
 *       ->permissions(['folder_watch'])
 *       ->order(60)
 *
 * Top-level items have parent === null.
 * Items with url === '#' are group headers (rendered as submenus).
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class NavbarItem {

    /** @var string Unique dot-separated key, e.g. 'management.service_setup.watch' */
    public $key;
    /** @var string|null Parent key, null for top-level items */
    public $parent = null;
    /** @var string Target URL or '#' for group headers */
    public $url = '#';
    /** @var string Translation key passed to $language::get() */
    public $translationKey = '';
    /** @var string Fallback text used when translationKey is empty */
    public $fallbackTitle = '';
    /** @var string[] Permission names; OR-checked; empty means no permission gate */
    public $permissions = [];
    /** @var int Sort order within the same parent level */
    public $order = 100;
    /** @var string CSS class(es) for the icon element, e.g. 'fas fa-server' */
    public $icon = '';
    /** @var bool When true, item is hidden on mobile */
    public $desktopOnly = false;
    /** @var bool When true, submenu is not rendered on mobile */
    public $noMobileSubmenu = false;
    /** @var string Extra CSS class on the <ul class="submenu"> element, e.g. 'megamenu' */
    public $submenuClass = '';
    /** @var string Settings key; when non-empty and settings[key] is truthy, item is hidden */
    public $settingDisabled = '';
    /** @var bool When true, renders as a visual divider (no link) */
    public $divider = false;

    /**
     * Create a new navbar item with the given unique key.
     *
     * @param string $key Unique dot-separated identifier, e.g. 'management.service_setup.watch'
     */
    public function __construct(string $key) {
        $this->key = $key;
    }

    /**
     * Set the parent item key for hierarchical navigation.
     *
     * @param string $parentKey Dot-separated key of the parent item
     * @return self
     */
    public function parent(string $parentKey): self {
        $this->parent = $parentKey;
        return $this;
    }

    /**
     * Set the target URL for this navigation item.
     *
     * Use '#' for group headers that expand to show child items.
     *
     * @param string $url Target URL or '#'
     * @return self
     */
    public function url(string $url): self {
        $this->url = $url;
        return $this;
    }

    /**
     * Set the display label for this navigation item.
     *
     * The label is resolved via $language::get() using the translation key.
     * If translation key is empty, the fallback text is used directly.
     *
     * @param string $translationKey Translation key for $language::get()
     * @param string $fallback       Literal fallback text when translationKey is empty
     * @return self
     */
    public function label(string $translationKey, string $fallback = ''): self {
        $this->translationKey = $translationKey;
        $this->fallbackTitle  = $fallback;
        return $this;
    }

    /**
     * Set required permissions for this navigation item.
     *
     * User must have at least one of the listed permissions to see this item.
     * Empty array means no permission check.
     *
     * @param string[] $permissions List of permission names
     * @return self
     */
    public function permissions(array $permissions): self {
        $this->permissions = $permissions;
        return $this;
    }

    /**
     * Set the sort order within the same parent level.
     *
     * Lower values appear first. Default is 100.
     *
     * @param int $order Sort order
     * @return self
     */
    public function order(int $order): self {
        $this->order = $order;
        return $this;
    }

    /**
     * Set the CSS icon class(es) for this navigation item.
     *
     * @param string $icon CSS classes, e.g. 'fas fa-server' or 'fe-activity'
     * @return self
     */
    public function icon(string $icon): self {
        $this->icon = $icon;
        return $this;
    }

    /**
     * Mark this item as desktop-only.
     *
     * When true, the item is hidden on mobile devices.
     *
     * @return self
     */
    public function desktopOnly(): self {
        $this->desktopOnly = true;
        return $this;
    }

    /**
     * Hide the submenu on mobile devices.
     *
     * When true, child items are not rendered in mobile view.
     *
     * @return self
     */
    public function noMobileSubmenu(): self {
        $this->noMobileSubmenu = true;
        return $this;
    }

    /**
     * Add an extra CSS class to the submenu <ul> element.
     *
     * Useful for mega menu layouts or custom styling.
     *
     * @param string $cls CSS class name, e.g. 'megamenu'
     * @return self
     */
    public function submenuClass(string $cls): self {
        $this->submenuClass = $cls;
        return $this;
    }

    /**
     * Conditionally hide this item based on a system setting.
     *
     * If the setting with the given key exists and evaluates to true,
     * this navigation item will not be displayed.
     *
     * @param string $key Setting key name
     * @return self
     */
    public function settingDisabled(string $key): self {
        $this->settingDisabled = $key;
        return $this;
    }

    /**
     * Render this item as a visual divider.
     *
     * Dividers are non-clickable elements that separate groups of items.
     *
     * @return self
     */
    public function makeDivider(): self {
        $this->divider = true;
        return $this;
    }
}
