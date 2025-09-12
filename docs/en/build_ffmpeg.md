# 🚀 Building FFmpeg with NVIDIA NVENC/CUVID Support and Statically Linked Libraries

This guide explains how to build **FFmpeg** on Ubuntu with NVIDIA hardware acceleration (NVENC/CUVID) and include all necessary libraries in the final binary for full portability. The goal is to create **static binaries** with integrated dependencies.

---

## 📋 Requirements

* **Ubuntu 22.04 or newer**
* NVIDIA GPU with **NVENC** support (optional but recommended)
* ~15 GB of free disk space
* Internet connection

---

## 🔧 Configuration

Set the following environment variables to configure the build:

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

## 🔧 1. Install Build Tools

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

## 🎶 2. Build and Enable Codecs

To include support for popular formats in the binary, build the following libraries from source and link them statically into FFmpeg:

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

- **libass** (subtitles):

```bash
git clone https://github.com/libass/libass.git
cd libass
./autogen.sh
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **libfreetype** (fonts):

```bash
wget https://download.savannah.gnu.org/releases/freetype/freetype-2.13.2.tar.gz
tar -xvzf freetype-2.13.2.tar.gz
cd freetype-2.13.2
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **libvorbis** (audio):

```bash
wget https://downloads.xiph.org/releases/vorbis/libvorbis-1.3.7.tar.xz
tar -xvf libvorbis-1.3.7.tar.xz
cd libvorbis-1.3.7
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **libmp3lame** (MP3):

```bash
wget https://downloads.sourceforge.net/project/lame/lame/3.100/lame-3.100.tar.gz
tar -xvzf lame-3.100.tar.gz
cd lame-3.100
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **libtheora** (Theora):

```bash
git clone https://github.com/xiph/theora.git
cd theora
./autogen.sh
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **librtmp** (RTMP):

```bash
git clone git://git.ffmpeg.org/rtmpdump
cd rtmpdump
make SYS=posix -j$(nproc)
sudo make prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" install
cd ..
```

- **libunistring** (for gnutls and librtmp):

```bash
wget https://ftp.gnu.org/gnu/libunistring/libunistring-1.2.tar.gz
tar -xvzf libunistring-1.2.tar.gz
cd libunistring-1.2
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **bzip2**:

```bash
cd ${BUILD_DIR:-~/ffmpeg_sources}
wget https://sourceware.org/pub/bzip2/bzip2-1.0.8.tar.gz
tar -xvzf bzip2-1.0.8.tar.gz
cd bzip2-1.0.8
make -f Makefile-libbz2_so CFLAGS="-fPIC" -j$(nproc)
make install PREFIX="${INSTALL_DIR:-$HOME/ffmpeg_build}"
cd ..
```

---

## ⚡ 3. NVIDIA Support

### 3.1 Install Drivers

```bash
sudo add-apt-repository -y ppa:graphics-drivers/ppa
sudo apt update
sudo apt install -y nvidia-driver-${NVIDIA_DRIVER_VERSION:-535}
```

> ℹ️ Ensure the driver version is compatible with your GPU.

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
sudo make PREFIX="${INSTALL_DIR:-$HOME/ffmpeg_build}" install
cd ..
```

### 3.4 Optional NVIDIA Tools

```bash
sudo apt install -y nvidia-cuda-toolkit nvidia-cuda-dev
```

---

## 🔨 4. Build FFmpeg

### 4.1 Download Source Code

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

### 4.3 Compilation

```bash
make -j$(nproc)
```

---

## 📦 5. Installation

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