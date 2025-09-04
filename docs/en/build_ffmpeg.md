# 🚀 Building FFmpeg with NVIDIA NVENC/CUVID Support

This guide explains how to build **FFmpeg** on Ubuntu with NVIDIA hardware acceleration (NVENC/CUVID) and popular codecs enabled.
The goal is to produce **static binaries** that can be easily transferred between systems.

---

## 📋 Requirements

* **Ubuntu 22.04 or newer**
* An NVIDIA GPU with **NVENC** support (optional, but recommended)
* \~2 GB of free disk space
* Internet connection

---

## 🔧 Configuration

Set the following environment variables to customize your build:

```bash
# FFmpeg version to build (default: 8.0)
export FFMPEG_VERSION="8.0"

# Installation directory (default: /home/xc_vm/bin/ffmpeg_bin)
export INSTALL_DIR="/home/xc_vm/bin/ffmpeg_bin"

# Build directory (default: ~/ffmpeg_sources)
export BUILD_DIR="$HOME/ffmpeg_sources"

# CUDA version (default: 12-2)
export CUDA_VERSION="12-2"

# NVIDIA driver version (default: 535)
export NVIDIA_DRIVER_VERSION="535"
```

---

## 🔧 1. Install Build Tools

Update the system and install essential development packages:

```bash
sudo apt-get update -qq && sudo apt-get -y install \
  autoconf automake build-essential cmake git-core \
  libass-dev libfreetype6-dev libgnutls28-dev libmp3lame-dev \
  libsdl2-dev libtool libva-dev libvdpau-dev libvorbis-dev \
  libxcb1-dev libxcb-shm0-dev libxcb-xfixes0-dev \
  meson ninja-build pkg-config texinfo wget yasm \
  zlib1g-dev mercurial nasm
```

---

## 🎶 2. Install Codecs

To enable support for common formats, install the following libraries:

```bash
# H.264/AVC
sudo apt-get install -y libx264-dev

# H.265/HEVC
sudo apt-get install -y libx265-dev libnuma-dev

# VP8/VP9
sudo apt-get install -y libvpx-dev

# Opus Audio
sudo apt-get install -y libopus-dev

# Additional libraries
sudo apt-get install -y \
  libbz2-dev libfontconfig1-dev libtheora-dev \
  libxvidcore-dev librtmp-dev libunistring-dev libgmp-dev
```

---

## ⚡ 3. NVIDIA Support

### 3.1 Install Drivers

```bash
sudo add-apt-repository -y ppa:graphics-drivers/ppa
sudo apt update
sudo apt install -y nvidia-driver-${NVIDIA_DRIVER_VERSION:-535}
```

> ℹ️ Choose the driver version that matches your GPU.

### 3.2 Install CUDA Toolkit

```bash
wget https://developer.download.nvidia.com/compute/cuda/repos/ubuntu2204/x86_64/cuda-keyring_1.0-1_all.deb
sudo dpkg -i cuda-keyring_1.0-1_all.deb
sudo apt update
sudo apt install -y cuda-toolkit-${CUDA_VERSION:-12-2}
```

### 3.3 Install NVENC Headers

```bash
cd ${BUILD_DIR:-~/ffmpeg_sources}
git clone https://git.videolan.org/git/ffmpeg/nv-codec-headers.git
cd nv-codec-headers
make
sudo make install
```

In some cases, you may need to install with:

```bash
make PREFIX="${INSTALL_DIR:-$HOME/ffmpeg_build}" install
```

### 3.4 Optional NVIDIA Tools

```bash
sudo apt install -y nvidia-cuda-toolkit nvidia-cuda-dev
```

---

## 🔨 4. Build FFmpeg

### 4.1 Download Source

```bash
mkdir -p ${BUILD_DIR:-~/ffmpeg_sources} && cd ${BUILD_DIR:-~/ffmpeg_sources}
wget https://ffmpeg.org/releases/ffmpeg-${FFMPEG_VERSION:-8.0}.tar.bz2
tar xjvf ffmpeg-${FFMPEG_VERSION:-8.0}.tar.bz2
cd ffmpeg-${FFMPEG_VERSION:-8.0}
```

### 4.2 Configure

```bash
export PATH="${INSTALL_DIR:-$HOME/bin}:$PATH"
export PKG_CONFIG_PATH="${INSTALL_DIR:-$HOME/ffmpeg_build}/lib/pkgconfig"

./configure \
  --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" \
  --pkg-config-flags="--static" \
  --extra-cflags="-I/usr/local/cuda/include" \
  --extra-ldflags="-L${INSTALL_DIR:-$HOME/ffmpeg_build}/lib -Wl,-Bstatic -lcrypto -lssl -Wl,-Bdynamic" \
  --extra-version=XCVM \
  --extra-libs="-lsupc++ -lgmp -lz -lunistring -lpthread -lm -lrt -ldl" \
  --bindir="${INSTALL_DIR:-$HOME/bin}" \
  --enable-gpl \
  --enable-gnutls \
  --enable-libass \
  --enable-libfreetype \
  --enable-libmp3lame \
  --enable-libopus \
  --enable-libvorbis \
  --enable-libvpx \
  --enable-libx264 \
  --enable-libx265 \
  --enable-librtmp \
  --enable-libtheora \
  --enable-libxvid \
  --enable-bzlib \
  --enable-fontconfig \
  --enable-zlib \
  --enable-nvenc \
  --enable-ffnvcodec \
  --enable-cuvid \
  --enable-version3 \
  --enable-nonfree \
  --enable-pthreads \
  --enable-runtime-cpudetect \
  --enable-gray \
  --disable-alsa \
  --disable-indev=alsa \
  --disable-outdev=alsa \
  --disable-ffplay \
  --disable-doc \
  --disable-debug \
  --disable-autodetect \
  --disable-shared \
  --enable-static \
  --enable-muxer=hls \
  --enable-muxer=dash \
  --enable-demuxer=hls \
  --extra-cflags=--static
```

### 4.3 Compile

```bash
make -j$(nproc)
```

---

## 📦 5. Install

```bash
mkdir -p ${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/
cp ffmpeg ffprobe ${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/
```

---

## ✅ Verification

```bash
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -version
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffprobe -version
```

Check NVIDIA support:

```bash
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -encoders | grep nvenc
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -decoders | grep cuvid
```

---

## 📝 Notes

1. The NVIDIA driver must be compatible with your GPU.
2. A **reboot** may be required after installing drivers.
3. Building FFmpeg consumes significant CPU and memory resources.
4. Static builds → larger binaries, but fully portable.
5. Adjust environment variables according to your system configuration.
6. Different FFmpeg versions may require adjustments to the configure flags.

---

## 🔄 Version Compatibility

| FFmpeg Version | Recommended CUDA | Notes |
|----------------|------------------|-------|
| 7.x            | 12.2+            | Latest features |
| 6.x            | 11.8+            | Stable |
| 5.x            | 11.0+            | Legacy |

Check the [FFmpeg documentation](https://ffmpeg.org/) for specific version requirements.