# 🚀 Сборка FFmpeg 8.0 с поддержкой NVIDIA NVENC/CUVID

Это руководство описывает процесс сборки **FFmpeg 8.0** на Ubuntu с поддержкой аппаратного ускорения NVIDIA (NVENC/CUVID) и популярными кодеками.
Цель — получить **статические бинарные файлы**, которые удобно переносить между системами.

---

## 📋 Что понадобится

* **Ubuntu 22.04 или новее**
* Видеокарта NVIDIA с поддержкой **NVENC** (но возможно и нет)
* \~2 ГБ свободного места на диске
* Интернет-доступ

---

## 🔧 1. Установка базовых инструментов

Обновите систему и установите основные инструменты сборки:

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

Чтобы FFmpeg умел работать с популярными форматами, установим дополнительные библиотеки:

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
sudo apt install -y nvidia-driver-535
```

> ℹ️ Версию драйвера выбирайте под своё GPU.

### 3.2 CUDA Toolkit

```bash
wget https://developer.download.nvidia.com/compute/cuda/repos/ubuntu2204/x86_64/cuda-keyring_1.0-1_all.deb
sudo dpkg -i cuda-keyring_1.0-1_all.deb
sudo apt update
sudo apt install -y cuda-toolkit-12-2
```

### 3.3 Заголовки NVENC

```bash
git clone https://git.videolan.org/git/ffmpeg/nv-codec-headers.git
cd nv-codec-headers
make
sudo make install
```

Возможно нужно устанавливать командой:

```bash
make PREFIX="$HOME/ffmpeg_build" install
```

### 3.4 Доп. инструменты NVIDIA (опционально)

```bash
sudo apt install -y nvidia-cuda-toolkit nvidia-cuda-dev
```

---

## 🔨 4. Сборка FFmpeg

### 4.1 Скачивание исходников

```bash
mkdir -p ~/ffmpeg_sources && cd ~/ffmpeg_sources
wget -O ffmpeg-snapshot.tar.bz2 https://ffmpeg.org/releases/ffmpeg-snapshot.tar.bz2
tar xjvf ffmpeg-snapshot.tar.bz2
cd ffmpeg
```

### 4.2 Конфигурация

```bash
export PATH="$HOME/bin:$PATH"
export PKG_CONFIG_PATH="$HOME/ffmpeg_build/lib/pkgconfig"

./configure \
  --prefix="$HOME/ffmpeg_build" \
  --pkg-config-flags="--static" \
  --extra-cflags="-I/usr/local/cuda/include" \
  --extra-ldflags="-L$HOME/ffmpeg_build/lib -Wl,-Bstatic -lcrypto -lssl -Wl,-Bdynamic" \
  --extra-version=XCVM \
  --extra-libs="-lsupc++ -lgmp -lz -lunistring -lpthread -lm -lrt -ldl" \
  --bindir="$HOME/bin" \
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
  --extra-cflags=--static
```

### 4.3 Сборка

```bash
make -j$(nproc)
```

---

## 📦 5. Установка

```bash
mkdir -p /home/xc_vm/bin/ffmpeg_bin/8.0/
cp ffmpeg ffprobe /home/xc_vm/bin/ffmpeg_bin/8.0/
```

---

## ✅ Проверка

```bash
/home/xc_vm/bin/ffmpeg_bin/8.0/ffmpeg -version
/home/xc_vm/bin/ffmpeg_bin/8.0/ffprobe -version
```

Проверка поддержки NVIDIA:

```bash
/home/xc_vm/bin/ffmpeg_bin/8.0/ffmpeg -encoders | grep nvenc
/home/xc_vm/bin/ffmpeg_bin/8.0/ffmpeg -decoders | grep cuvid
```

---

## 📝 Заметки

1. Драйвер NVIDIA должен поддерживать ваше железо.
2. После установки драйверов может понадобиться **перезагрузка**.
3. Сборка требует много памяти и процессорного времени.
4. Статическая сборка → большие бинарники, но полная переносимость.
