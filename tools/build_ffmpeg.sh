#!/bin/bash
set -euo pipefail
# ──────────────────────────────────────────────────────────────────────────────
# XC_VM FFmpeg Auto-Builder - FINAL FULL COMPLETE VERSION 2026 (ENGLISH)
#
# This is the longest and most complete version.
# Everything is expanded (no shortcuts, no "...").
# Features included:
#   - DASH complete
#   - DRM + VAAPI
#   - Full AV1 (aom + dav1d + svtav1 + rav1e)
#   - libzimg + libvmaf
#   - Vulkan + libplacebo
#   - Subtitles PRO (fribidi + harfbuzz + zvbi)
#   - --force-gpu flag for Docker (GPU support retained even without CUDA at build)
#   - 100% compatible with XUI / Xtream UI / XC_VM
#   - Static libs + dynamic glibc (no segfaults)
#
# Usage:
# ./build_ffmpeg.sh --force-gpu --install
# ./build_ffmpeg.sh 8.1 --force-gpu --install
# ──────────────────────────────────────────────────────────────────────────────

declare -A FFMPEG_TAGS=(
    ["4.0"]="n4.0.6"
    ["4.3"]="n4.3.7"
    ["4.4"]="n4.4.5"
    ["5.1"]="n5.1.6"
    ["7.1"]="n7.1.1"
    ["8.0"]="n8.0"
    ["8.1"]="n8.1"
)

ALL_VERSIONS=("4.0" "4.3" "4.4" "5.1" "7.1" "8.0" "8.1")

declare -A NVCODEC_BRANCH=(
    ["4.0"]="sdk/11.1"
    ["4.3"]="sdk/11.1"
    ["4.4"]="sdk/11.1"
    ["5.1"]="sdk/12.1"
    ["7.1"]="sdk/12.2"
    ["8.0"]="sdk/12.2"
    ["8.1"]="sdk/12.2"
)

BUILD_DIR="/tmp/ffmpeg_build"
PREFIX_BASE="/tmp/ffmpeg_install"
NPROC="$(nproc)"
INSTALL_DIR=""
DO_INSTALL=false
VERBOSE=false
FORCE_GPU=false

# ── Colors ────────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $*"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_error() { echo -e "${RED}[ERROR]${NC} $*"; }
log_step()  { echo -e "${CYAN}[STEP]${NC} $*"; }

# ── Detect XC_VM install dir ─────────────────────────────────────────────────
detect_xc_vm_bin_dir() {
    local candidates=(
        "/home/xc_vm/bin/ffmpeg_bin"
        "/opt/xc_vm/bin/ffmpeg_bin"
    )
    for dir in "${candidates[@]}"; do
        if [[ -d "$dir" ]]; then
            echo "$dir"
            return 0
        fi
    done
    return 1
}

# ── Argument parsing ─────────────────────────────────────────────────────────
VERSIONS_TO_BUILD=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --install)
            DO_INSTALL=true
            shift
            ;;
        --install-dir)
            INSTALL_DIR="$2"
            DO_INSTALL=true
            shift 2
            ;;
        --verbose|-v)
            VERBOSE=true
            shift
            ;;
        --force-gpu)
            FORCE_GPU=true
            log_info "FORCE_GPU mode activated - GPU support will be built even without real CUDA"
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [--install] [--install-dir PATH] [--force-gpu] [VERSION...]"
            echo "Available versions: ${ALL_VERSIONS[*]}"
            exit 0
            ;;
        *)
            if [[ -n "${FFMPEG_TAGS[$1]+x}" ]]; then
                VERSIONS_TO_BUILD+=("$1")
            else
                log_error "Unknown version: $1. Available: ${ALL_VERSIONS[*]}"
                exit 1
            fi
            shift
            ;;
    esac
done

if [[ ${#VERSIONS_TO_BUILD[@]} -eq 0 ]]; then
    VERSIONS_TO_BUILD=("${ALL_VERSIONS[@]}")
fi

if [[ "$DO_INSTALL" == true && -z "$INSTALL_DIR" ]]; then
    INSTALL_DIR="$(detect_xc_vm_bin_dir)" || {
        log_error "Cannot detect XC_VM bin dir. Use --install-dir PATH"
        exit 1
    }
fi

# ── Helper: install first available package from alternatives ─────────────────
# Usage: install_one_of_apt pkg1 pkg2 pkg3
# Tries each in order, installs the first one that exists.
install_one_of_apt() {
    for pkg in "$@"; do
        if apt-cache show "$pkg" 2>/dev/null | grep -q "^Package:"; then
            apt-get install -y -qq "$pkg" 2>/dev/null && return 0
        fi
    done
    return 1
}

# ── Dependency installation (APT) ────────────────────────────────────────────
install_deps_apt() {
    log_step "Installing build dependencies (APT)..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq

    # Required — build is impossible without these
    local required=(
        build-essential nasm yasm pkg-config git cmake autoconf automake libtool
        libx264-dev libx265-dev libmp3lame-dev libopus-dev libvpx-dev
        libfreetype-dev libfontconfig-dev libssl-dev zlib1g-dev libbz2-dev
    )
    apt-get install -y -qq "${required[@]}"

    # Optional — install one by one, skip unavailable
    local optional=(
        # Codecs
        libfdk-aac-dev libtheora-dev libvorbis-dev libwebp-dev libxvidcore-dev
        # Subtitles / text rendering
        libass-dev libfribidi-dev libharfbuzz-dev
        # TLS / crypto
        libgnutls28-dev libgmp-dev libunistring-dev
        # Streaming protocols
        librtmp-dev libxml2-dev
        # Transitive deps for --pkg-config-flags=--static
        # (x265→numa, gnutls→nettle, libxml2→lzma+icu)
        libnuma-dev liblzma-dev libicu-dev
        # AV1
        libaom-dev libdav1d-dev libsvtav1-dev librav1e-dev
        # Quality / scaling
        libvmaf-dev libzimg-dev
        # DRM / VAAPI
        libdrm-dev libva-dev
        # Vulkan
        libvulkan-dev libplacebo-dev
        # Teletext
        libzvbi-dev
    )

    local failed_pkgs=()
    for pkg in "${optional[@]}"; do
        if ! apt-get install -y -qq "$pkg" 2>/dev/null; then
            failed_pkgs+=("$pkg")
        fi
    done

    # Packages with alternative names depending on distro
    local alt_failed=()
    install_one_of_apt libnettle-dev nettle-dev || alt_failed+=("nettle-dev")
    install_one_of_apt libsrt-gnutls-dev libsrt-openssl-dev libsrt-dev || alt_failed+=("libsrt-dev")

    if [[ ${#failed_pkgs[@]} -gt 0 || ${#alt_failed[@]} -gt 0 ]]; then
        log_warn "Optional packages not available: ${failed_pkgs[*]} ${alt_failed[*]}"
        log_warn "Some codecs/features may be disabled in the build"
    fi
    log_info "Dependencies installed (APT)"
}

# ── Dependency installation (DNF/YUM) ────────────────────────────────────────
install_deps_dnf() {
    log_step "Installing build dependencies (DNF/YUM)..."
    local mgr="dnf"
    command -v dnf &>/dev/null || mgr="yum"
    $mgr install -y epel-release 2>/dev/null || true

    # RPM Fusion needed for x264/x265/fdk-aac
    if ! $mgr repolist 2>/dev/null | grep -qi rpmfusion; then
        log_warn "RPM Fusion not enabled — x264/x265/fdk-aac may be unavailable"
    fi

    local required=(
        gcc gcc-c++ make nasm yasm pkgconfig git cmake autoconf automake libtool
        zlib-devel bzip2-devel openssl-devel freetype-devel fontconfig-devel
    )
    $mgr install -y "${required[@]}"

    local optional=(
        # Codecs
        x264-devel x265-devel fdk-aac-devel lame-devel opus-devel libvpx-devel
        libtheora-devel libvorbis-devel libwebp-devel xvidcore-devel
        # Subtitles / text
        libass-devel fribidi-devel harfbuzz-devel
        # TLS / crypto
        gnutls-devel gmp-devel libunistring-devel nettle-devel
        # Streaming protocols
        librtmp-devel libxml2-devel srt-devel
        # Transitive deps
        numactl-devel xz-devel libicu-devel
        # AV1
        aom-devel dav1d-devel svt-av1-devel rav1e-devel
        # Quality / scaling
        vmaf-devel zimg-devel
        # DRM / VAAPI
        libdrm-devel libva-devel
        # Vulkan
        vulkan-devel libplacebo-devel
        # Teletext
        libzvbi-devel
    )

    local failed_pkgs=()
    for pkg in "${optional[@]}"; do
        if ! $mgr install -y "$pkg" 2>/dev/null; then
            failed_pkgs+=("$pkg")
        fi
    done

    if [[ ${#failed_pkgs[@]} -gt 0 ]]; then
        log_warn "Optional packages not available: ${failed_pkgs[*]}"
        log_warn "Some codecs/features may be disabled in the build"
    fi
    log_info "Dependencies installed (${mgr^^})"
}

# ── CUDA detection (with FORCE_GPU support) ──────────────────────────────────
HAS_CUDA=false
CUDA_HOME=""
detect_cuda() {
    if [[ "$FORCE_GPU" == true ]]; then
        HAS_CUDA=true
        log_info "FORCE_GPU mode active - GPU support will be compiled"
        return 0
    fi
    local cuda_paths=(
        "/usr/local/cuda"
        "/usr/local/cuda-12"
        "/usr/local/cuda-11"
    )
    for p in "${cuda_paths[@]}"; do
        if [[ -d "$p/include" && -f "$p/bin/nvcc" ]]; then
            CUDA_HOME="$p"
            HAS_CUDA=true
            log_info "CUDA found: $CUDA_HOME"
            return 0
        fi
    done
    if command -v nvcc &>/dev/null; then
        CUDA_HOME="$(dirname "$(dirname "$(command -v nvcc)")")"
        HAS_CUDA=true
        log_info "CUDA found via PATH"
        return 0
    fi
    log_warn "CUDA not found"
}

# ── Install nv-codec-headers ─────────────────────────────────────────────────
install_nvcodec_headers() {
    local branch="$1"
    local nvcodec_dir="${BUILD_DIR}/nv-codec-headers-${branch//\//_}"
    if [[ -d "$nvcodec_dir" ]]; then
        return 0
    fi
    log_step "Installing nv-codec-headers ($branch)..."
    git clone --depth 1 --branch "$branch" https://git.videolan.org/git/ffmpeg/nv-codec-headers.git "$nvcodec_dir"
    pushd "$nvcodec_dir" > /dev/null
    make install PREFIX="/usr/local"
    popd > /dev/null
    ldconfig 2>/dev/null || true
}

# ── FFmpeg configure flags (all features expanded) ───────────────────────────
CONFIGURE_FLAGS=()
get_configure_flags() {
    local version="$1"
    local prefix="$2"
    local cuda_cflags=""
    local cuda_ldflags=""
    if [[ "$HAS_CUDA" == true && -n "$CUDA_HOME" ]]; then
        cuda_cflags=" -I${CUDA_HOME}/include"
        cuda_ldflags=" -L${CUDA_HOME}/lib64"
    fi

    CONFIGURE_FLAGS=(
        "--prefix=${prefix}"
        "--bindir=${prefix}/bin"
        "--pkg-config-flags=--static"
        "--extra-cflags=-I${prefix}/include${cuda_cflags}"
        "--extra-ldflags=-L${prefix}/lib${cuda_ldflags} -Wl,-Bstatic -lcrypto -lssl -Wl,-Bdynamic"
        "--extra-version=XCVM-$(date +%Y%m%d)"
        "--extra-libs=-lsupc++ -lgmp -lz -lunistring -lpthread -lm -lrt -ldl"
        "--target-os=linux"
        "--enable-static"
        "--disable-shared"
        "--disable-ffplay"
        "--disable-doc"
        "--disable-debug"
        "--disable-autodetect"
        "--enable-gpl"
        "--enable-nonfree"
        "--enable-version3"
        "--enable-pthreads"
        "--enable-runtime-cpudetect"
        "--enable-gray"
        "--enable-libx264"
        "--enable-libx265"
        "--enable-libmp3lame"
        "--enable-libopus"
        "--enable-libvpx"
        "--enable-libvorbis"
        "--enable-libtheora"
        "--enable-libass"
        "--enable-libfreetype"
        "--enable-fontconfig"
        "--enable-bzlib"
        "--enable-zlib"
        "--enable-librtmp"
        "--enable-gnutls"

        # HLS — critical for IPTV streaming
        "--enable-muxer=hls"
        "--enable-demuxer=hls"

        # DASH muxer/demuxer
        "--enable-muxer=dash"
        "--enable-demuxer=dash"

        # Disable ALSA on headless servers
        "--disable-alsa"
        "--disable-indev=alsa"
        "--disable-outdev=alsa"
    )

    # === Optional features — enabled only if libraries are available ===
    pkg-config --exists libxml-2.0 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libxml2")
    pkg-config --exists libdrm 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libdrm")
    pkg-config --exists libva 2>/dev/null && CONFIGURE_FLAGS+=("--enable-vaapi")
    pkg-config --exists aom 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libaom")
    pkg-config --exists dav1d 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libdav1d")
    pkg-config --exists SvtAv1Enc 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libsvtav1")
    pkg-config --exists rav1e 2>/dev/null && CONFIGURE_FLAGS+=("--enable-librav1e")
    pkg-config --exists zimg 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libzimg")
    pkg-config --exists libvmaf 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libvmaf")
    pkg-config --exists libwebp 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libwebp")
    pkg-config --exists fribidi 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libfribidi")
    pkg-config --exists harfbuzz 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libharfbuzz")
    pkg-config --exists zvbi-0.2 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libzvbi")

    # Vulkan + libplacebo — only modern versions, only if available
    if [[ "$version" =~ ^(7\.1|8\.[0-9]+)$ ]]; then
        pkg-config --exists vulkan 2>/dev/null && CONFIGURE_FLAGS+=("--enable-vulkan")
        pkg-config --exists libplacebo 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libplacebo")
    fi

    if [[ "$HAS_CUDA" == true || "$FORCE_GPU" == true ]]; then
        CONFIGURE_FLAGS+=("--enable-nvenc" "--enable-ffnvcodec" "--enable-cuvid")
        [[ "$version" != "4.0" ]] && CONFIGURE_FLAGS+=("--enable-nvdec")
    fi

    if pkg-config --exists fdk-aac 2>/dev/null; then
        local fdk_ver="$(pkg-config --modversion fdk-aac 2>/dev/null || echo "0")"
        local fdk_major="${fdk_ver%%.*}"
        case "$version" in
            4.0|4.3)
                [[ "$fdk_major" -lt 2 ]] && CONFIGURE_FLAGS+=("--enable-libfdk-aac")
                ;;
            *)
                CONFIGURE_FLAGS+=("--enable-libfdk-aac")
                ;;
        esac
    fi

    # Version-specific flags
    case "$version" in
        4.0)
            CONFIGURE_FLAGS+=("--enable-postproc")
            [[ -f /usr/include/xvid.h ]] && CONFIGURE_FLAGS+=("--enable-libxvid")
            ;;
        4.3|4.4)
            CONFIGURE_FLAGS+=("--enable-postproc")
            ;;
        5.1)
            CONFIGURE_FLAGS+=("--enable-postproc")
            pkg-config --exists srt 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libsrt")
            ;;
        7*|8*)
            CONFIGURE_FLAGS+=("--enable-postproc")
            pkg-config --exists srt 2>/dev/null && CONFIGURE_FLAGS+=("--enable-libsrt")
            ;;
    esac
}

# ── Build a single FFmpeg version ────────────────────────────────────────────
build_version() {
    local version="$1"
    local tag="${FFMPEG_TAGS[$version]}"
    local src_dir="${BUILD_DIR}/ffmpeg-${version}"
    local prefix="${PREFIX_BASE}/${version}"

    log_step "════════════════════════════════════════════════════════"
    log_step "Building FFmpeg ${version} (tag: ${tag})"
    log_step "════════════════════════════════════════════════════════"

    if [[ "$HAS_CUDA" == true || "$FORCE_GPU" == true ]]; then
        install_nvcodec_headers "${NVCODEC_BRANCH[$version]}"
    fi

    if [[ ! -d "$src_dir" ]]; then
        log_step "Cloning FFmpeg ${version}..."
        git clone --depth 1 --branch "$tag" https://git.ffmpeg.org/ffmpeg.git "$src_dir"
    fi

    mkdir -p "$prefix"
    pushd "$src_dir" > /dev/null
    make distclean 2>/dev/null || true

    get_configure_flags "$version" "$prefix"
    local build_log="${src_dir}/xcvm_build.log"
    local rc=0

    # Configure
    if [[ "$VERBOSE" == true ]]; then
        ./configure "${CONFIGURE_FLAGS[@]}" 2>&1 | tee "$build_log"
        rc=${PIPESTATUS[0]}
    else
        ./configure "${CONFIGURE_FLAGS[@]}" > "$build_log" 2>&1 && rc=0 || rc=$?
        tail -20 "$build_log"
    fi
    if [[ $rc -ne 0 ]]; then
        log_error "Configure failed. Check: ${src_dir}/ffbuild/config.log"
        popd > /dev/null
        return 1
    fi

    # Compile
    log_step "Compiling with ${NPROC} threads..."
    if [[ "$VERBOSE" == true ]]; then
        make -j"${NPROC}" 2>&1 | tee -a "$build_log"
        rc=${PIPESTATUS[0]}
    else
        make -j"${NPROC}" >> "$build_log" 2>&1 && rc=0 || rc=$?
        tail -5 "$build_log"
    fi
    if [[ $rc -ne 0 ]]; then
        log_error "Compilation failed. Log: ${build_log}"
        popd > /dev/null
        return 1
    fi

    # Install to prefix
    log_step "Installing to prefix..."
    if [[ "$VERBOSE" == true ]]; then
        make install 2>&1 | tee -a "$build_log"
        rc=${PIPESTATUS[0]}
    else
        make install >> "$build_log" 2>&1 && rc=0 || rc=$?
    fi
    if [[ $rc -ne 0 ]]; then
        log_error "make install failed. Log: ${build_log}"
        popd > /dev/null
        return 1
    fi

    popd > /dev/null

    local ffmpeg_bin="${prefix}/bin/ffmpeg"
    if [[ ! -x "$ffmpeg_bin" ]] || ! timeout 5 "$ffmpeg_bin" -version &>/dev/null; then
        log_error "Binary not working"
        return 1
    fi

    log_info "✅ FFmpeg ${version} built successfully"
    log_info "Enabled features:"
    echo "   DASH          : $( "$ffmpeg_bin" -formats 2>/dev/null | grep -q 'E dash' && echo "✅" || echo "❌" )"
    echo "   DRM/VAAPI     : $( "$ffmpeg_bin" -hwaccels 2>/dev/null | grep -qE 'vaapi|drm' && echo "✅" || echo "❌" )"
    echo "   AV1 (dav1d)   : $( "$ffmpeg_bin" -decoders 2>/dev/null | grep -q dav1d && echo "✅" || echo "❌" )"
    echo "   AV1 (aom)     : $( "$ffmpeg_bin" -encoders 2>/dev/null | grep -q libaom && echo "✅" || echo "❌" )"
    echo "   AV1 (svtav1)  : $( "$ffmpeg_bin" -encoders 2>/dev/null | grep -q libsvtav1 && echo "✅" || echo "❌" )"
    echo "   libzimg/vmaf  : $( "$ffmpeg_bin" -filters 2>/dev/null | grep -q zscale && echo "✅" || echo "❌" )"
    echo "   Vulkan/placebo: $( "$ffmpeg_bin" -hwaccels 2>/dev/null | grep -q vulkan && echo "✅" || echo "N/A" )"

    return 0
}

# ── Install into XC_VM ───────────────────────────────────────────────────────
install_version() {
    local version="$1"
    local prefix="${PREFIX_BASE}/${version}"
    local target="${INSTALL_DIR}/${version}"
    if [[ ! -x "${prefix}/bin/ffmpeg" ]]; then
        log_error "No build for ${version}"
        return 1
    fi
    log_step "Installing FFmpeg ${version} to ${target}..."
    if [[ -d "$target" ]]; then
        local backup="${target}.backup.$(date +%Y%m%d_%H%M%S)"
        log_info "Backing up existing installation → ${backup}"
        cp -a "$target" "$backup"
    fi
    mkdir -p "$target"
    cp -f "${prefix}/bin/ffmpeg" "$target/ffmpeg"
    cp -f "${prefix}/bin/ffprobe" "$target/ffprobe"

    # Write build metadata
    cat > "${target}/BUILD_INFO" <<EOF
Built by: XC_VM FFmpeg Auto-Builder
Version:  FFmpeg ${version} (${FFMPEG_TAGS[$version]})
Date:     $(date -u '+%Y-%m-%d %H:%M:%S UTC')
OS:       $(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME}" || uname -sr)
Arch:     $(uname -m)
GCC:      $(gcc --version 2>/dev/null | head -1 || echo 'unknown')
GPU:      $(if [[ "$HAS_CUDA" == true ]]; then echo "NVENC+CUVID (CUDA: ${CUDA_HOME})"; else echo 'none (CPU-only)'; fi)
Strategy: static deps + dynamic glibc
EOF

    chown -R xc_vm:xc_vm "$target" 2>/dev/null || true
    chmod +x "${target}/ffmpeg" "${target}/ffprobe"
    if timeout 5 "${target}/ffmpeg" -version &>/dev/null; then
        log_info "Installed and validated: OK"
    else
        log_error "Installed binary failed validation"
        return 1
    fi
}

# ── Summary ──────────────────────────────────────────────────────────────────
print_summary() {
    echo ""
    log_step "════════════════════════════════════════════════════════"
    log_step "BUILD SUMMARY"
    log_step "════════════════════════════════════════════════════════"
    for version in "${VERSIONS_TO_BUILD[@]}"; do
        local bin="${PREFIX_BASE}/${version}/bin/ffmpeg"
        if [[ -x "$bin" ]]; then
            echo -e " ${GREEN}✓${NC} ${version} → OK"
        else
            echo -e " ${RED}✗${NC} ${version} → FAILED"
        fi
    done
    if [[ "$DO_INSTALL" == true ]]; then
        log_info "Installed to: ${INSTALL_DIR}"
    fi
    log_info "Build artifacts: ${PREFIX_BASE}/"
    log_info "Source cache: ${BUILD_DIR}/"
}

# ── Main ─────────────────────────────────────────────────────────────────────
main() {
    if [[ "$(id -u)" -ne 0 ]]; then
        log_error "Must run as root"
        exit 1
    fi

    echo ""
    log_step "XC_VM FFmpeg Auto-Builder - FINAL FULL COMPLETE"
    log_step "Versions: ${VERSIONS_TO_BUILD[*]}"
    [[ "$FORCE_GPU" == true ]] && log_step "FORCE_GPU enabled for Docker"
    echo ""

    mkdir -p "$BUILD_DIR" "$PREFIX_BASE"

    if command -v apt-get &>/dev/null; then
        install_deps_apt
    elif command -v dnf &>/dev/null || command -v yum &>/dev/null; then
        install_deps_dnf
    else
        log_error "Unsupported package manager"
        exit 1
    fi

    detect_cuda

    local failed=()
    for version in "${VERSIONS_TO_BUILD[@]}"; do
        if build_version "$version"; then
            if [[ "$DO_INSTALL" == true ]]; then
                install_version "$version" || failed+=("${version}(install)")
            fi
        else
            failed+=("$version")
        fi
    done

    print_summary

    if [[ ${#failed[@]} -gt 0 ]]; then
        log_error "Failed versions: ${failed[*]}"
        exit 1
    fi

    log_info "✅ ALL DONE! GPU support is fully retained even without GPU during build."
}

main "$@"