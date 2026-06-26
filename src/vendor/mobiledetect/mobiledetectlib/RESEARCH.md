# Research Methodology for Adding New Device Models

How to find new tablet (or phone) models from a vendor and add them to Mobile-Detect.

## Step 1 -- Identify new models

- Browse the vendor's website across regions to find new devices:
  - Samsung: samsung.com/us/tablets/, samsung.com/uk/tablets/, samsung.com/global/
  - Check both the main Catalog section and the Support section for model codes
- Use GSMArena (gsmarena.com) -- search for e.g. `samsung galaxy tab` and filter by announcement year. Each device page lists all SM-XXXX model variants (Wi-Fi, LTE, 5G, regional suffixes like N, B, F)
- Check SamMobile (sammobile.com) for Samsung-specific coverage
- Cross-reference with the existing regex in `src/MobileDetect.php` (search for `SamsungTablet`) and test fixtures in `tests/providers/vendors/Samsung.php` to identify what's missing

## Step 2 -- Find User-Agent strings

- Search UA databases: user-agents.net, whatismybrowser.com, deviceatlas.com, udger.com
- Search for `"SM-XXXX" user agent` on the web
- Check Samsung's developer docs: https://developer.samsung.com/internet/user-agent-string-format.html
- If real UAs aren't available (device too new), construct them using the standard Chrome pattern:
  ```
  Mozilla/5.0 (Linux; Android {version}; {model}) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{version}.0.0.0 Safari/537.36
  ```
  Use the Android version the device ships with (from GSMArena specs) and a Chrome version contemporary to the device's release date.

## Step 3 -- Add to codebase

- Add model numbers to the appropriate regex array in `src/MobileDetect.php` (e.g., `$tabletDevices['SamsungTablet']`)
- Add test fixture entries in `tests/providers/vendors/{Vendor}.php`
- Run `vendor/bin/phpunit -v -c tests/phpunit.xml` to verify

## Samsung model number conventions

- `SM-T###` -- Standard Galaxy Tab series
- `SM-X###` -- Newer Galaxy Tab S / Tab A series (2022+)
- `SM-P###` -- Galaxy Tab with S Pen (Tab S6 Lite, etc.)
- Suffixes: no suffix = Wi-Fi, `B` = global 5G, `N` = Korean, `U` = US carrier, `F` = regional LTE variant

## Note on User-Agent Reduction

Chrome 110+ began rolling out UA reduction, replacing the device model name with `K`. Samsung Browser v24+ also uses `K`. However, many devices and browsers still send the full model number. The library's detection depends on model-number matching, so we continue to add model patterns. Long-term, Client Hints (`Sec-CH-UA-Model`) support may be needed.
