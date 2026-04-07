#!/bin/bash
# auto_install.sh — автоматическая установка XC_VM в контейнере
# Подаёт ответы на интерактивные вопросы инсталлятора
set -e

INSTALL_DIR="/opt/xcvm-install"
cd "$INSTALL_DIR"

# Проверяем наличие XC_VM.zip
if [[ ! -f XC_VM.zip ]]; then
    echo "ERROR: XC_VM.zip not found in $INSTALL_DIR"
    exit 1
fi

echo "==> Распаковка XC_VM.zip..."
unzip -o XC_VM.zip

# Проверяем что инсталлятор и архив на месте
if [[ ! -f install ]]; then
    echo "ERROR: install script not found after unzip"
    exit 1
fi
if [[ ! -f xc_vm.tar.gz ]]; then
    echo "ERROR: xc_vm.tar.gz not found after unzip"
    exit 1
fi

echo "==> Запуск инсталлятора..."

# Ответы на интерактивные вопросы (чистая установка, /home/xc_vm/ не существует):
#   1) "MariaDB root password (or press Enter to generate):" — пустая строка (авто-генерация)
#   2) "HTTP port (default 80):" — пустая строка (default 80)
#   3) "HTTPS port (default 443):" — пустая строка (default 443)
#   4) "Overwrite sysctl configuration? Recommended! (Y / N):" — Y

printf '\n\n\nY\n' | python3 install 2>&1 | tee /var/log/xcvm_install.log
EXIT_CODE=${PIPESTATUS[1]}

echo ""
echo "========================================="
if [[ $EXIT_CODE -eq 0 ]]; then
    echo "  INSTALL COMPLETED (exit code: 0)"
else
    echo "  INSTALL FAILED (exit code: $EXIT_CODE)"
fi
echo "========================================="
echo ""

# Проверяем что ключевые файлы на месте
echo "==> Post-install verification:"
CHECKS=(
    "/home/xc_vm/console.php"
    "/home/xc_vm/console.php"
    "/home/xc_vm/autoload.php"
    "/home/xc_vm/bootstrap.php"
    "/home/xc_vm/config/config.ini"
    "/home/xc_vm/bin/nginx/sbin/nginx"
    "/home/xc_vm/bin/php/bin/php"
    "/home/xc_vm/bin/redis/redis-server"
)

PASS=0
FAIL=0
for f in "${CHECKS[@]}"; do
    if [[ -e "$f" ]]; then
        echo "  [OK]   $f"
        ((PASS++))
    else
        echo "  [FAIL] $f"
        ((FAIL++))
    fi
done

echo ""
echo "Results: $PASS passed, $FAIL failed out of ${#CHECKS[@]} checks"

# Проверяем сервисы (если systemd работает)
if command -v systemctl &>/dev/null && systemctl is-system-running &>/dev/null 2>&1; then
    echo ""
    echo "==> Service status:"
    systemctl status xc_vm --no-pager 2>/dev/null || echo "  xc_vm service not running (may need manual start)"
fi

exit $EXIT_CODE
