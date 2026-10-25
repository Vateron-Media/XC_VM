# <a id="upgrading-php-to-83"></a>Обновление PHP до версии 8.3

PHP Срок службы версии 8.1 истек, и уязвимости, связанные с **оболочка php2**, не устраняются. Это руководство переводит время выполнения PHP каждого сервера в вашем кластере (основного и каждого из load balancer) на **PHP 8.3**, не дожидаясь выпуска панели.

Версия вашей панели, база данных и настройки не изменяются. Заменяются только пакет runtime (PHP и nginx) и расширение `xcvm_core`.

!!! информация "Панель управления 2.7.2 и новее"
Начиная с версии 2.7.2, MAIN самостоятельно переходит на PHP 8.3 при следующем обновлении двоичных файлов: программа обновления извлекает сборку PHP 8.3 из `xcvm_core` и указывает `php.ini` на загрузчик ionCube PHP 8.3, прежде чем остановить панель и оставить сервер без изменений, если ни того, ни другого нет. Устройство load balancer, обновленное до версии 2.7.2 или новее, делает то же самое в течение минуты после обновления панели, а устройство, установленное с такой панели, поставляется с версией PHP 8.3. На устройстве load balancer, которое осталось на более старой панели, запустите это руководство.

!!! опасность "Не пропускайте шаг 1"
Никогда не устанавливайте пакет PHP 8.3 на сервер, который не прошел этап 1. Без сборки PHP 8.3 из `xcvm_core` панель не запускается и не может восстановиться самостоятельно: для ее отката потребуется доступ по SSH.

**Если ваш основной канал с двоичными файлами является бета-версией** (Настройки → Обновления → Канал обновления — Двоичные файлы), установите его на **Стабильный** сейчас: в некоторых версиях панели бета-версия основной версии устанавливает пакет PHP 8.3 самостоятельно при следующем перезапуске, без шага 1.

---

## <a id="before-you-start"></a>Прежде чем Вы начнете

- **Панельная версия:** 2.5.0 или новее. Более старая панель имеет один канал обновления для панели и двоичных файлов: сначала обновите панель.
- **Поддерживаемые системы:** Ubuntu 20.04, 22.04, 24.04 и Debian 12, 13. Сборки PHP 8.3 для любой другой системы (Ubuntu 18, Debian 11, ...) не существует: сначала обновите операционную систему.
- **Доступ:** SSH как `root` для каждого сервера.
- **Диск:** около 1 ГБ бесплатно на `/` (`/root` и `/tmp`).
- **Простой:** 1-3 минуты на каждый сервер, пока выполняется замена пакета. После этого потоки, обслуживаемые этим сервером, снова подключаются.
- **Заказ:** Сначала основной, затем балансировщики нагрузки **по одному за раз**.

---

## <a id="step-1-check-and-prepare-every-server-no-downtime"></a>Шаг 1. Проверьте и подготовьте (каждый сервер, без простоев)

На этом этапе панель продолжает работать на PHP 8.1. Скрипт тестирует PHP 8.3 с помощью собственной панели `php.ini` и вашего сервера, а затем выполняет то, что требуется для PHP 8.3. В запущенной панели ничего не меняется.

Вставьте весь блок целиком в сеанс SSH:

```bash
bash <<'EOF'
set -euo pipefail
trap 'echo "STOP: step failed at line $LINENO"' ERR
export LC_ALL=C
B=/home/xc_vm/bin
PHP=$B/php/bin/php
INI=$B/php/lib/php.ini
EXT83=$B/php/lib/php/extensions/no-debug-non-zts-20230831
REL=https://github.com/Vateron-Media/XC_VM_Binaries/releases/download/21102026
CORE=https://raw.githubusercontent.com/Vateron-Media/XC_VM_Binaries/4021565ebcbd51357d25e0547d4c970c3fbf1a65/bin/xcvm_extention
W=/root/php83

[ "$(id -u)" = 0 ] || { echo "STOP: run as root"; exit 1; }
. /etc/os-release
A="${ID}_${VERSION_ID%%.*}.tar.gz"
case "$A" in
    ubuntu_20.tar.gz|ubuntu_22.tar.gz|ubuntu_24.tar.gz|debian_12.tar.gz|debian_13.tar.gz) ;;
    *) echo "STOP: there is no PHP 8.3 build for $PRETTY_NAME"; exit 1 ;;
esac
rm -rf "$W"; mkdir -p "$W/rt"

# xcvm_core built for PHP 8.3
curl -fsSL "$CORE/SHA256SUMS" -o "$W/SHA256SUMS"
curl -fsSL "$CORE/xcvm_core-php8.3.tar.gz" -o "$W/xcvm_core-php8.3.tar.gz"
(cd "$W" && grep ' xcvm_core-php8.3.tar.gz$' SHA256SUMS | sha256sum -c --quiet -)
tar -xzf "$W/xcvm_core-php8.3.tar.gz" -C "$W" ./xcvm_core.so

# PHP 8.3 runtime, unpacked aside for the test only
curl -fsSL --retry 3 -o "$W/$A" "$REL/$A"
(cd "$W" && curl -fsSL "$REL/hashes.md5" | grep " $A\$" | md5sum -c --quiet -)
tar -xzf "$W/$A" -C "$W/rt"
P83=$(find "$W/rt" -path '*/php/bin/php' -type f | head -1)
X83=$(dirname "$(dirname "$P83")")/lib/php/extensions/no-debug-non-zts-20230831
cp "$W/xcvm_core.so" "$X83/"
ln -sfn ioncube_loader_lin_8.3.so "$X83/ioncube_loader_lin_8.1.so"

# PHP 8.3 must load every module PHP 8.1 loads here, and read the panel's
# config and database exactly as PHP 8.1 does.
"$P83" -v > /dev/null 2>&1 || { echo "STOP: PHP 8.3 does not run on this server:"; ldd "$P83" | grep 'not found' || true; exit 1; }
"$PHP" -c "$INI" -m 2>/dev/null | sort -u > "$W/modules-8.1"
"$P83" -c "$INI" -d extension_dir="$X83" -m 2>/dev/null | sort -u > "$W/modules-8.3"
MISSING=$(comm -23 "$W/modules-8.1" "$W/modules-8.3")
[ -z "$MISSING" ] || { echo "STOP: PHP 8.3 lacks:" $MISSING; exit 1; }
PROBE='$r = ["core" => class_exists("XC_VM"), "ioncube" => extension_loaded("ionCube Loader")];
try { $c = XC_VM::config_server(); $r["config"] = is_array($c) ? array_keys($c) : gettype($c); } catch (Throwable $e) { $r["config"] = "error"; }
try { $r["db"] = XC_VM::db_connect(false) instanceof PDO; } catch (Throwable $e) { $r["db"] = "error"; }
echo json_encode($r);'
O81=$("$PHP" -c "$INI" -r "$PROBE" 2>/dev/null) || true
O83=$("$P83" -c "$INI" -d extension_dir="$X83" -r "$PROBE" 2>/dev/null) || true
echo "PHP 8.1: $O81"
echo "PHP 8.3: $O83"
[ -n "$O83" ] && [ "$O83" = "$O81" ] || { echo "STOP: PHP 8.3 does not see the panel as PHP 8.1 does"; exit 1; }

# Stage for the real update. Your php.ini loads ioncube_loader_lin_8.1.so,
# and so do the php.ini files that panel updates bring: under PHP 8.3 that
# name points to the 8.3 loader.
install -D -m 0555 "$W/xcvm_core.so" "$EXT83/xcvm_core.so"
ln -sfn ioncube_loader_lin_8.3.so "$EXT83/ioncube_loader_lin_8.1.so"
curl -fsSL https://raw.githubusercontent.com/Vateron-Media/XC_VM/main/src/bin/install/update_binaries.sh -o "$W/update_binaries.sh"
rm -rf "$W/rt" "$W/$A"
echo "READY"
EOF
```

- **`READY`**: переходите к следующему шагу.
- **`STOP: ...`**: не продолжать. Сервер не изменился и продолжает работать на PHP 8.1. Откройте [проблему](https://github.com/Vateron-Media/XC_VM/issues) с полным выводом.

---

## <a id="step-2-keep-the-binaries-channel-on-stable-main-only"></a>Шаг 2. Поддерживайте стабильный канал передачи двоичных файлов (только ОСНОВНОЙ).

В панели администратора откройте **Настройки → Обновления** и проверьте, что **Канал обновления — Двоичные файлы** равно **Стабильный** (значение по умолчанию). Если это не так, установите значение Стабильно и сохраните. Оставьте **Панель обновления канала** как есть.

Подсистемы балансировки нагрузки в этом не нуждаются: до версии 2.7.2 они никогда не обновляли свои двоичные файлы самостоятельно.

---

## <a id="step-3-install-php-83-every-server-main-first-then-each-load-balancer"></a>Шаг 3. Установите PHP 8.3 (каждый сервер: сначала главный, затем каждый load balancer).

Выполняйте это только на сервере, на шаге 1 которого было выведено `READY`:

```bash
nohup sh -c 'bash /root/php83/update_binaries.sh Vateron-Media XC_VM_Binaries /home/xc_vm/bin 21102026 && sed -i "s/\"release\": \"21102026\"/\"release\": \"29062026\"/" /home/xc_vm/bin/bin_version.json && { /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php root:own || true; }' > /root/php83/update.log 2>&1 &
tail -f /root/php83/update.log
```

Обновление продолжается, если ваш сеанс SSH прерывается. Оно заканчивается на `Binaries updated successfully` (панель старше 2.6.6 также выводит неизвестную командную строку для `root:own`, что безвредно); нажмите `Ctrl+C`, чтобы выйти из журнала. Если на этом пути произойдет какой-либо сбой, скрипт самостоятельно вернет предыдущие двоичные файлы обратно.

Команда записывает установленный пакет как `29062026`, текущую стабильную версию. Панель проверяет наличие новых двоичных файлов при каждом перезапуске, и выпущенные панели оценивают `29062026` как самый новый пакет на каждом канале: с записанным тегом real при следующем перезапуске будет возвращена версия PHP 8.1.

---

## <a id="step-4-verify"></a>Шаг 4. Проверьте

```bash
/home/xc_vm/bin/php/bin/php -r 'echo PHP_VERSION, " xcvm_core=", phpversion("xcvm_core"), " ioncube=", extension_loaded("ionCube Loader") ? "yes" : "no", "\n";'
systemctl is-active xc_vm
```

Ожидаемый результат: `8.3.x xcvm_core=<version> ioncube=yes`, затем `active`.

**Подождите две минуты и запустите его снова**: проверка перезапуска на наличие новых двоичных файлов выполняется в течение минуты, поэтому учитывается только второй запуск. Если результат равен `8.1.x`, снова выполните шаг 3 и откройте [проблему](https://github.com/Vateron-Media/XC_VM/issues) с помощью `/root/php83/update.log`.

Затем войдите в панель и прослушайте трансляцию, предоставляемую этим сервером. На ГЛАВНОЙ странице также откройте страницы установленных вами модулей.

---

## <a id="after-the-upgrade"></a>После обновления

- **Сохраняйте канал стабильных двоичных файлов на ГЛАВНОМ.** В бета-версии некоторые версии панели устанавливают бета-пакет при следующем перезапуске.
- **Обновления панели** работайте, как и раньше; после них ничего не нужно повторять.
- **A load balancer установлен позже** поставляется с PHP 8.3 с панели 2.7.2 или новее. Для установки с более старой панели необходимо выполнить шаги 1, 3 и 4.
