# 🚀 Сборка FFmpeg с поддержкой NVIDIA NVENC/CUVID

Это руководство объясняет, как собрать **FFmpeg** на Ubuntu с аппаратным ускорением NVIDIA (NVENC/CUVID) и поддержкой популярных кодеков.
Цель — создание **статических бинарных файлов**, которые можно легко переносить между системами.

---

## 📋 Требования

* **Ubuntu 22.04 или новее**
* Видеокарта NVIDIA с поддержкой **NVENC** (опционально, но рекомендуется)
* \~2 ГБ свободного места на диске
* Интернет-соединение

---

## 🔧 Конфигурация

Установите следующие переменные окружения для настройки сборки:

```bash
# Версия FFmpeg для сборки (по умолчанию: 8.0)
export FFMPEG_VERSION="8.0"

# Директория для установки (по умолчанию: /home/xc_vm/bin/ffmpeg_bin)
export INSTALL_DIR="/home/xc_vm/bin/ffmpeg_bin"

# Директория для сборки (по умолчанию: ~/ffmpeg_sources)
export BUILD_DIR="$HOME/ffmpeg_sources"

# Версия CUDA (по умолчанию: 12-2)
export CUDA_VERSION="12-2"

# Версия драйвера NVIDIA (по умолчанию: 535)
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
  zlib1g-dev mercurial nasm
```

---

## 🎶 2. Установка кодеков

Для включения поддержки распространенных форматов установите следующие библиотеки:

```bash
# H.264/AVC
sudo apt-get install -y libx264-dev

# H.265/HEVC
sudo apt-get install -y libx265-dev libnuma-dev

# VP8/VP9
sudo apt-get install -y libvpx-dev

# Opus Audio
sudo apt-get install -y libopus-dev

# Дополнительные библиотеки
sudo apt-get install -y \
  libbz2-dev libfontconfig1-dev libtheora-dev \
  libxvidcore-dev librtmp-dev libunistring-dev libgmp-dev
```

---

## ⚡ 3. Поддержка NVIDIA

### 3.1 Установка драйверов

```bash
sudo add-apt-repository -y ppa:graphics-drivers/ppa
sudo apt update
sudo apt install -y nvidia-driver-${NVIDIA_DRIVER_VERSION:-535}
```

> ℹ️ Выберите версию драйвера, совместимую с вашей видеокартой.

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
sudo make install
```

Возможно нужно устанавливать командой:

```bash
make PREFIX="${INSTALL_DIR:-$HOME/ffmpeg_build}" install
```

### 3.4 Опциональные инструменты NVIDIA

```bash
sudo apt install -y nvidia-cuda-toolkit nvidia-cuda-dev
```

---

## 🔨 4. Сборка FFmpeg

### 4.1 Загрузка исходного кода

```bash
mkdir -p ${BUILD_DIR:-~/ffmpeg_sources} && cd ${BUILD_DIR:-~/ffmpeg_sources}
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

1. Драйвер NVIDIA должен быть совместим с вашей видеокартой.
2. После установки драйверов может потребоваться **перезагрузка**.
3. Сборка FFmpeg потребляет значительные ресурсы CPU и памяти.
4. Статическая сборка → большие бинарные файлы, но полностью переносимые.
5. Настройте переменные окружения в соответствии с конфигурацией вашей системы.
6. Для разных версий FFmpeg могут потребоваться корректировки флагов конфигурации.

---

## 🔄 Совместимость версий

| Версия FFmpeg | Рекомендуемая CUDA | Примечания |
|---------------|--------------------|------------|
| 7.x           | 12.2+              | Новейшие функции |
| 6.x           | 11.8+              | Стабильная |
| 5.x           | 11.0+              | Устаревшая |

Проверяйте [документацию FFmpeg](https://ffmpeg.org/) для конкретных требований версий.
```