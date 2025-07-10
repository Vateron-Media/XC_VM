/* main/php_config.h.  Generated from php_config.h.in by configure.  */
/* main/php_config.h.in.  Generated from configure.ac by autoheader.  */


#ifndef PHP_CONFIG_H
#define PHP_CONFIG_H

#if defined(__GNUC__) && __GNUC__ >= 4
# define ZEND_API __attribute__ ((visibility("default")))
# define ZEND_DLEXPORT __attribute__ ((visibility("default")))
#else
# define ZEND_API
# define ZEND_DLEXPORT
#endif

#define ZEND_DLIMPORT


/* Uses cmsgcred struct */
/* #undef ANC_CREDS_CMSGCRED */

/* Uses ucred struct */
#define ANC_CREDS_UCRED 1

/* */
/* #undef CDB_INCLUDE_FILE */

/* Whether to build bcmath as dynamic module */
/* #undef COMPILE_DL_BCMATH */

/* Whether to build bz2 as dynamic module */
/* #undef COMPILE_DL_BZ2 */

/* Whether to build calendar as dynamic module */
/* #undef COMPILE_DL_CALENDAR */

/* Whether to build ctype as dynamic module */
/* #undef COMPILE_DL_CTYPE */

/* Whether to build curl as dynamic module */
/* #undef COMPILE_DL_CURL */

/* Whether to build date as dynamic module */
/* #undef COMPILE_DL_DATE */

/* Whether to build dba as dynamic module */
/* #undef COMPILE_DL_DBA */

/* Whether to build dl_test as dynamic module */
/* #undef COMPILE_DL_DL_TEST */

/* Whether to build dom as dynamic module */
/* #undef COMPILE_DL_DOM */

/* Whether to build enchant as dynamic module */
/* #undef COMPILE_DL_ENCHANT */

/* Whether to build exif as dynamic module */
/* #undef COMPILE_DL_EXIF */

/* Whether to build ffi as dynamic module */
/* #undef COMPILE_DL_FFI */

/* Whether to build fileinfo as dynamic module */
/* #undef COMPILE_DL_FILEINFO */

/* Whether to build filter as dynamic module */
/* #undef COMPILE_DL_FILTER */

/* Whether to build ftp as dynamic module */
/* #undef COMPILE_DL_FTP */

/* Whether to build gd as dynamic module */
/* #undef COMPILE_DL_GD */

/* Whether to build gettext as dynamic module */
/* #undef COMPILE_DL_GETTEXT */

/* Whether to build gmp as dynamic module */
/* #undef COMPILE_DL_GMP */

/* Whether to build hash as dynamic module */
/* #undef COMPILE_DL_HASH */

/* Whether to build iconv as dynamic module */
/* #undef COMPILE_DL_ICONV */

/* Whether to build imap as dynamic module */
/* #undef COMPILE_DL_IMAP */

/* Whether to build intl as dynamic module */
/* #undef COMPILE_DL_INTL */

/* Whether to build json as dynamic module */
/* #undef COMPILE_DL_JSON */

/* Whether to build ldap as dynamic module */
/* #undef COMPILE_DL_LDAP */

/* Whether to build libxml as dynamic module */
/* #undef COMPILE_DL_LIBXML */

/* Whether to build mbstring as dynamic module */
/* #undef COMPILE_DL_MBSTRING */

/* Whether to build mysqli as dynamic module */
/* #undef COMPILE_DL_MYSQLI */

/* Whether to build mysqlnd as dynamic module */
/* #undef COMPILE_DL_MYSQLND */

/* Whether to build oci8 as dynamic module */
/* #undef COMPILE_DL_OCI8 */

/* Whether to build odbc as dynamic module */
/* #undef COMPILE_DL_ODBC */

/* Whether to build opcache as dynamic module */
#define COMPILE_DL_OPCACHE 1

/* Whether to build openssl as dynamic module */
/* #undef COMPILE_DL_OPENSSL */

/* Whether to build pcntl as dynamic module */
/* #undef COMPILE_DL_PCNTL */

/* Whether to build pcre as dynamic module */
/* #undef COMPILE_DL_PCRE */

/* Whether to build pdo as dynamic module */
/* #undef COMPILE_DL_PDO */

/* Whether to build pdo_dblib as dynamic module */
/* #undef COMPILE_DL_PDO_DBLIB */

/* Whether to build pdo_firebird as dynamic module */
/* #undef COMPILE_DL_PDO_FIREBIRD */

/* Whether to build pdo_mysql as dynamic module */
/* #undef COMPILE_DL_PDO_MYSQL */

/* Whether to build pdo_oci as dynamic module */
/* #undef COMPILE_DL_PDO_OCI */

/* Whether to build pdo_odbc as dynamic module */
/* #undef COMPILE_DL_PDO_ODBC */

/* Whether to build pdo_pgsql as dynamic module */
/* #undef COMPILE_DL_PDO_PGSQL */

/* Whether to build pdo_sqlite as dynamic module */
/* #undef COMPILE_DL_PDO_SQLITE */

/* Whether to build pgsql as dynamic module */
/* #undef COMPILE_DL_PGSQL */

/* Whether to build phar as dynamic module */
/* #undef COMPILE_DL_PHAR */

/* Whether to build posix as dynamic module */
/* #undef COMPILE_DL_POSIX */

/* Whether to build pspell as dynamic module */
/* #undef COMPILE_DL_PSPELL */

/* Whether to build readline as dynamic module */
/* #undef COMPILE_DL_READLINE */

/* Whether to build reflection as dynamic module */
/* #undef COMPILE_DL_REFLECTION */

/* Whether to build session as dynamic module */
/* #undef COMPILE_DL_SESSION */

/* Whether to build shmop as dynamic module */
/* #undef COMPILE_DL_SHMOP */

/* Whether to build simplexml as dynamic module */
/* #undef COMPILE_DL_SIMPLEXML */

/* Whether to build snmp as dynamic module */
/* #undef COMPILE_DL_SNMP */

/* Whether to build soap as dynamic module */
/* #undef COMPILE_DL_SOAP */

/* Whether to build sockets as dynamic module */
/* #undef COMPILE_DL_SOCKETS */

/* Whether to build sodium as dynamic module */
/* #undef COMPILE_DL_SODIUM */

/* Whether to build spl as dynamic module */
/* #undef COMPILE_DL_SPL */

/* Whether to build sqlite3 as dynamic module */
/* #undef COMPILE_DL_SQLITE3 */

/* Whether to build standard as dynamic module */
/* #undef COMPILE_DL_STANDARD */

/* Whether to build sysvmsg as dynamic module */
/* #undef COMPILE_DL_SYSVMSG */

/* Whether to build sysvsem as dynamic module */
/* #undef COMPILE_DL_SYSVSEM */

/* Whether to build sysvshm as dynamic module */
/* #undef COMPILE_DL_SYSVSHM */

/* Whether to build tidy as dynamic module */
/* #undef COMPILE_DL_TIDY */

/* Whether to build tokenizer as dynamic module */
/* #undef COMPILE_DL_TOKENIZER */

/* Whether to build xml as dynamic module */
/* #undef COMPILE_DL_XML */

/* Whether to build xmlreader as dynamic module */
/* #undef COMPILE_DL_XMLREADER */

/* Whether to build xmlwriter as dynamic module */
/* #undef COMPILE_DL_XMLWRITER */

/* Whether to build xsl as dynamic module */
/* #undef COMPILE_DL_XSL */

/* Whether to build zend_test as dynamic module */
/* #undef COMPILE_DL_ZEND_TEST */

/* Whether to build zip as dynamic module */
/* #undef COMPILE_DL_ZIP */

/* Whether to build zlib as dynamic module */
/* #undef COMPILE_DL_ZLIB */

/* */
#define COOKIE_IO_FUNCTIONS_T cookie_io_functions_t

/* */
#define COOKIE_SEEKER_USES_OFF64_T 1

/* Define if crypt_r has uses CRYPTD */
/* #undef CRYPT_R_CRYPTD */

/* Define if struct crypt_data requires _GNU_SOURCE */
/* #undef CRYPT_R_GNU_SOURCE */

/* Define if crypt_r uses struct crypt_data */
#define CRYPT_R_STRUCT_CRYPT_DATA 1

/* Define to 1 if using 'alloca.c'. */
/* #undef C_ALLOCA */

/* Define if the target system is darwin */
/* #undef DARWIN */

/* */
/* #undef DB1_INCLUDE_FILE */

/* */
/* #undef DB1_VERSION */

/* */
/* #undef DB2_INCLUDE_FILE */

/* */
/* #undef DB3_INCLUDE_FILE */

/* */
/* #undef DB4_INCLUDE_FILE */

/* */
/* #undef DBA_CDB */

/* */
/* #undef DBA_CDB_BUILTIN */

/* */
/* #undef DBA_CDB_MAKE */

/* */
/* #undef DBA_DB1 */

/* */
/* #undef DBA_DB2 */

/* */
/* #undef DBA_DB3 */

/* */
/* #undef DBA_DB4 */

/* */
/* #undef DBA_DBM */

/* */
/* #undef DBA_FLATFILE */

/* */
/* #undef DBA_GDBM */

/* */
/* #undef DBA_INIFILE */

/* */
/* #undef DBA_LMDB */

/* */
/* #undef DBA_NDBM */

/* */
/* #undef DBA_QDBM */

/* */
/* #undef DBA_TCADB */

/* */
/* #undef DBM_INCLUDE_FILE */

/* */
/* #undef DBM_VERSION */

/* */
#define DEFAULT_SHORT_OPEN_TAG "1"

/* Define if dlsym() requires a leading underscore in symbol names. */
/* #undef DLSYM_NEEDS_UNDERSCORE */

/* Whether to enable chroot() function */
/* #undef ENABLE_CHROOT_FUNC */

/* */
/* #undef GDBM_INCLUDE_FILE */

/* Define to 1 if 'TIOCGWINSZ' requires <sys/ioctl.h>. */
#define GWINSZ_IN_SYS_IOCTL 1

/* Whether 3 arg set_rebind_proc() */
/* #undef HAVE_3ARG_SETREBINDPROC */

/* Define when aarch64 CRC32 API is available. */
/* #undef HAVE_AARCH64_CRC32 */

/* */
/* #undef HAVE_ADABAS */

/* Whether you have AI_ALL */
#define HAVE_AI_ALL 1

/* Whether you have AI_IDN */
#define HAVE_AI_IDN 1

/* Whether you have AI_V4MAPPED */
#define HAVE_AI_V4MAPPED 1

/* whether the compiler supports __alignof__ */
#define HAVE_ALIGNOF 1

/* Define to 1 if you have 'alloca', as a function or macro. */
#define HAVE_ALLOCA 1

/* Define to 1 if <alloca.h> works. */
#define HAVE_ALLOCA_H 1

/* Define to 1 if you have the 'alphasort' function. */
#define HAVE_ALPHASORT 1

/* AppArmor confinement available */
/* #undef HAVE_APPARMOR */

/* */
/* #undef HAVE_ARGON2LIB */

/* Define to 1 if you have the <arpa/inet.h> header file. */
#define HAVE_ARPA_INET_H 1

/* Define to 1 if you have the <arpa/nameser.h> header file. */
#define HAVE_ARPA_NAMESER_H 1

/* Define to 1 if you have the 'asctime_r' function. */
#define HAVE_ASCTIME_R 1

/* Define if asm goto support */
#define HAVE_ASM_GOTO 1

/* Define to 1 if you have the 'asprintf' function. */
#define HAVE_ASPRINTF 1

/* Define to 1 if you have the 'atoll' function. */
#define HAVE_ATOLL 1

/* whether the compiler supports __attribute__ ((__aligned__)) */
#define HAVE_ATTRIBUTE_ALIGNED 1

/* Whether you have bcmath */
#define HAVE_BCMATH 1

/* */
#define HAVE_BIND_TEXTDOMAIN_CODESET 1

/* Define if system has broken getcwd */
/* #undef HAVE_BROKEN_GETCWD */

/* Konstantin Chuguev's iconv implementation */
/* #undef HAVE_BSD_ICONV */

/* */
#define HAVE_BUILD_DEFS_H 1

/* Define to 1 if gcc supports __sync_bool_compare_and_swap() a.o. */
#define HAVE_BUILTIN_ATOMIC 1

/* */
#define HAVE_BUNDLED_PCRE 1

/* */
/* #undef HAVE_BZ2 */

/* */
#define HAVE_CALENDAR 1

/* Libzip >= 1.6.0 with zip_register_cancel_callback_with_state function */
/* #undef HAVE_CANCEL_CALLBACK */

/* */
/* #undef HAVE_CAPSTONE */

/* Define to 1 if you have the 'chroot' function. */
#define HAVE_CHROOT 1

/* Define to 1 if you have the 'clearenv' function. */
#define HAVE_CLEARENV 1

/* */
/* #undef HAVE_CLI0CLI_H */

/* */
/* #undef HAVE_CLI0CORE_H */

/* */
/* #undef HAVE_CLI0DEFS_H */

/* */
/* #undef HAVE_CLI0ENV_H */

/* */
/* #undef HAVE_CLI0EXT_H */

/* do we have clock_gettime? */
#define HAVE_CLOCK_GETTIME 1

/* do we have clock_get_time? */
/* #undef HAVE_CLOCK_GET_TIME */

/* */
/* #undef HAVE_CODBC */

/* Define to 1 if you have the <CommonCrypto/CommonRandom.h> header file. */
/* #undef HAVE_COMMONCRYPTO_COMMONRANDOM_H */

/* whether __cpuid_count is available */
#define HAVE_CPUID_COUNT 1

/* Define to 1 if you have the <cpuid.h> header file. */
#define HAVE_CPUID_H 1

/* Define to 1 if you have the 'CreateProcess' function. */
/* #undef HAVE_CREATEPROCESS */

/* */
#define HAVE_CRYPT 1

/* Define to 1 if you have the <crypt.h> header file. */
#define HAVE_CRYPT_H 1

/* */
#define HAVE_CRYPT_R 1

/* Define to 1 if you have the 'ctermid' function. */
#define HAVE_CTERMID 1

/* Define to 1 if you have the 'ctime_r' function. */
#define HAVE_CTIME_R 1

/* */
#define HAVE_CTYPE 1

/* */
#define HAVE_CURL 1

/* Have cURL with old OpenSSL */
/* #undef HAVE_CURL_OLD_OPENSSL */

/* */
/* #undef HAVE_DBA */

/* Whether you want DBMaker */
/* #undef HAVE_DBMAKER */

/* */
#define HAVE_DCNGETTEXT 1

/* Define to 1 if you have the declaration of 'arc4random_buf', and to 0 if
   you don't. */
#define HAVE_DECL_ARC4RANDOM_BUF 0

/* Define to 1 if you have the declaration of 'strerror_r', and to 0 if you
   don't. */
#define HAVE_DECL_STRERROR_R 1

/* Define to 1 if you have the declaration of 'tzname', and to 0 if you don't.
   */
/* #undef HAVE_DECL_TZNAME */

/* do we have /dev/poll? */
/* #undef HAVE_DEVPOLL */

/* Define if the target system has /dev/urandom device */
#define HAVE_DEV_URANDOM 1

/* Define to 1 if you have the <dirent.h> header file. */
#define HAVE_DIRENT_H 1

/* Define to 1 if you have the <dlfcn.h> header file. */
#define HAVE_DLFCN_H 1

/* */
#define HAVE_DLOPEN 1

/* */
#define HAVE_DLSYM 1

/* Whether you have dmalloc */
/* #undef HAVE_DMALLOC */

/* */
#define HAVE_DNGETTEXT 1

/* Define to 1 if you have the <dns.h> header file. */
/* #undef HAVE_DNS_H */

/* */
/* #undef HAVE_DNS_SEARCH */

/* */
#define HAVE_DN_EXPAND 1

/* */
#define HAVE_DN_SKIPNAME 1

/* */
#define HAVE_DOM 1

/* Whether to enable DTrace support */
/* #undef HAVE_DTRACE */

/* */
/* #undef HAVE_EMPRESS */

/* */
/* #undef HAVE_ENCHANT */

/* enchant_broker_set_param since 1.5.0 and removed in 2.x */
/* #undef HAVE_ENCHANT_BROKER_SET_PARAM */

/* enchant_get_version since 1.6.0 */
/* #undef HAVE_ENCHANT_GET_VERSION */

/* Libzip >= 1.2.0 with encryption support */
/* #undef HAVE_ENCRYPTION */

/* do we have epoll? */
#define HAVE_EPOLL 1

/* */
/* #undef HAVE_ESOOB */

/* Whether you want EXIF (metadata from images) support */
#define HAVE_EXIF 1

/* Define to 1 if you have the 'explicit_bzero' function. */
#define HAVE_EXPLICIT_BZERO 1

/* Define to 1 if you have the 'explicit_memset' function. */
/* #undef HAVE_EXPLICIT_MEMSET */

/* Define to 1 if you have the 'fabsf' function. */
#define HAVE_FABSF 1

/* Define to 1 if you have the <fcntl.h> header file. */
#define HAVE_FCNTL_H 1

/* Define to 1 if you have the 'fdatasync' function. */
#define HAVE_FDATASYNC 1

/* Have ffi support */
/* #undef HAVE_FFI */

/* Whether libffi supports fastcall calling convention */
/* #undef HAVE_FFI_FASTCALL */

/* Whether libffi supports ms_cdecl calling convention */
/* #undef HAVE_FFI_MS_CDECL */

/* Whether libffi supports pascal calling convention */
/* #undef HAVE_FFI_PASCAL */

/* Whether libffi supports register calling convention */
/* #undef HAVE_FFI_REGISTER */

/* Whether libffi supports stdcall calling convention */
/* #undef HAVE_FFI_STDCALL */

/* Whether libffi supports sysv calling convention */
/* #undef HAVE_FFI_SYSV */

/* Whether libffi supports thiscall calling convention */
/* #undef HAVE_FFI_THISCALL */

/* Define to 1 if you have the 'flock' function. */
#define HAVE_FLOCK 1

/* Define to 1 if you have the 'floorf' function. */
#define HAVE_FLOORF 1

/* Define if flush should be called explicitly after a buffered io. */
/* #undef HAVE_FLUSHIO */

/* Define to 1 if your system has a working POSIX 'fnmatch' function. */
#define HAVE_FNMATCH 1

/* */
#define HAVE_FOPENCOOKIE 1

/* Define to 1 if you have the 'fork' function. */
#define HAVE_FORK 1

/* do we have acl support? */
/* #undef HAVE_FPM_ACL */

/* whether fpsetprec is present and usable */
/* #undef HAVE_FPSETPREC */

/* whether FPU control word can be manipulated by inline assembler */
#define HAVE_FPU_INLINE_ASM_X86 1

/* Define to 1 if you have the 'ftok' function. */
#define HAVE_FTOK 1

/* Whether you want FTP support */
/* #undef HAVE_FTP */

/* Whether FTP over SSL is supported */
/* #undef HAVE_FTP_SSL */

/* Define to 1 if the system has the `ifunc' function attribute */
#define HAVE_FUNC_ATTRIBUTE_IFUNC 1

/* Define to 1 if the system has the `target' function attribute */
#define HAVE_FUNC_ATTRIBUTE_TARGET 1

/* Define to 1 if you have the three-argument form of gethostbyname_r(). */
/* #undef HAVE_FUNC_GETHOSTBYNAME_R_3 */

/* Define to 1 if you have the five-argument form of gethostbyname_r(). */
/* #undef HAVE_FUNC_GETHOSTBYNAME_R_5 */

/* Define to 1 if you have the six-argument form of gethostbyname_r(). */
#define HAVE_FUNC_GETHOSTBYNAME_R_6 1

/* Define to 1 if you have the 'funopen' function. */
/* #undef HAVE_FUNOPEN */

/* Define to 1 if you have the 'gai_strerror' function. */
#define HAVE_GAI_STRERROR 1

/* Define if the target system has support for global register variables */
#define HAVE_GCC_GLOBAL_REGS 1

/* Whether you have gcov */
/* #undef HAVE_GCOV */

/* */
/* #undef HAVE_GD_AVIF */

/* */
#define HAVE_GD_BMP 1

/* */
#define HAVE_GD_BUNDLED 1

/* */
#define HAVE_GD_FREETYPE 1

/* */
/* #undef HAVE_GD_GET_INTERPOLATION */

/* */
#define HAVE_GD_JPG 1

/* */
/* #undef HAVE_GD_LIBVERSION */

/* */
#define HAVE_GD_PNG 1

/* */
#define HAVE_GD_TGA 1

/* */
/* #undef HAVE_GD_WEBP */

/* */
/* #undef HAVE_GD_XPM */

/* Define if you have the getaddrinfo function */
#define HAVE_GETADDRINFO 1

/* Define to 1 if you have the 'getcwd' function. */
#define HAVE_GETCWD 1

/* Define to 1 if you have the 'getgrgid_r' function. */
#define HAVE_GETGRGID_R 1

/* Define to 1 if you have the 'getgrnam_r' function. */
#define HAVE_GETGRNAM_R 1

/* Define to 1 if you have the 'getgroups' function. */
#define HAVE_GETGROUPS 1

/* */
#define HAVE_GETHOSTBYADDR 1

/* Define to 1 if you have some form of gethostbyname_r(). */
#define HAVE_GETHOSTBYNAME_R 1

/* */
#define HAVE_GETHOSTNAME 1

/* whether getifaddrs is present and usable */
#define HAVE_GETIFADDRS 1

/* Define to 1 if you have the 'getloadavg' function. */
#define HAVE_GETLOADAVG 1

/* Define to 1 if you have the 'getlogin' function. */
#define HAVE_GETLOGIN 1

/* Define to 1 if you have the 'getopt' function. */
#define HAVE_GETOPT 1

/* Define to 1 if you have the 'getpgid' function. */
#define HAVE_GETPGID 1

/* Define to 1 if you have the 'getpid' function. */
#define HAVE_GETPID 1

/* Define to 1 if you have the 'getpriority' function. */
#define HAVE_GETPRIORITY 1

/* Define to 1 if you have the 'getprotobyname' function. */
#define HAVE_GETPROTOBYNAME 1

/* Define to 1 if you have the 'getprotobynumber' function. */
#define HAVE_GETPROTOBYNUMBER 1

/* Define to 1 if you have the 'getpwnam_r' function. */
#define HAVE_GETPWNAM_R 1

/* Define to 1 if you have the 'getpwuid_r' function. */
#define HAVE_GETPWUID_R 1

/* Define to 1 if you have the 'getrlimit' function. */
#define HAVE_GETRLIMIT 1

/* Define to 1 if you have the 'getrusage' function. */
#define HAVE_GETRUSAGE 1

/* Define to 1 if you have the 'getservbyname' function. */
#define HAVE_GETSERVBYNAME 1

/* Define to 1 if you have the 'getservbyport' function. */
#define HAVE_GETSERVBYPORT 1

/* Define to 1 if you have the 'getsid' function. */
#define HAVE_GETSID 1

/* Define to 1 if you have the 'gettimeofday' function. */
#define HAVE_GETTIMEOFDAY 1

/* Define to 1 if you have the 'getwd' function. */
#define HAVE_GETWD 1

/* glibc's iconv implementation */
#define HAVE_GLIBC_ICONV 1

/* Define to 1 if you have the 'glob' function. */
#define HAVE_GLOB 1

/* */
/* #undef HAVE_GMP */

/* Define to 1 if you have the 'gmtime_r' function. */
#define HAVE_GMTIME_R 1

/* Define to 1 if you have the <grp.h> header file. */
#define HAVE_GRP_H 1

/* */
/* #undef HAVE_HISTORY_LIST */

/* Define to 1 if you have the 'hstrerror' function. */
#define HAVE_HSTRERROR 1

/* */
#define HAVE_HTONL 1

/* Define to enable copying PHP CODE pages into HUGE PAGES (experimental) */
#define HAVE_HUGE_CODE_PAGES 1

/* */
/* #undef HAVE_IBMDB2 */

/* IBM iconv implementation */
/* #undef HAVE_IBM_ICONV */

/* */
#define HAVE_ICONV 1

/* Define to 1 if you have the <ieeefp.h> header file. */
/* #undef HAVE_IEEEFP_H */

/* Define to 1 if you have the 'if_indextoname' function. */
#define HAVE_IF_INDEXTONAME 1

/* Define to 1 if you have the 'if_nametoindex' function. */
#define HAVE_IF_NAMETOINDEX 1

/* */
/* #undef HAVE_IMAP */

/* */
/* #undef HAVE_IMAP2000 */

/* */
/* #undef HAVE_IMAP2001 */

/* */
/* #undef HAVE_IMAP2004 */

/* */
/* #undef HAVE_IMAP_AUTH_GSS */

/* Whether IMAP extension has Kerberos support */
/* #undef HAVE_IMAP_KRB */

/* */
/* #undef HAVE_IMAP_MUTF7 */

/* */
/* #undef HAVE_IMAP_SSL */

/* Define to 1 if you have the <immintrin.h> header file. */
#define HAVE_IMMINTRIN_H 1

/* */
#define HAVE_INET_ATON 1

/* Define to 1 if you have the 'inet_ntoa' function. */
#define HAVE_INET_NTOA 1

/* Define to 1 if you have the 'inet_ntop' function. */
#define HAVE_INET_NTOP 1

/* Define to 1 if you have the 'inet_pton' function. */
#define HAVE_INET_PTON 1

/* Define to 1 if you have the 'initgroups' function. */
#define HAVE_INITGROUPS 1

/* Define to 1 if the system has the type 'int16_t'. */
#define HAVE_INT16_T 1

/* Define to 1 if the system has the type 'int32_t'. */
#define HAVE_INT32_T 1

/* Define to 1 if the system has the type 'int64_t'. */
#define HAVE_INT64_T 1

/* Define to 1 if the system has the type 'int8_t'. */
#define HAVE_INT8_T 1

/* Whether intmax_t is available */
#define HAVE_INTMAX_T 1

/* Define to 1 if you have the <inttypes.h> header file. */
#define HAVE_INTTYPES_H 1

/* */
/* #undef HAVE_IODBC */

/* */
/* #undef HAVE_IODBC_H */

/* Define to 1 if you have the <io.h> header file. */
/* #undef HAVE_IO_H */

/* Whether to enable IPv6 support */
#define HAVE_IPV6 1

/* */
/* #undef HAVE_ISQLEXT_H */

/* */
/* #undef HAVE_ISQL_H */

/* Define to enable JIT */
#define HAVE_JIT 1

/* whether to enable JavaScript Object Serialization support */
#define HAVE_JSON 1 

/* Define to 1 if you have the 'kill' function. */
#define HAVE_KILL 1

/* do we have kqueue? */
/* #undef HAVE_KQUEUE */

/* Define to 1 if you have the <langinfo.h> header file. */
#define HAVE_LANGINFO_H 1

/* Define to 1 if you have the 'lchown' function. */
#define HAVE_LCHOWN 1

/* */
/* #undef HAVE_LDAP */

/* Define to 1 if you have the 'ldap_control_find' function. */
/* #undef HAVE_LDAP_CONTROL_FIND */

/* Define to 1 if you have the 'ldap_extended_operation' function. */
/* #undef HAVE_LDAP_EXTENDED_OPERATION */

/* Define to 1 if you have the 'ldap_extended_operation_s' function. */
/* #undef HAVE_LDAP_EXTENDED_OPERATION_S */

/* Define to 1 if you have the 'ldap_parse_extended_result' function. */
/* #undef HAVE_LDAP_PARSE_EXTENDED_RESULT */

/* Define to 1 if you have the 'ldap_parse_reference' function. */
/* #undef HAVE_LDAP_PARSE_REFERENCE */

/* Define to 1 if you have the 'ldap_parse_result' function. */
/* #undef HAVE_LDAP_PARSE_RESULT */

/* Define to 1 if you have the 'ldap_passwd' function. */
/* #undef HAVE_LDAP_PASSWD */

/* Define to 1 if you have the 'ldap_refresh_s' function. */
/* #undef HAVE_LDAP_REFRESH_S */

/* LDAP SASL support */
/* #undef HAVE_LDAP_SASL */

/* Define to 1 if you have the 'ldap_start_tls_s' function. */
/* #undef HAVE_LDAP_START_TLS_S */

/* Define to 1 if you have the 'ldap_whoami_s' function. */
/* #undef HAVE_LDAP_WHOAMI_S */

/* */
/* #undef HAVE_LIBATOMIC */

/* */
/* #undef HAVE_LIBAVIF */

/* */
/* #undef HAVE_LIBBIND */

/* */
/* #undef HAVE_LIBBSD */

/* */
#define HAVE_LIBCRYPT 1

/* */
#define HAVE_LIBDL 1

/* */
/* #undef HAVE_LIBEDIT */

/* */
/* #undef HAVE_LIBEXPAT */

/* */
#define HAVE_LIBFREETYPE 1

/* */
/* #undef HAVE_LIBGD */

/* Whether libiconv is used */
/* #undef HAVE_LIBICONV */

/* */
#define HAVE_LIBINTL 1

/* */
#define HAVE_LIBJPEG 1

/* Define to 1 if you have the 'm' library (-lm). */
#define HAVE_LIBM 1

/* Whether you have libmm */
/* #undef HAVE_LIBMM */

/* */
/* #undef HAVE_LIBNETWORK */

/* */
/* #undef HAVE_LIBNSL */

/* */
/* #undef HAVE_LIBPAM */

/* */
#define HAVE_LIBPNG 1

/* Define to 1 if you have the 'pq' library (-lpq). */
/* #undef HAVE_LIBPQ */

/* */
/* #undef HAVE_LIBRARYMANAGER_H */

/* */
/* #undef HAVE_LIBREADLINE */

/* */
/* #undef HAVE_LIBRESOLV */

/* */
/* #undef HAVE_LIBROOT */

/* */
#define HAVE_LIBRT 1

/* */
/* #undef HAVE_LIBSOCKET */

/* */
/* #undef HAVE_LIBSODIUMLIB */

/* */
/* #undef HAVE_LIBUTIL */

/* */
/* #undef HAVE_LIBWEBP */

/* */
#define HAVE_LIBXML 1

/* Libzip >= 1.3.1 with zip_libzip_version function */
/* #undef HAVE_LIBZIP_VERSION */

/* Define to 1 if you have the 'localtime_r' function. */
#define HAVE_LOCALTIME_R 1

/* Define to 1 if the system has the type 'long double'. */
/* #undef HAVE_LONG_DOUBLE */

/* do we have SO_LISTENQxxx? */
/* #undef HAVE_LQ_SO_LISTENQ */

/* do we have TCP_INFO? */
#define HAVE_LQ_TCP_INFO 1

/* do we have mach_vm_read? */
/* #undef HAVE_MACH_VM_READ */

/* Define to 1 if you have the 'makedev' function. */
/* #undef HAVE_MAKEDEV */

/* Define to 1 if you have the <malloc.h> header file. */
#define HAVE_MALLOC_H 1

/* whether to have multibyte regex support */
/* #undef HAVE_MBREGEX */

/* whether to have multibyte string support */
/* #undef HAVE_MBSTRING */

/* Define to 1 if you have the 'memmem' function. */
#define HAVE_MEMMEM 1

/* Define to 1 if you have the 'memmove' function. */
#define HAVE_MEMMOVE 1

/* Libzip >= 1.7.0 with zip_*_method_supported functions */
/* #undef HAVE_METHOD_SUPPORTED */

/* Define to 1 if you have the <minix/config.h> header file. */
/* #undef HAVE_MINIX_CONFIG_H */

/* Define to 1 if you have the 'mkfifo' function. */
#define HAVE_MKFIFO 1

/* Define to 1 if you have the 'mknod' function. */
#define HAVE_MKNOD 1

/* Define to 1 if you have the 'mkstemp' function. */
#define HAVE_MKSTEMP 1

/* Define to 1 if you have the 'mmap' function. */
#define HAVE_MMAP 1

/* Define to 1 if you have the 'mprotect' function. */
#define HAVE_MPROTECT 1

/* Define to 1 if you have the 'mremap' function. */
#define HAVE_MREMAP 1

/* Whether you have MySQL */
/* #undef HAVE_MYSQL */

/* */
/* #undef HAVE_MYSQLILIB */

/* */
#define HAVE_NANOSLEEP 1

/* Define to 1 if you have the <netinet/in.h> header file. */
#define HAVE_NETINET_IN_H 1

/* Define to 1 if you have the <netinet/tcp.h> header file. */
#define HAVE_NETINET_TCP_H 1

/* Define to 1 if you have the <net/if.h> header file. */
#define HAVE_NET_IF_H 1

/* Whether utf8_mime2text() has new signature */
/* #undef HAVE_NEW_MIME2TEXT */

/* */
#define HAVE_NGETTEXT 1

/* Define to 1 if you have the 'nice' function. */
#define HAVE_NICE 1

/* Define to 1 if you have the 'nl_langinfo' function. */
#define HAVE_NL_LANGINFO 1

/* Define to 1 if you have the <nmmintrin.h> header file. */
#define HAVE_NMMINTRIN_H 1

/* Defined to 1 if the PHP OCI8 extension for Oracle Database is configured */
/* #undef HAVE_OCI8 */

/* Defined to 1 if PHP OCI8 DTrace support was enabled during configuration */
/* #undef HAVE_OCI8_DTRACE */

/* */
/* #undef HAVE_OCIENVCREATE */

/* */
/* #undef HAVE_OCIENVNLSCREATE */

/* */
/* #undef HAVE_OCILOBREAD2 */

/* */
/* #undef HAVE_OCISTMTFETCH2 */

/* Defined to 1 if OCI8 configuration located Oracle's Instant Client
   libraries */
/* #undef HAVE_OCI_INSTANT_CLIENT */

/* */
/* #undef HAVE_ODBC2 */

/* */
/* #undef HAVE_ODBCSDK_H */

/* */
/* #undef HAVE_ODBC_H */

/* */
#define HAVE_OPENPTY 1

/* Define to 1 if you have the <openssl/crypto.h> header file. */
/* #undef HAVE_OPENSSL_CRYPTO_H */

/* */
#define HAVE_OPENSSL_EXT 1

/* */
/* #undef HAVE_OPROFILE */

/* */
/* #undef HAVE_ORALDAP */

/* */
#define HAVE_PCRE_JIT_SUPPORT 1

/* */
/* #undef HAVE_PCRE_VALGRIND_SUPPORT */

/* */
/* #undef HAVE_PDO_DBLIB */

/* */
/* #undef HAVE_PDO_FIREBIRD */

/* Whether to build PostgreSQL for PDO support or not */
/* #undef HAVE_PDO_PGSQL */

/* Define to 1 if you have the pdo_sqlite extension enabled. */
/* #undef HAVE_PDO_SQLITELIB */

/* Whether to build PostgreSQL support or not */
/* #undef HAVE_PGSQL */

/* Whether libpq is compiled with --enable-multibyte */
/* #undef HAVE_PGSQL_WITH_MULTIBYTE_SUPPORT */

/* PostgreSQL 9.3 or later */
/* #undef HAVE_PG_LO64 */

/* */
#define HAVE_PHPDBG 1

/* */
/* #undef HAVE_PHPDBG_READLINE */

/* */
#define HAVE_PHP_SESSION 1

/* Define to 1 if you have the 'poll' function. */
#define HAVE_POLL 1

/* Define to 1 if you have the <poll.h> header file. */
#define HAVE_POLL_H 1

/* do we have port framework? */
/* #undef HAVE_PORT */

/* whether to include POSIX-like functions */
#define HAVE_POSIX 1

/* do we have prctl? */
#define HAVE_PRCTL 1

/* */
#define HAVE_PREAD 1

/* Libzip >= 1.3.0 with zip_register_progress_callback_with_state function */
/* #undef HAVE_PROGRESS_CALLBACK */

/* */
/* #undef HAVE_PSPELL */

/* Define to 1 if the PS_STRINGS thing exists. */
/* #undef HAVE_PS_STRINGS */

/* do we have ptrace? */
#define HAVE_PTRACE 1

/* Whether ptrdiff_t is available */
#define HAVE_PTRDIFF_T 1

/* Define to 1 if you have the <pty.h> header file. */
#define HAVE_PTY_H 1

/* Define to 1 if you have the 'putenv' function. */
#define HAVE_PUTENV 1

/* Define to 1 if you have the <pwd.h> header file. */
#define HAVE_PWD_H 1

/* */
#define HAVE_PWRITE 1

/* Define to 1 if you have the 'RAND_egd' function. */
/* #undef HAVE_RAND_EGD */

/* Define to 1 if you have the <resolv.h> header file. */
#define HAVE_RESOLV_H 1

/* */
/* #undef HAVE_RES_NDESTROY */

/* */
#define HAVE_RES_NSEARCH 1

/* */
#define HAVE_RES_SEARCH 1

/* */
/* #undef HAVE_RFC822_OUTPUT_ADDRESS_LIST */

/* Define to 1 if you have the 'rfork' function. */
/* #undef HAVE_RFORK */

/* */
/* #undef HAVE_RL_CALLBACK_READ_CHAR */

/* */
/* #undef HAVE_RL_COMPLETION_MATCHES */

/* */
/* #undef HAVE_RL_ON_NEW_LINE */

/* */
/* #undef HAVE_SAPDB */

/* Whether you have sockaddr_storage.ss_family */
#define HAVE_SA_SS_FAMILY 1

/* Define to 1 if you have the 'scandir' function. */
#define HAVE_SCANDIR 1

/* do we have select? */
#define HAVE_SELECT 1

/* */
#define HAVE_SEMUN 0

/* Define to 1 if you have the 'setegid' function. */
#define HAVE_SETEGID 1

/* Define to 1 if you have the 'setenv' function. */
#define HAVE_SETENV 1

/* Define to 1 if you have the 'seteuid' function. */
#define HAVE_SETEUID 1

/* Define to 1 if you have the 'setitimer' function. */
#define HAVE_SETITIMER 1

/* Define to 1 if you have the 'setpriority' function. */
#define HAVE_SETPRIORITY 1

/* Define to 1 if you have the 'setproctitle' function. */
/* #undef HAVE_SETPROCTITLE */

/* Define to 1 if you have the 'setproctitle_fast' function. */
/* #undef HAVE_SETPROCTITLE_FAST */

/* Define to 1 if you have the 'setrlimit' function. */
#define HAVE_SETRLIMIT 1

/* Define to 1 if you have the 'setsid' function. */
#define HAVE_SETSID 1

/* */
/* #undef HAVE_SETSOCKOPT */

/* Libzip >= 1.0.0 with zip_file_set_mtime */
/* #undef HAVE_SET_MTIME */

/* */
#define HAVE_SHMOP 1

/* Define if you have SysV IPC SHM support */
#define HAVE_SHM_IPC 1

/* Define if you have mmap(MAP_ANON) SHM support */
#define HAVE_SHM_MMAP_ANON 1

/* Define if you have POSIX mmap() SHM support */
#define HAVE_SHM_MMAP_POSIX 1

/* */
#define HAVE_SHM_OPEN 1

/* Define to 1 if you have the 'shutdown' function. */
#define HAVE_SHUTDOWN 1

/* */
/* #undef HAVE_SHUTDOWN_SNMP_LOGGING */

/* Define to 1 if you have the 'sigaction' function. */
#define HAVE_SIGACTION 1

/* Define to 1 if you have the 'sigprocmask' function. */
#define HAVE_SIGPROCMASK 1

/* Define to 1 if you have the 'sigsetjmp' function. */
/* #undef HAVE_SIGSETJMP */

/* Define to 1 if you have the 'sigtimedwait' function. */
#define HAVE_SIGTIMEDWAIT 1

/* Define to 1 if you have the 'sigwaitinfo' function. */
#define HAVE_SIGWAITINFO 1

/* */
#define HAVE_SIMPLEXML 1

/* Define is hash3 algo is available */
/* #undef HAVE_SLOW_HASH3 */

/* */
/* #undef HAVE_SNMP */

/* */
/* #undef HAVE_SNMP_SHA256 */

/* */
/* #undef HAVE_SNMP_SHA512 */

/* */
/* #undef HAVE_SOAP */

/* Whether struct sockaddr has field sa_len */
/* #undef HAVE_SOCKADDR_SA_LEN */

/* Whether you have struct sockaddr_storage */
#define HAVE_SOCKADDR_STORAGE 1

/* Define if sockaddr_un in sys/un.h contains a sun_len component */
/* #undef HAVE_SOCKADDR_UN_SUN_LEN */

/* */
#define HAVE_SOCKET 1

/* */
#define HAVE_SOCKETPAIR 1

/* */
#define HAVE_SOCKETS 1

/* Define to 1 if the system has the type 'socklen_t'. */
#define HAVE_SOCKLEN_T 1

/* */
/* #undef HAVE_SOLID */

/* */
/* #undef HAVE_SOLID_30 */

/* */
/* #undef HAVE_SOLID_35 */

/* */
/* #undef HAVE_SQLCLI1_H */

/* */
/* #undef HAVE_SQLDATASOURCES */

/* */
/* #undef HAVE_SQLEXT_H */

/* Define to 1 if you have the sqlite3 extension enabled. */
/* #undef HAVE_SQLITE3 */

/* have sqlite3_close_v2 */
/* #undef HAVE_SQLITE3_CLOSE_V2 */

/* have sqlite3_column_table_name */
/* #undef HAVE_SQLITE3_COLUMN_TABLE_NAME */

/* have sqlite3_errstr function */
/* #undef HAVE_SQLITE3_ERRSTR */

/* have sqlite3_expanded_sql function */
/* #undef HAVE_SQLITE3_EXPANDED_SQL */

/* */
/* #undef HAVE_SQLTYPES_H */

/* */
/* #undef HAVE_SQLUCODE_H */

/* */
/* #undef HAVE_SQLUNIX_H */

/* */
/* #undef HAVE_SQL_H */

/* Whether ssize_t is available */
#define HAVE_SSIZE_T 1

/* Define to 1 if you have the 'statfs' function. */
#define HAVE_STATFS 1

/* Define to 1 if you have the 'statvfs' function. */
#define HAVE_STATVFS 1

/* Define to 1 if you have the <stdint.h> header file. */
#define HAVE_STDINT_H 1

/* Define to 1 if you have the <stdio.h> header file. */
#define HAVE_STDIO_H 1

/* Define to 1 if you have the <stdlib.h> header file. */
#define HAVE_STDLIB_H 1

/* Define to 1 if you have the 'std_syslog' function. */
/* #undef HAVE_STD_SYSLOG */

/* Define to 1 if you have the 'strcasecmp' function. */
#define HAVE_STRCASECMP 1

/* Define if you have 'strerror_r'. */
#define HAVE_STRERROR_R 1

/* Define to 1 if you have the <strings.h> header file. */
#define HAVE_STRINGS_H 1

/* Define to 1 if you have the <string.h> header file. */
#define HAVE_STRING_H 1

/* Define to 1 if you have the 'strlcat' function. */
/* #undef HAVE_STRLCAT */

/* Define to 1 if you have the 'strlcpy' function. */
/* #undef HAVE_STRLCPY */

/* Define to 1 if you have the 'strndup' function. */
#define HAVE_STRNDUP 1

/* Define to 1 if you have the 'strnlen' function. */
#define HAVE_STRNLEN 1

/* Define to 1 if you have the 'strptime' function. */
#define HAVE_STRPTIME 1

/* whether strptime() declaration fails */
#define HAVE_STRPTIME_DECL_FAILS 1

/* Define to 1 if you have the 'strtok_r' function. */
#define HAVE_STRTOK_R 1

/* Define to 1 if you have the 'strtoll' function. */
#define HAVE_STRTOLL 1

/* whether you have struct flock */
#define HAVE_STRUCT_FLOCK 1

/* Define to 1 if 'st_blksize' is a member of 'struct stat'. */
#define HAVE_STRUCT_STAT_ST_BLKSIZE 1

/* Define to 1 if 'st_blocks' is a member of 'struct stat'. */
#define HAVE_STRUCT_STAT_ST_BLOCKS 1

/* Define to 1 if 'st_rdev' is a member of 'struct stat'. */
#define HAVE_STRUCT_STAT_ST_RDEV 1

/* Define to 1 if 'tm_gmtoff' is a member of 'struct tm'. */
#define HAVE_STRUCT_TM_TM_GMTOFF 1

/* Define to 1 if 'tm_zone' is a member of 'struct tm'. */
#define HAVE_STRUCT_TM_TM_ZONE 1

/* Define to 1 if your 'struct stat' has 'st_blocks'. Deprecated, use
   'HAVE_STRUCT_STAT_ST_BLOCKS' instead. */
#define HAVE_ST_BLOCKS 1

/* Define to 1 if you have the 'symlink' function. */
#define HAVE_SYMLINK 1

/* do we have sysconf? */
#define HAVE_SYSCONF 1

/* Define to 1 if you have the <sysexits.h> header file. */
#define HAVE_SYSEXITS_H 1

/* Define to 1 if you have the <syslog.h> header file. */
#define HAVE_SYSLOG_H 1

/* FPM use systemd integration */
/* #undef HAVE_SYSTEMD */

/* Define to 1 if you have the <systemd/sd-daemon.h> header file. */
/* #undef HAVE_SYSTEMD_SD_DAEMON_H */

/* */
#define HAVE_SYSVMSG 1

/* */
#define HAVE_SYSVSEM 1

/* */
#define HAVE_SYSVSHM 1

/* Define to 1 if you have the <sys/acl.h> header file. */
/* #undef HAVE_SYS_ACL_H */

/* Define to 1 if you have the <sys/apparmor.h> header file. */
/* #undef HAVE_SYS_APPARMOR_H */

/* Define to 1 if you have the <sys/file.h> header file. */
#define HAVE_SYS_FILE_H 1

/* Define to 1 if you have the <sys/ioctl.h> header file. */
#define HAVE_SYS_IOCTL_H 1

/* Define to 1 if you have the <sys/ipc.h> header file. */
#define HAVE_SYS_IPC_H 1

/* Define to 1 if you have the <sys/loadavg.h> header file. */
/* #undef HAVE_SYS_LOADAVG_H */

/* Define to 1 if you have the <sys/mkdev.h> header file. */
/* #undef HAVE_SYS_MKDEV_H */

/* Define to 1 if you have the <sys/mman.h> header file. */
#define HAVE_SYS_MMAN_H 1

/* Define to 1 if you have the <sys/mount.h> header file. */
#define HAVE_SYS_MOUNT_H 1

/* Define to 1 if you have the <sys/param.h> header file. */
#define HAVE_SYS_PARAM_H 1

/* Define to 1 if you have the <sys/poll.h> header file. */
#define HAVE_SYS_POLL_H 1

/* Define to 1 if you have the <sys/pstat.h> header file. */
/* #undef HAVE_SYS_PSTAT_H */

/* Define to 1 if you have the <sys/resource.h> header file. */
#define HAVE_SYS_RESOURCE_H 1

/* Define to 1 if you have the <sys/sdt.h> header file. */
/* #undef HAVE_SYS_SDT_H */

/* Define to 1 if you have the <sys/select.h> header file. */
#define HAVE_SYS_SELECT_H 1

/* Define to 1 if you have the <sys/socket.h> header file. */
#define HAVE_SYS_SOCKET_H 1

/* Define to 1 if you have the <sys/sockio.h> header file. */
/* #undef HAVE_SYS_SOCKIO_H */

/* Define to 1 if you have the <sys/statfs.h> header file. */
#define HAVE_SYS_STATFS_H 1

/* Define to 1 if you have the <sys/statvfs.h> header file. */
#define HAVE_SYS_STATVFS_H 1

/* Define to 1 if you have the <sys/stat.h> header file. */
#define HAVE_SYS_STAT_H 1

/* Define to 1 if you have the <sys/sysexits.h> header file. */
/* #undef HAVE_SYS_SYSEXITS_H */

/* Define to 1 if you have the <sys/sysmacros.h> header file. */
#define HAVE_SYS_SYSMACROS_H 1

/* Define to 1 if you have the <sys/time.h> header file. */
#define HAVE_SYS_TIME_H 1

/* Define to 1 if you have the <sys/types.h> header file. */
#define HAVE_SYS_TYPES_H 1

/* Define to 1 if you have the <sys/uio.h> header file. */
#define HAVE_SYS_UIO_H 1

/* Define to 1 if you have the <sys/un.h> header file. */
#define HAVE_SYS_UN_H 1

/* Define to 1 if you have the <sys/utsname.h> header file. */
#define HAVE_SYS_UTSNAME_H 1

/* Define to 1 if you have the <sys/vfs.h> header file. */
#define HAVE_SYS_VFS_H 1

/* Define to 1 if you have the <sys/wait.h> header file. */
#define HAVE_SYS_WAIT_H 1

/* */
/* #undef HAVE_TIDY */

/* defined if tidybuffio.h exists */
/* #undef HAVE_TIDYBUFFIO_H */

/* */
/* #undef HAVE_TIDYOPTGETDOC */

/* defined if tidyp.h exists */
/* #undef HAVE_TIDYP_H */

/* */
/* #undef HAVE_TIDYRELEASEDATE */

/* defined if tidy.h exists */
/* #undef HAVE_TIDY_H */

/* Have timelib_config.h */
#define HAVE_TIMELIB_CONFIG_H 1

/* */
#define HAVE_TIMER_CREATE 1

/* do we have times? */
#define HAVE_TIMES 1

/* Define to 1 if you have the <tmmintrin.h> header file. */
#define HAVE_TMMINTRIN_H 1

/* Define to 1 if your 'struct tm' has 'tm_zone'. Deprecated, use
   'HAVE_STRUCT_TM_TM_ZONE' instead. */
#define HAVE_TM_ZONE 1

/* Whether you have a working ttyname_r */
#define HAVE_TTYNAME_R 1

/* Define to 1 if you don't have 'tm_zone' but do have the external array
   'tzname'. */
/* #undef HAVE_TZNAME */

/* Define to 1 if you have the 'tzset' function. */
#define HAVE_TZSET 1

/* */
/* #undef HAVE_UDBCEXT_H */

/* Define to 1 if the system has the type 'uint16_t'. */
#define HAVE_UINT16_T 1

/* Define to 1 if the system has the type 'uint32_t'. */
#define HAVE_UINT32_T 1

/* Define to 1 if the system has the type 'uint64_t'. */
#define HAVE_UINT64_T 1

/* Define to 1 if the system has the type 'uint8_t'. */
#define HAVE_UINT8_T 1

/* Define to 1 if you have the <unistd.h> header file. */
#define HAVE_UNISTD_H 1

/* */
/* #undef HAVE_UNIXODBC */

/* Define to 1 if you have the <unix.h> header file. */
/* #undef HAVE_UNIX_H */

/* Define to 1 if you have the 'unsetenv' function. */
#define HAVE_UNSETENV 1

/* Define to 1 if you have the 'unshare' function. */
#define HAVE_UNSHARE 1

/* */
/* #undef HAVE_UODBC */

/* Define to 1 if you have the 'usleep' function. */
#define HAVE_USLEEP 1

/* Define to 1 if you have the 'utime' function. */
#define HAVE_UTIME 1

/* Define to 1 if you have the 'utimes' function. */
#define HAVE_UTIMES 1

/* Define to 1 if you have the <utime.h> header file. */
#define HAVE_UTIME_H 1

/* Whether struct utsname has domainname */
#define HAVE_UTSNAME_DOMAINNAME 1

/* */
/* #undef HAVE_VALGRIND */

/* Define to 1 if you have the 'vasprintf' function. */
#define HAVE_VASPRINTF 1

/* Define to 1 if you have the 'wait3' function. */
#define HAVE_WAIT3 1

/* Define to 1 if you have the 'wait4' function. */
#define HAVE_WAIT4 1

/* Define to 1 if you have the 'waitpid' function. */
#define HAVE_WAITPID 1

/* Define to 1 if you have the <wchar.h> header file. */
#define HAVE_WCHAR_H 1

/* Define to 1 if you have the <wmmintrin.h> header file. */
#define HAVE_WMMINTRIN_H 1

/* */
#define HAVE_XML 1

/* */
#define HAVE_XMLREADER 1

/* */
#define HAVE_XMLWRITER 1

/* */
/* #undef HAVE_XPM */

/* */
#define HAVE_XSL 1

/* */
#define HAVE_XSL_EXSLT 1

/* */
/* #undef HAVE_ZIP */

/* */
#define HAVE_ZLIB 1

/* whether _controlfp is present usable */
/* #undef HAVE__CONTROLFP */

/* whether _controlfp_s is present and usable */
/* #undef HAVE__CONTROLFP_S */

/* whether _FPU_SETCW is present and usable */
#define HAVE__FPU_SETCW 1

/* */
/* #undef HAVE___ATOMIC_EXCHANGE_1 */

/* iconv() is aliased to libiconv() in -liconv */
/* #undef ICONV_ALIASED_LIBICONV */

/* Whether iconv supports IGNORE */
#define ICONV_BROKEN_IGNORE 1

/* */
/* #undef JISX0208 */

/* */
/* #undef LMDB_INCLUDE_FILE */

/* Whether asctime_r is declared */
/* #undef MISSING_ASCTIME_R_DECL */

/* Whether ctime_r is declared */
/* #undef MISSING_CTIME_R_DECL */

/* */
#define MISSING_FCLOSE_DECL 0

/* Whether gmtime_r is declared */
/* #undef MISSING_GMTIME_R_DECL */

/* Whether localtime_r is declared */
/* #undef MISSING_LOCALTIME_R_DECL */

/* Whether strtok_r is declared */
/* #undef MISSING_STRTOK_R_DECL */

/* Whether mysqlnd is enabled */
#define MYSQLI_USE_MYSQLND 1

/* Enable compressed protocol support */
#define MYSQLND_COMPRESSION_ENABLED 1

/* Enable mysqlnd code that uses OpenSSL directly */
#define MYSQLND_HAVE_SSL 1

/* Enable core mysqlnd SSL code */
#define MYSQLND_SSL_SUPPORTED 1

/* */
/* #undef NDBM_INCLUDE_FILE */

/* The highest supported ODBC version */
#define ODBCVER 0x0350

/* Define to the address where bug reports for this package should be sent. */
/* #undef PACKAGE_BUGREPORT */

/* Define to the full name of this package. */
/* #undef PACKAGE_NAME */

/* Define to the full name and version of this package. */
/* #undef PACKAGE_STRING */

/* Define to the one symbol short name of this package. */
/* #undef PACKAGE_TARNAME */

/* Define to the home page for this package. */
/* #undef PACKAGE_URL */

/* Define to the version of this package. */
/* #undef PACKAGE_VERSION */

/* */
#define PCRE2_CODE_UNIT_WIDTH 8

/* */
/* #undef PDO_MYSQL_UNIX_ADDR */

/* Whether pdo_mysql uses mysqlnd */
#define PDO_USE_MYSQLND 1

/* */
#define PHAR_HAVE_OPENSSL 1

/* */
#define PHPDBG_DEBUG 0

/* build architecture */
/* #undef PHP_BUILD_ARCH */

/* used compiler for build */
/* #undef PHP_BUILD_COMPILER */

/* build provider */
/* #undef PHP_BUILD_PROVIDER */

/* builder uname output */
#define PHP_BUILD_SYSTEM "Linux divarion 5.15.0-84-generic #93-Ubuntu SMP Tue Sep 5 17:16:10 UTC 2023 x86_64 x86_64 x86_64 GNU/Linux"

/* Define if your system has fork/vfork/CreateProcess */
#define PHP_CAN_SUPPORT_PROC_OPEN 1

/* fpm group name */
#define PHP_FPM_GROUP "xc_vm"

/* fpm systemd service type */
#define PHP_FPM_SYSTEMD "simple"

/* fpm user name */
#define PHP_FPM_USER "xc_vm"

/* Whether the compiler supports __builtin_clz */
#define PHP_HAVE_BUILTIN_CLZ 1

/* Whether the compiler supports __builtin_clzl */
#define PHP_HAVE_BUILTIN_CLZL 1

/* Whether the compiler supports __builtin_clzll */
#define PHP_HAVE_BUILTIN_CLZLL 1

/* Whether the compiler supports __builtin_cpu_init */
#define PHP_HAVE_BUILTIN_CPU_INIT 1

/* Whether the compiler supports __builtin_cpu_supports */
#define PHP_HAVE_BUILTIN_CPU_SUPPORTS 1

/* Whether the compiler supports __builtin_ctzl */
#define PHP_HAVE_BUILTIN_CTZL 1

/* Whether the compiler supports __builtin_ctzll */
#define PHP_HAVE_BUILTIN_CTZLL 1

/* Whether the compiler supports __builtin_expect */
#define PHP_HAVE_BUILTIN_EXPECT 1

/* Whether the compiler supports __builtin_saddll_overflow */
#define PHP_HAVE_BUILTIN_SADDLL_OVERFLOW 1

/* Whether the compiler supports __builtin_saddl_overflow */
#define PHP_HAVE_BUILTIN_SADDL_OVERFLOW 1

/* Whether the compiler supports __builtin_smulll_overflow */
#define PHP_HAVE_BUILTIN_SMULLL_OVERFLOW 1

/* Whether the compiler supports __builtin_smull_overflow */
#define PHP_HAVE_BUILTIN_SMULL_OVERFLOW 1

/* Whether the compiler supports __builtin_ssubll_overflow */
#define PHP_HAVE_BUILTIN_SSUBLL_OVERFLOW 1

/* Whether the compiler supports __builtin_ssubl_overflow */
#define PHP_HAVE_BUILTIN_SSUBL_OVERFLOW 1

/* Whether you have HP-UX 10.x */
/* #undef PHP_HPUX_TIME_R */

/* Which iconv implementation to use */
#define PHP_ICONV_IMPL "glibc"

/* Whether you have IRIX-style functions */
/* #undef PHP_IRIX_TIME_R */

/* */
#define PHP_MHASH_BC 1

/* */
/* #undef PHP_MYSQL_UNIX_SOCK_ADDR */

/* */
/* #undef PHP_OCI8_DEF_DIR */

/* */
/* #undef PHP_OCI8_DEF_SHARED_LIBADD */

/* define to 1 if oniguruma has an invalid entry for KOI8 encoding */
/* #undef PHP_ONIG_BAD_KOI8_ENTRY */

/* uname output */
#define PHP_OS "Linux"

/* */
/* #undef PHP_PDO_OCI_CLIENT_VERSION */

/* whether pread64 is default */
/* #undef PHP_PREAD_64 */

/* whether pwrite64 is default */
/* #undef PHP_PWRITE_64 */

/* */
#define PHP_SIGCHILD 0

/* uname -a output */
#define PHP_UNAME "Linux divarion 5.15.0-84-generic #93-Ubuntu SMP Tue Sep 5 17:16:10 UTC 2023 x86_64 x86_64 x86_64 GNU/Linux"

/* Whether PHP has to use its own crypt_r */
#define PHP_USE_PHP_CRYPT_R 1

/* Use dlopen with RTLD_NOW instead of RTLD_LAZY */
/* #undef PHP_USE_RTLD_NOW */

/* whether write(2) works */
#define PHP_WRITE_STDOUT 1

/* /proc/pid/mem interface */
#define PROC_MEM_FILE "mem"

/* Whether to use Pthreads */
/* #undef PTHREADS */

/* */
/* #undef QDBM_INCLUDE_FILE */

/* The size of 'int', as computed by sizeof. */
#define SIZEOF_INT 4

/* Size of intmax_t */
#define SIZEOF_INTMAX_T 8

/* The size of 'long', as computed by sizeof. */
#define SIZEOF_LONG 8

/* The size of 'long long', as computed by sizeof. */
#define SIZEOF_LONG_LONG 8

/* The size of 'off_t', as computed by sizeof. */
#define SIZEOF_OFF_T 8

/* Size of ptrdiff_t */
#define SIZEOF_PTRDIFF_T 8

/* The size of 'short', as computed by sizeof. */
#define SIZEOF_SHORT 2

/* The size of 'size_t', as computed by sizeof. */
#define SIZEOF_SIZE_T 8

/* Size of ssize_t */
#define SIZEOF_SSIZE_T 8

/* have sqlite3 with extension support */
/* #undef SQLITE_OMIT_LOAD_EXTENSION */

/* Needed in sqlunix.h for wchar defs */
/* #undef SS_FBX */

/* Needed in sqlunix.h */
/* #undef SS_LINUX */

/* If using the C implementation of alloca, define if you know the
   direction of stack growth for your system; otherwise it will be
   automatically deduced at runtime.
	STACK_DIRECTION > 0 => grows toward higher addresses
	STACK_DIRECTION < 0 => grows toward lower addresses
	STACK_DIRECTION = 0 => direction of growth unknown */
/* #undef STACK_DIRECTION */

/* Define to 1 if all of the C89 standard headers exist (not just the ones
   required in a freestanding environment). This macro is provided for
   backward compatibility; new code need not use it. */
#define STDC_HEADERS 1

/* Define to 1 if strerror_r returns char *. */
#define STRERROR_R_CHAR_P 1

/* */
/* #undef TCADB_INCLUDE_FILE */

/* Define to 1 if your <sys/time.h> declares 'struct tm'. */
/* #undef TM_IN_SYS_TIME */

/* */
/* #undef USE_GD_JISX0208 */

/* Define if cross-process locking is required by accept() */
/* #undef USE_LOCKING */

/* Use system default cipher list instead of hardcoded value */
/* #undef USE_OPENSSL_SYSTEM_CIPHERS */

/* Enable extensions on AIX, Interix, z/OS.  */
#ifndef _ALL_SOURCE
# define _ALL_SOURCE 1
#endif
/* Enable general extensions on macOS.  */
#ifndef _DARWIN_C_SOURCE
# define _DARWIN_C_SOURCE 1
#endif
/* Enable general extensions on Solaris.  */
#ifndef __EXTENSIONS__
# define __EXTENSIONS__ 1
#endif
/* Enable GNU extensions on systems that have them.  */
#ifndef _GNU_SOURCE
# define _GNU_SOURCE 1
#endif
/* Enable X/Open compliant socket functions that do not require linking
   with -lxnet on HP-UX 11.11.  */
#ifndef _HPUX_ALT_XOPEN_SOCKET_API
# define _HPUX_ALT_XOPEN_SOCKET_API 1
#endif
/* Identify the host operating system as Minix.
   This macro does not affect the system headers' behavior.
   A future release of Autoconf may stop defining this macro.  */
#ifndef _MINIX
/* # undef _MINIX */
#endif
/* Enable general extensions on NetBSD.
   Enable NetBSD compatibility extensions on Minix.  */
#ifndef _NETBSD_SOURCE
# define _NETBSD_SOURCE 1
#endif
/* Enable OpenBSD compatibility extensions on NetBSD.
   Oddly enough, this does nothing on OpenBSD.  */
#ifndef _OPENBSD_SOURCE
# define _OPENBSD_SOURCE 1
#endif
/* Define to 1 if needed for POSIX-compatible behavior.  */
#ifndef _POSIX_SOURCE
/* # undef _POSIX_SOURCE */
#endif
/* Define to 2 if needed for POSIX-compatible behavior.  */
#ifndef _POSIX_1_SOURCE
/* # undef _POSIX_1_SOURCE */
#endif
/* Enable POSIX-compatible threading on Solaris.  */
#ifndef _POSIX_PTHREAD_SEMANTICS
# define _POSIX_PTHREAD_SEMANTICS 1
#endif
/* Enable extensions specified by ISO/IEC TS 18661-5:2014.  */
#ifndef __STDC_WANT_IEC_60559_ATTRIBS_EXT__
# define __STDC_WANT_IEC_60559_ATTRIBS_EXT__ 1
#endif
/* Enable extensions specified by ISO/IEC TS 18661-1:2014.  */
#ifndef __STDC_WANT_IEC_60559_BFP_EXT__
# define __STDC_WANT_IEC_60559_BFP_EXT__ 1
#endif
/* Enable extensions specified by ISO/IEC TS 18661-2:2015.  */
#ifndef __STDC_WANT_IEC_60559_DFP_EXT__
# define __STDC_WANT_IEC_60559_DFP_EXT__ 1
#endif
/* Enable extensions specified by C23 Annex F.  */
#ifndef __STDC_WANT_IEC_60559_EXT__
# define __STDC_WANT_IEC_60559_EXT__ 1
#endif
/* Enable extensions specified by ISO/IEC TS 18661-4:2015.  */
#ifndef __STDC_WANT_IEC_60559_FUNCS_EXT__
# define __STDC_WANT_IEC_60559_FUNCS_EXT__ 1
#endif
/* Enable extensions specified by C23 Annex H and ISO/IEC TS 18661-3:2015.  */
#ifndef __STDC_WANT_IEC_60559_TYPES_EXT__
# define __STDC_WANT_IEC_60559_TYPES_EXT__ 1
#endif
/* Enable extensions specified by ISO/IEC TR 24731-2:2010.  */
#ifndef __STDC_WANT_LIB_EXT2__
# define __STDC_WANT_LIB_EXT2__ 1
#endif
/* Enable extensions specified by ISO/IEC 24747:2009.  */
#ifndef __STDC_WANT_MATH_SPEC_FUNCS__
# define __STDC_WANT_MATH_SPEC_FUNCS__ 1
#endif
/* Enable extensions on HP NonStop.  */
#ifndef _TANDEM_SOURCE
# define _TANDEM_SOURCE 1
#endif
/* Enable X/Open extensions.  Define to 500 only if necessary
   to make mbstate_t available.  */
#ifndef _XOPEN_SOURCE
/* # undef _XOPEN_SOURCE */
#endif


/* Define if processor uses big-endian word */
/* #undef WORDS_BIGENDIAN */

/* */
#define ZEND_DEBUG 0

/* */
#define ZEND_FIBER_ASM 1

/* */
/* #undef ZEND_FIBER_UCONTEXT */

/* Use zend max execution timers */
/* #undef ZEND_MAX_EXECUTION_TIMERS */

/* */
#define ZEND_MM_ALIGNMENT 8

/* */
#define ZEND_MM_ALIGNMENT_LOG2 3

/* Use zend signal handling */
#define ZEND_SIGNALS 1

/* */
/* #undef ZTS */

/* */
/* #undef _XOPEN_SOURCE */

/* Define when using musl libc */
/* #undef __MUSL__ */

/* Define as 'int' if <sys/types.h> doesn't define. */
/* #undef gid_t */

/* */
/* #undef in_addr_t */

/* Define to '__inline__' or '__inline' if that's what the C compiler
   calls it, or to nothing if 'inline' is not supported under any name.  */
#ifndef __cplusplus
/* #undef inline */
#endif

/* Define as 'unsigned int' if <stddef.h> doesn't define. */
/* #undef size_t */

/* Define as 'int' if <sys/types.h> doesn't define. */
/* #undef uid_t */


#include <stdlib.h>

#ifdef HAVE_SYS_TYPES_H
# include <sys/types.h>
#endif

#ifdef HAVE_SYS_SELECT_H
#include <sys/select.h>
#endif

#include <string.h>

#endif /* PHP_CONFIG_H */

