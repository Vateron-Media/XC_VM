<h1 align="center">🧱 XC_VM — Building Binary Files</h1>

<p align="center">
  This guide explains how to build binary files for XC_VM.  
  It is intended for developers and system administrators who want to customize the system.
</p>

<p align="center">
  <a href="../../en/main-page.md"><b>⬅️ Back to Main Page</b></a>
</p>

---

## 📚 Navigation

* [🔧 Prerequisites](#prerequisites)
* [🌐 Building NGINX](#building-nginx)
* [📺 Building NGINX with RTMP](#building-nginx-with-rtmp)
* [🐘 Building PHP-FPM](#building-php-fpm)
* [📦 Installing PHP Extensions](#installing-php-extensions)
* [⚙️ Building the `network` Binary](#building-the-network-binary)
* [✅ Conclusion](#conclusion)

---

## 🔧 Prerequisites

> ⚠️ **Warning:** All steps should be performed **on the server** where `xc_vm` is installed.

### 1. Install Dependencies

Install the necessary packages for building. This ensures all required tools and libraries are available.

```bash
sudo apt-get install -y \
  build-essential libpcre3 libpcre3-dev zlib1g zlib1g-dev \
  libssl-dev libgd-dev libxml2 libxml2-dev uuid-dev libxslt1-dev \
  unzip wget curl git
```

---

## 🌐 Building NGINX

### 1. Download Source Files

#### OpenSSL

```bash
wget https://github.com/openssl/openssl/releases/download/openssl-3.5.1/openssl-3.5.1.tar.gz
tar -xzvf openssl-3.5.1.tar.gz
```

#### Zlib

```bash
wget https://zlib.net/zlib-1.3.1.tar.gz
tar -xzvf zlib-1.3.1.tar.gz
```

#### PCRE

```bash
wget https://sourceforge.net/projects/pcre/files/pcre/8.45/pcre-8.45.tar.gz
tar -xzvf pcre-8.45.tar.gz
```

#### FLV Module

```bash
wget https://github.com/winshining/nginx-http-flv-module/archive/refs/tags/v1.2.12.zip
unzip v1.2.12.zip
```

#### Nginx

```bash
wget https://nginx.org/download/nginx-1.28.0.tar.gz
tar -zxvf nginx-1.28.0.tar.gz
cd nginx-1.28.0
```

### 2. Configure Build

Configure a static build with required modules:

```bash
./configure \
  --prefix=/home/xc_vm/bin/nginx \
  --with-compat \
  --with-http_auth_request_module \
  --with-file-aio \
  --with-threads \
  --with-http_gzip_static_module \
  --with-http_realip_module \
  --with-http_flv_module \
  --with-http_mp4_module \
  --with-http_secure_link_module \
  --with-http_slice_module \
  --with-http_ssl_module \
  --with-http_stub_status_module \
  --with-http_sub_module \
  --with-http_v2_module \
  --with-cc-opt='-static -static-libgcc -O2 -g -pipe -Wall -Wp,-D_FORTIFY_SOURCE=2 -fexceptions -fstack-protector --param=ssp-buffer-size=4 -m64 -mtune=generic -fPIC' \
  --with-ld-opt='-static -Wl,-z,relro -Wl,-z,now -pie' \
  --with-pcre=../pcre-8.45 \
  --with-pcre-jit \
  --with-zlib=../zlib-1.3.1 \
  --with-openssl=../openssl-3.5.1 \
  --with-openssl-opt=no-nextprotoneg
```

### 3. Build and Install

```bash
make
make install
```

---

## 📺 Building NGINX with RTMP

### 1. Download RTMP Module

```bash
wget https://github.com/arut/nginx-rtmp-module/archive/refs/tags/v1.2.2.tar.gz
tar -xzvf v1.2.2.tar.gz
```

### 2. Configure Build

```bash
cd nginx-1.28.0
./configure \
  --prefix=/home/xc_vm/bin/nginx_rtmp \
  --add-module=../nginx-http-flv-module-1.2.12 \
  --with-compat \
  --with-http_auth_request_module \
  --with-file-aio \
  --with-threads \
  --with-http_gzip_static_module \
  --with-http_realip_module \
  --with-http_flv_module \
  --with-http_mp4_module \
  --with-http_secure_link_module \
  --with-http_slice_module \
  --with-http_ssl_module \
  --with-http_stub_status_module \
  --with-http_sub_module \
  --with-http_v2_module \
  --with-cc-opt='-static -static-libgcc -O2 -g -pipe -Wall -Wp,-D_FORTIFY_SOURCE=2 -fexceptions -fstack-protector --param=ssp-buffer-size=4 -m64 -mtune=generic -fPIC' \
  --with-ld-opt='-static -Wl,-z,relro -Wl,-z,now -pie'
```

### 3. Build and Install

```bash
make
make install
```

### 4. Rename Executable

```bash
mv /home/xc_vm/bin/nginx_rtmp/sbin/nginx /home/xc_vm/bin/nginx_rtmp/sbin/nginx_rtmp
```

---

## 🐘 Building PHP-FPM

### 1. Install Dependencies

```bash
sudo apt-get install -y \
  libcurl4-gnutls-dev libbz2-dev libzip-dev autoconf automake \
  libtool m4 gcc make pkg-config libmaxminddb-dev libssh2-1-dev
```

### 2. Download PHP Source

```bash
wget -O php-8.1.33.tar.gz http://php.net/get/php-8.1.33.tar.gz/from/this/mirror
tar -xzvf php-8.1.33.tar.gz
cd php-8.1.33
```

### 3. Configure Build

```bash
./configure \
  --prefix=/home/xc_vm/bin/php \
  --with-fpm-user=xc_vm \
  --with-fpm-group=xc_vm \
  --enable-gd \
  --with-jpeg \
  --with-freetype \
  --enable-static \
  --disable-shared \
  --enable-opcache \
  --enable-fpm \
  --without-sqlite3 \
  --without-pdo-sqlite \
  --enable-mysqlnd \
  --with-mysqli \
  --with-curl \
  --disable-cgi \
  --with-zlib \
  --enable-sockets \
  --with-openssl \
  --enable-shmop \
  --enable-sysvsem \
  --enable-sysvshm \
  --enable-sysvmsg \
  --enable-calendar \
  --disable-rpath \
  --enable-inline-optimization \
  --enable-pcntl \
  --enable-mbregex \
  --enable-exif \
  --enable-bcmath \
  --with-mhash \
  --with-gettext \
  --with-xmlrpc \
  --with-xsl \
  --with-libxml \
  --with-pdo-mysql \
  --disable-mbregex \
  --with-pear
```

### 4. Build and Install

```bash
make
make install
```

---

## 📦 Installing PHP Extensions

Use the built-in `pecl` to install extensions:

```bash
/home/xc_vm/bin/php/bin/pecl install maxminddb
/home/xc_vm/bin/php/bin/pecl install ssh2
/home/xc_vm/bin/php/bin/pecl install igbinary
/home/xc_vm/bin/php/bin/pecl install redis
```

### Configuring Redis Extension

During PECL installation, you will be prompted:

```
enable igbinary serializer support? [no] : yes
enable lzf compression support? [no] :
enable zstd compression support? [no] :
enable msgpack serializer support? [no] :
enable lz4 compression? [no] :
use system liblz4? [yes] :
```

Recommended answers: `yes` for igbinary, others optional.

---

## ⚙️ Building the `network` Binary

### 1. Install `PyInstaller` (one-time)

```bash
pip install -U pyinstaller
```

### 2. Build Binary

```bash
cd /home/xc_vm/bin
pyinstaller --onefile network.py
```

The executable will be located in:

```bash
/home/xc_vm/bin/dist/network
```

### 3. Move Binary to Final Location

```bash
mv /home/xc_vm/bin/network /home/xc_vm/bin/network
```

### 4. Clean Temporary Directories

```bash
rm -rf build dist network.spec __pycache__
```

---

## ✅ Conclusion

After completing all steps, you will have your own statically built binaries:

* `nginx`
* `nginx_rtmp`
* `php-fpm`
* `pecl` extensions
* `network` (Python script binary)

> 💡 **Tip:** If issues occur, check build logs or open an [issue](https://github.com/Vateron-Media/XC_VM/issues).

<p align="center">
  <a href="../../en/main-page.md"><b>⬅️ Back to Main Page</b></a>
</p>

---