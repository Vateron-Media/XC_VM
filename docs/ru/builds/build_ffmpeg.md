# 🚀 Сборка FFmpeg с поддержкой NVIDIA NVENC/CUVID и статически включенными библиотеками

Это руководство объясняет, как собрать **FFmpeg** на Ubuntu с аппаратным ускорением NVIDIA (NVENC/CUVID) и включением всех необходимых библиотек в конечный бинарный файл для полной переносимости. Цель — создание **статических бинарных файлов** с интегрированными зависимостями.

---

## 📋 Требования

* **Ubuntu 22.04 или новее**
* Видеокарта NVIDIA с поддержкой **NVENC** (опционально, но рекомендуется)
* ~15 ГБ свободного места на диске
* Интернет-соединение

---

## 🔧 Конфигурация

Установите следующие переменные окружения для настройки сборки:

```bash
# Версия FFmpeg для сборки
export FFMPEG_VERSION="8.0"

# Директория для установки
export INSTALL_DIR="/home/xc_vm/bin/ffmpeg_bin"

# Директория для сборки
export BUILD_DIR="$HOME/ffmpeg_sources"

# Версия CUDA
export CUDA_VERSION="12-2"

# Версия драйвера NVIDIA
export NVIDIA_DRIVER_VERSION="535"
```

---

## 🔧 1. Установка инструментов для сборки

Обновите систему и установите основные пакеты для разработки:

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

## 🎶 2. Сборка и включение кодеков

Для включения поддержки популярных форматов в бинарный файл, соберите следующие библиотеки из исходников и включите их в FFmpeg:

### 2.1 Создание директории для сборки

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

### 2.6 Дополнительные библиотеки

- **libass** (субтитры):

```bash
git clone https://github.com/libass/libass.git
cd libass
./autogen.sh
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **libfreetype** (шрифты):

```bash
wget https://download.savannah.gnu.org/releases/freetype/freetype-2.13.2.tar.gz
tar -xvzf freetype-2.13.2.tar.gz
cd freetype-2.13.2
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **libvorbis** (аудио):

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

- **libunistring** (для gnutls и librtmp):

```bash
wget https://ftp.gnu.org/gnu/libunistring/libunistring-1.2.tar.gz
tar -xvzf libunistring-1.2.tar.gz
cd libunistring-1.2
./configure --prefix="${INSTALL_DIR:-$HOME/ffmpeg_build}" --enable-static --disable-shared
make -j$(nproc)
sudo make install
cd ..
```

- **bzip2**
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

## ⚡ 3. Поддержка NVIDIA

### 3.1 Установка драйверов

```bash
sudo add-apt-repository -y ppa:graphics-drivers/ppa
sudo apt update
sudo apt install -y nvidia-driver-${NVIDIA_DRIVER_VERSION:-535}
```

> ℹ️ Убедитесь, что версия драйвера совместима с вашей видеокартой.

### 3.2 Установка CUDA Toolkit

```bash
wget https://developer.download.nvidia.com/compute/cuda/repos/ubuntu2204/x86_64/cuda-keyring_1.0-1_all.deb
sudo dpkg -i cuda-keyring_1.0-1_all.deb
sudo apt update
sudo apt install -y cuda-toolkit-${CUDA_VERSION:-12-2}
```

### 3.3 Установка заголовков NVENC

```bash
cd ${BUILD_DIR:-~/ffmpeg_sources}
git clone https://git.videolan.org/git/ffmpeg/nv-codec-headers.git
cd nv-codec-headers
make
sudo make PREFIX="${INSTALL_DIR:-$HOME/ffmpeg_build}" install
cd ..
```

### 3.4 Опциональные инструменты NVIDIA

```bash
sudo apt install -y nvidia-cuda-toolkit nvidia-cuda-dev
```

---

## 🔨 4. Сборка FFmpeg

### 4.1 Загрузка исходного кода

```bash
cd ${BUILD_DIR:-~/ffmpeg_sources}
wget https://ffmpeg.org/releases/ffmpeg-${FFMPEG_VERSION:-8.0}.tar.bz2
tar xjvf ffmpeg-${FFMPEG_VERSION:-8.0}.tar.bz2
cd ffmpeg-${FFMPEG_VERSION:-8.0}
```

### 4.2 Конфигурация

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

### 4.3 Компиляция

```bash
make -j$(nproc)
```

---

## 📦 5. Установка

```bash
mkdir -p ${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/
cp ffmpeg ffprobe ${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/
```

---

## ✅ Проверка

```bash
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -version
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffprobe -version
```

Проверка поддержки NVIDIA:

```bash
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -encoders | grep nvenc
${INSTALL_DIR:-/home/xc_vm/bin/ffmpeg_bin}/${FFMPEG_VERSION:-8.0}/ffmpeg -decoders | grep cuvid
```

---

## 📝 Примечания

1. Все библиотеки собраны статически и включены в бинарный файл FFmpeg, что обеспечивает полную переносимость.
2. Драйвер NVIDIA должен быть совместим с вашей видеокартой.
3. После установки драйверов может потребоваться **перезагрузка**.
4. Сборка FFmpeg потребляет значительные ресурсы CPU и памяти.
5. Итоговый бинарный файл будет большим из-за включения всех зависимостей.
6. Настройте переменные окружения в соответствии с вашей системой.
7. Для разных версий FFmpeg могут потребоваться корректировки флагов конфигурации.

---

## 🔄 Совместимость версий

| Версия FFmpeg | Рекомендуемая CUDA | Примечания |
|---------------|--------------------|------------|
| 8.x           | 12.2+              | Новейшие функции |
| 7.x           | 12.0+              | Стабильная |
| 6.x           | 11.8+              | Устаревшая |

Проверяйте [документацию FFmpeg](https://ffmpeg.org/) для конкретных требований версий.