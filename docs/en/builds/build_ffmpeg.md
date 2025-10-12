Вот английский перевод руководства по сборке FFmpeg для XC_VM:

---

<h1 align="center">🚀 XC_VM — FFmpeg Build Guide</h1>

<p align="center">
  This guide explains how to build FFmpeg with NVIDIA hardware acceleration (NVENC/CUVID) and statically link libraries for full portability within the XC_VM project.
</p>

<p align="center">
  <a href="../../en/main-page.md"><b>⬅️ Back to Main Page</b></a>
</p>

---

## 📚 Navigation

* [📋 Requirements](#requirements)
* [🔧 Configuration](#configuration)
* [🛠 Install Build Tools](#install-build-tools)
* [🎶 Compile and Include Codecs](#compile-and-include-codecs)
* [🖥 NVIDIA & CUDA Installation](#nvidia--cuda-installation)
* [🔨 FFmpeg Build](#ffmpeg-build)
* [📦 Installation](#installation)
* [✅ Verification](#verification)
* [🧾 Notes](#notes)
* [🔄 Version Compatibility](#version-compatibility)

---

## 📋 Requirements

* **Ubuntu 22.04 or newer** — recommended OS for a stable build.
* NVIDIA GPU with **NVENC support** (optional but recommended for hardware acceleration).
* ~15 GB of free disk space for sources and temporary files.
* Stable internet connection to download dependencies.

> 💡 **Tip:** Ensure your system is updated before starting to avoid package conflicts.

---

## 🔧 Configuration

Set environment variables to customize the build, allowing easy adjustments to versions and paths:

```bash
# FFmpeg version to build
export FFMPEG_VERSION="8.0"

# Installation directory
export INSTALL_DIR="/home/xc_vm/bin/ffmpeg_bin"

# Build directory
export BUILD_DIR="$HOME/ffmpeg_sources"

# CUDA version
export CUDA_VERSION="12-2"

# NVIDIA driver version
export NVIDIA_DRIVER_VERSION="535"
```

---

## 🛠 Install Build Tools

Update the system and install essential development packages:

```bash
sudo apt-get update -qq && sudo apt-get -y install \
  autoconf automake build-essential cmake git-core \
  libass-dev libfreetype6-dev libgnutls28-dev libmp3lame-dev \
  libsdl2-dev libtool libva-dev libvdpau-dev libvorbis-dev \
  libxcb1-dev libxcb-shm0-dev libxcb-xfixes0-dev \
  meson ninja-build pkg-config texinfo wget yasm \
  zlib1g-dev mercurial nasm libssl-dev software-properties-common
```

---

## 🎶 Compile and Include Codecs

Build libraries from source for static integration into FFmpeg, supporting popular formats without external dependencies.

### 2.1 Create Build Directory

```bash
mkdir -p ${BUILD_DIR:-~/ffmpeg_sources} && cd ${BUILD_DIR:-~/ffmpeg_sources}
```

### 2.2 H.264 (libx264)

```bash
git clone https://code.videolan.org/videolan/x264.git
cd x264
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared --enable-pic
make -j$(nproc)
sudo make install
cd ..
```

### 2.3 H.265 (libx265)

```bash
git clone https://bitbucket.org/multicoreware/x265_git.git
cd x265_git/build
cmake ../source -DCMAKE_INSTALL_PREFIX="${INSTALL_DIR:-$HOME/ffmpeg_build}" -DENABLE_SHARED=OFF
make -j$(nproc)
sudo make install
cd ../..
```

### 2.4 VP8/VP9 (libvpx)

```bash
git clone https://chromium.googlesource.com/webm/libvpx
cd libvpx
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared --enable-pic
make -j$(nproc)
sudo make install
cd ..
```

### 2.5 Opus Audio (libopus)

```bash
wget https://downloads.xiph.org/releases/opus/opus-1.5.2.tar.gz
tar -xvzf opus-1.5.2.tar.gz
cd opus-1.5.2
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

### 2.6 Additional Libraries

* **libass** (subtitles)
* **libfreetype** (fonts)
* **libvorbis** (audio)
* **libmp3lame** (MP3)
* **libtheora** (Theora)
* **librtmp** (RTMP)
* **libunistring** (required by gnutls and librtmp)
* **bzip2**

*(Use the same build commands as in the Russian version, adjusting paths as needed.)*

---

## 🖥 NVIDIA & CUDA Installation

Install NVIDIA drivers and CUDA for hardware acceleration.

### 3.1 NVIDIA Driver Installation

```bash
sudo add-apt-repository ppa:graphics-drivers/ppa
sudo apt update
sudo apt install nvidia-driver-${NVIDIA_DRIVER_VERSION:-535}
```

> ⚠️ **Important:** Reboot after installation and verify GPU compatibility.

### 3.2 CUDA Toolkit Installation

```bash
wget https://developer.download.nvidia.com/compute/cuda/repos/ubuntu2204/x86_64/cuda-keyring_1.0-1_all.deb
sudo dpkg -i cuda-keyring_1.0-1_all.deb
sudo apt update
sudo apt install -y cuda-toolkit-${CUDA_VERSION:-12-2}
```

### 3.3 NVENC Headers

```bash
cd ${BUILD_DIR:-~/ffmpeg_sources}
git clone https://git.videolan.org/git/ffmpeg/nv-codec-headers.git
cd nv-codec-headers
make
sudo make PREFIX="${INSTALL_DIR:-$HOME/ffmpeg_build}" install
cd ..
```

### 3.4 Optional NVIDIA Tools

```bash
sudo apt install -y nvidia-cuda-toolkit nvidia-cuda-dev
```

---

## 🔨 FFmpeg Build

### 4.1 Download Source

```bash
cd ${BUILD_DIR:-~/ffmpeg_sources}
wget https://ffmpeg.org/releases/ffmpeg-${FFMPEG_VERSION:-8.0}.tar.bz2
tar xjvf ffmpeg-${FFMPEG_VERSION:-8.0}.tar.bz2
cd ffmpeg-${FFMPEG_VERSION:-8.0}
```

### 4.2 Configuration

```bash
export PATH="${INSTALL_DIR:-$HOME/bin}:$PATH"
export PKG_CONFIG_PATH="${INSTALL_DIR:-$HOME/ffmpeg_build}/lib/pkgconfig"

./configure \
  --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" \
  --pkg-config-flags="--static" \
  --extra-cflags="-I${INSTALL_DIR:-$HOME/ffmpeg_build}/include -I/usr/local/cuda/include" \
  --extra-ldflags="-L${INSTALL_DIR:-$HOME/ffmpeg_build}/lib -L/usr/local/cuda/lib64 -Wl,-Bstatic -lcrypto -lssl -Wl,-Bdynamic" \
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
  --enable-static \
  --enable-muxer=hls \
  --enable-muxer=dash \
  --enable-demuxer=hls \
  --extra-cflags=--static \
  --target-os=linux
```

### 4.3 Compile

```bash
make -j$(nproc)
```

---

## 📦 Installation

```bash
mkdir -p ${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/
cp ffmpeg ffprobe ${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/
```

---

## ✅ Verification

Check version and NVIDIA support:

```bash
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -version
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffprobe -version
```

Check NVENC and CUVID support:

```bash
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -encoders | grep nvenc
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -decoders | grep cuvid
```

---

## 📝 Notes

1. All libraries are statically linked into the FFmpeg binary, ensuring full portability.
2. The NVIDIA driver must be compatible with your GPU.
3. A **reboot** may be required after installing drivers.
4. Building FFmpeg is CPU- and memory-intensive.
5. The resulting binary will be large due to included dependencies.
6. Adjust environment variables to match your system.
7. Configuration flags may need adjustments for different FFmpeg versions.

---

## 🔄 Version Compatibility

| FFmpeg Version | Recommended CUDA | Notes            |
|----------------|------------------|------------------|
| 8.x            | 12.2+            | Latest features  |
| 7.x            | 12.0+            | Stable           |
| 6.x            | 11.8+            | Outdated         |

Check the [FFmpeg documentation](https://ffmpeg.org/) for specific version requirements.

---

<p align="center">
  <a href="../../en/main-page.md"><b>⬅️ Назад на главную</b></a>
</p>

---