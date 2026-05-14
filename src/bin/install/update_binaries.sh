#!/bin/bash
set -euo pipefail

OWNER="${1:-Vateron-Media}"
REPO="${2:-XC_VM_Binaries}"
TARGET_BIN_DIR="${3:-/home/xc_vm/bin}"
TARGET_RELEASE_TAG="${4:-}"
VERSION_FILE="${TARGET_BIN_DIR}/bin_version.json"

TMP_ROOT=""
EXTRACT_DIR=""
STAGE_DIR=""
BACKUP_DIR=""
ROLLBACK_REQUIRED=0
SERVICE_NAME="xc_vm"
SERVICE_STOPPED=0

INSTALLED_PATHS=()
BACKED_UP_COMPONENTS=()

is_binary_root() {
    local path="$1"
    [[ -d "$path/nginx" && -d "$path/nginx_rtmp" && -d "$path/php" ]]
}

rollback_update() {
    local i
    local dest

    for ((i=${#INSTALLED_PATHS[@]}-1; i>=0; i--)); do
        dest="${INSTALLED_PATHS[$i]}"
        if [[ -e "$TARGET_BIN_DIR/$dest" ]]; then
            rm -rf "$TARGET_BIN_DIR/$dest"
        fi
    done

    for dest in "${BACKED_UP_COMPONENTS[@]}"; do
        if [[ -e "$BACKUP_DIR/$dest" ]]; then
            if [[ -e "$TARGET_BIN_DIR/$dest" ]]; then
                rm -rf "$TARGET_BIN_DIR/$dest"
            fi
            mv "$BACKUP_DIR/$dest" "$TARGET_BIN_DIR/$dest"
        fi
    done
}

cleanup() {
    local exit_code="$1"

    if [[ "$exit_code" -ne 0 && "$ROLLBACK_REQUIRED" -eq 1 ]]; then
        echo "Update failed, rolling back binaries" >&2
        rollback_update
    fi

    if [[ -n "$TMP_ROOT" && -d "$TMP_ROOT" ]]; then
        rm -rf "$TMP_ROOT"
    fi

    if [[ "$SERVICE_STOPPED" -eq 1 ]]; then
        echo "Starting service: ${SERVICE_NAME}"
        if ! systemctl start "$SERVICE_NAME"; then
            echo "Warning: Failed to start service: ${SERVICE_NAME}" >&2
        fi
    fi
}

trap 'cleanup $?' EXIT

if [[ "$EUID" -ne 0 ]]; then
    echo "Please run as root!" >&2
    exit 1
fi

echo "Stopping service: ${SERVICE_NAME}"
if ! systemctl stop "$SERVICE_NAME"; then
    echo "Failed to stop service: ${SERVICE_NAME}" >&2
    exit 1
fi
SERVICE_STOPPED=1

TMP_ROOT="$(mktemp -d /tmp/xc_vm_bin_update.XXXXXX)"
EXTRACT_DIR="$TMP_ROOT/extract"
STAGE_DIR="$TMP_ROOT/stage"
BACKUP_DIR="$TMP_ROOT/backup"

mkdir -p "$EXTRACT_DIR" "$STAGE_DIR" "$BACKUP_DIR" "$TARGET_BIN_DIR"

echo "Starting binaries update"
echo "Working directories:"
echo "  TMP_ROOT: $TMP_ROOT"
echo "  EXTRACT_DIR: $EXTRACT_DIR"
echo "  STAGE_DIR: $STAGE_DIR"
echo "  BACKUP_DIR: $BACKUP_DIR"
echo "  TARGET_BIN_DIR: $TARGET_BIN_DIR"

DIST_ID=""
DIST_VERSION=""

if command -v lsb_release >/dev/null 2>&1; then
    DIST_ID="$(lsb_release -is 2>/dev/null || true)"
    DIST_ID="${DIST_ID,,}"
    DIST_VERSION="$(lsb_release -rs 2>/dev/null || true)"
fi

if [[ -z "$DIST_ID" || -z "$DIST_VERSION" ]] && [[ -r /etc/os-release ]]; then
    DIST_ID="${DIST_ID:-$(. /etc/os-release && echo "${ID,,}")}"
    DIST_VERSION="${DIST_VERSION:-$(. /etc/os-release && echo "$VERSION_ID")}"
fi

if [[ -z "$DIST_ID" || -z "$DIST_VERSION" ]]; then
    echo "Failed to detect Linux distribution" >&2
    exit 1
fi

echo "Detected distribution: ${DIST_ID} ${DIST_VERSION}"

DIST_MAJOR="${DIST_VERSION%%.*}"
ASSET_NAME=""

case "$DIST_ID" in
    ubuntu)
        case "$DIST_MAJOR" in
            18|20|22|24)
                ASSET_NAME="ubuntu_${DIST_MAJOR}.tar.gz"
                ;;
        esac
        ;;
    debian)
        case "$DIST_MAJOR" in
            11|12|13)
                ASSET_NAME="debian_${DIST_MAJOR}.tar.gz"
                ;;
        esac
        ;;
    rocky|almalinux|rhel|centos)
        case "$DIST_MAJOR" in
            8|9)
                ASSET_NAME="rhel_${DIST_MAJOR}.tar.gz"
                ;;
        esac
        ;;
esac

if [[ -z "$ASSET_NAME" ]]; then
    echo "Unsupported distribution for binaries: $DIST_ID $DIST_VERSION" >&2
    exit 1
fi

echo "Selected release asset: ${ASSET_NAME}"

API_URL="https://api.github.com/repos/${OWNER}/${REPO}/releases/latest"
RELEASE_TAG="$TARGET_RELEASE_TAG"

if [[ -z "$RELEASE_TAG" ]]; then
    API_RESPONSE="$(curl -fsSL --connect-timeout 5 --max-time 20 \
        -H "Accept: application/vnd.github+json" \
        -H "User-Agent: XC_VM-Binaries-Updater" \
        "$API_URL" || true)"

    RELEASE_TAG="$(printf '%s\n' "$API_RESPONSE" | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
    RELEASE_TAG="$(printf '%s\n' "$RELEASE_TAG" | awk 'NR == 1 { print; exit }')"

    if [[ -z "$RELEASE_TAG" ]]; then
        REDIRECT_URL="$(curl -fsSI --connect-timeout 5 --max-time 20 \
            "https://github.com/${OWNER}/${REPO}/releases/latest" \
            | awk 'tolower($1) == "location:" { gsub(/\r/, "", $2); last = $2 } END { print last }')"
        RELEASE_TAG="${REDIRECT_URL##*/}"
    fi
fi

if [[ -z "$RELEASE_TAG" || "$RELEASE_TAG" == "latest" ]]; then
    echo "Failed to resolve latest release tag for ${OWNER}/${REPO}" >&2
    exit 1
fi

echo "Resolved release tag: ${RELEASE_TAG}"

ARCHIVE_URL="https://github.com/${OWNER}/${REPO}/releases/download/${RELEASE_TAG}/${ASSET_NAME}"
ARCHIVE_PATH="$TMP_ROOT/${ASSET_NAME}"

echo "Downloading ${ASSET_NAME} from release ${RELEASE_TAG}"
echo "Download URL: ${ARCHIVE_URL}"
echo "Archive path: ${ARCHIVE_PATH}"
curl -fL --connect-timeout 10 --max-time 900 -o "$ARCHIVE_PATH" "$ARCHIVE_URL"

HASHES_URL="https://github.com/${OWNER}/${REPO}/releases/download/${RELEASE_TAG}/hashes.md5"
HASHES_CONTENT="$(curl -fsSL --connect-timeout 5 --max-time 30 "$HASHES_URL" || true)"
EXPECTED_HASH=""

if [[ -n "$HASHES_CONTENT" ]]; then
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue

        hash_value="$(printf '%s\n' "$line" | awk '{print $1}')"
        file_name="$(printf '%s\n' "$line" | awk '{print $2}' | sed 's#^\*##; s#^\./##')"

        if [[ "$file_name" == "$ASSET_NAME" ]]; then
            EXPECTED_HASH="$hash_value"
            break
        fi
    done <<< "$HASHES_CONTENT"
fi

echo "Verifying archive integrity"
if [[ -n "$EXPECTED_HASH" ]]; then
    ACTUAL_HASH="$(md5sum "$ARCHIVE_PATH" | awk '{print $1}')"
    if [[ "$ACTUAL_HASH" != "$EXPECTED_HASH" ]]; then
        echo "MD5 verification failed for ${ASSET_NAME}" >&2
        echo "Expected: ${EXPECTED_HASH}" >&2
        echo "Actual:   ${ACTUAL_HASH}" >&2
        exit 1
    fi
    echo "MD5 verification passed for ${ASSET_NAME}"
else
    echo "Warning: hashes.md5 entry not found for ${ASSET_NAME}, skipping MD5 check"
fi

echo "Extracting archive"
echo "Extract source: ${ARCHIVE_PATH}"
echo "Extract destination: ${EXTRACT_DIR}"
tar -xzf "$ARCHIVE_PATH" -C "$EXTRACT_DIR"

SOURCE_ROOT=""
POSSIBLE_PATHS=()
DISTRO_VARIANTS=("${DIST_ID}${DIST_MAJOR}" "${DIST_ID}_${DIST_MAJOR}")

for dname in "${DISTRO_VARIANTS[@]}"; do
    POSSIBLE_PATHS+=("$EXTRACT_DIR/$dname/bin")
    POSSIBLE_PATHS+=("$EXTRACT_DIR/$dname")
done

POSSIBLE_PATHS+=("$EXTRACT_DIR/bin")
POSSIBLE_PATHS+=("$EXTRACT_DIR")

for candidate in "${POSSIBLE_PATHS[@]}"; do
    [[ -d "$candidate" ]] || continue

    if is_binary_root "$candidate"; then
        SOURCE_ROOT="$candidate"
        echo "Structure found: ${SOURCE_ROOT}"
        break
    fi

    if is_binary_root "$candidate/bin"; then
        SOURCE_ROOT="$candidate/bin"
        echo "Structure found: ${SOURCE_ROOT}"
        break
    fi
done

if [[ -z "$SOURCE_ROOT" ]]; then
    echo "Searching directory structure recursively..."
    while IFS= read -r root; do
        if is_binary_root "$root"; then
            SOURCE_ROOT="$root"
            echo "Structure found recursively: ${SOURCE_ROOT}"
            break
        fi

        if is_binary_root "$root/bin"; then
            SOURCE_ROOT="$root/bin"
            echo "Structure found recursively: ${SOURCE_ROOT}"
            break
        fi
    done < <(find "$EXTRACT_DIR" -type d 2>/dev/null)
fi

if [[ -z "$SOURCE_ROOT" ]]; then
    echo "Could not locate binary directories inside ${ASSET_NAME}" >&2
    exit 1
fi

echo "Using source root: ${SOURCE_ROOT}"
echo "Staging binaries into: ${STAGE_DIR}"

NETWORK_SOURCE=""
NETWORK_DEST=""

if [[ -d "$SOURCE_ROOT/network" ]]; then
    NETWORK_SOURCE="$SOURCE_ROOT/network"
    NETWORK_DEST="network"
elif [[ -f "$SOURCE_ROOT/network.py" ]]; then
    NETWORK_SOURCE="$SOURCE_ROOT/network.py"
    NETWORK_DEST="network.py"
elif [[ -f "$SOURCE_ROOT/network" ]]; then
    NETWORK_SOURCE="$SOURCE_ROOT/network"
    NETWORK_DEST="network"
fi

if [[ -z "$NETWORK_SOURCE" ]]; then
    echo "Network component is missing in ${ASSET_NAME}" >&2
    exit 1
fi

cp -a "$SOURCE_ROOT/nginx" "$STAGE_DIR/nginx"
cp -a "$SOURCE_ROOT/nginx_rtmp" "$STAGE_DIR/nginx_rtmp"
cp -a "$SOURCE_ROOT/php" "$STAGE_DIR/php"
cp -a "$NETWORK_SOURCE" "$STAGE_DIR/$NETWORK_DEST"

install_component_merge() {
    local component="$1"
    local target_path="$TARGET_BIN_DIR/$component"
    local stage_path="$STAGE_DIR/$component"

    echo "Installing component: ${component} -> ${target_path}"
    echo "Updating in merge mode (preserve local configs): ${target_path}"

    if [[ ! -d "$stage_path" ]]; then
        echo "Stage directory is missing for component: ${component}" >&2
        exit 1
    fi

    if [[ -e "$target_path" && ! -d "$target_path" ]]; then
        echo "Target path exists but is not a directory: ${target_path}" >&2
        exit 1
    fi

    if [[ -d "$target_path" ]]; then
        echo "Backing up existing component for rollback: ${target_path} -> ${BACKUP_DIR}/${component}"
        cp -a "$target_path" "$BACKUP_DIR/$component"
        BACKED_UP_COMPONENTS+=("$component")
    else
        mkdir -p "$target_path"
        INSTALLED_PATHS+=("$component")
    fi

    cp -a "$stage_path/." "$target_path/"
}

install_component_replace() {
    local src_name="$1"
    local dst_name="$2"
    local target_path="$TARGET_BIN_DIR/$dst_name"
    local stage_path="$STAGE_DIR/$src_name"

    echo "Installing component: ${src_name} -> ${target_path}"

    if [[ ! -e "$stage_path" ]]; then
        echo "Stage path is missing for component: ${src_name}" >&2
        exit 1
    fi

    if [[ -e "$target_path" ]]; then
        echo "Backing up existing component: ${target_path} -> ${BACKUP_DIR}/${dst_name}"
        mv "$target_path" "$BACKUP_DIR/$dst_name"
        BACKED_UP_COMPONENTS+=("$dst_name")
    else
        INSTALLED_PATHS+=("$dst_name")
    fi

    mv "$stage_path" "$target_path"
}

ROLLBACK_REQUIRED=1

install_component_merge "nginx"
install_component_merge "nginx_rtmp"
install_component_merge "php"
install_component_replace "$NETWORK_DEST" "$NETWORK_DEST"

if [[ -f "$TARGET_BIN_DIR/php/bin/php" ]]; then
    chmod 0551 "$TARGET_BIN_DIR/php/bin/php"
fi

if [[ -f "$TARGET_BIN_DIR/php/sbin/php-fpm" ]]; then
    chmod 0551 "$TARGET_BIN_DIR/php/sbin/php-fpm"
fi

if [[ -f "$TARGET_BIN_DIR/nginx/sbin/nginx" ]]; then
    chmod 0550 "$TARGET_BIN_DIR/nginx/sbin/nginx"
fi

if [[ -f "$TARGET_BIN_DIR/nginx_rtmp/sbin/nginx_rtmp" ]]; then
    chmod 0750 "$TARGET_BIN_DIR/nginx_rtmp/sbin/nginx_rtmp"
fi

if [[ -f "$TARGET_BIN_DIR/network" ]]; then
    chmod 0750 "$TARGET_BIN_DIR/network"
fi

if [[ -f "$TARGET_BIN_DIR/network.py" ]]; then
    chmod 0750 "$TARGET_BIN_DIR/network.py"
fi

UPDATED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
cat > "$VERSION_FILE" <<EOF
{
    "owner": "${OWNER}",
    "repository": "${REPO}",
    "release": "${RELEASE_TAG}",
    "asset": "${ASSET_NAME}",
    "distribution": "${DIST_ID}",
    "distribution_version": "${DIST_VERSION}",
    "updated_at_utc": "${UPDATED_AT}"
}
EOF

sudo chown xc_vm:xc_vm -R /home/xc_vm >/dev/null 2>&1

ROLLBACK_REQUIRED=0
echo "Binaries updated successfully. Version file: ${VERSION_FILE}"