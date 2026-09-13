# XC_VM — دليل واجهات برمجة تطبيقات كود التفعيل الذكي (Active Codes API Guide)

دليل شامل وتفصيلي ومفصول لكيفية استخدام والربط مع نظام **أكواد التفعيل الذكية (Smart Activation Codes)** في منصة **XC_VM** بالطريقة القياسية والرسمية المعتمدة في لوحة التحكم باستخدام نظام **أكواد الوصول (Access Codes)**.

---

## 📑 الفهرس العام (Table of Contents)

- [نظرة عامة على معمارية الربط (Architecture Overview)](#نظرة-عامة-على-معمارية-الربط)
- [الجزء الأول: دليل واجهة المدير (PART 1: Admin API)](#الجزء-الأول-دليل-واجهة-المدير-part-1-admin-api)
  - [1. كيفية الإعداد والحصول على كود الوصول والمفتاح](#1-كيفية-الإعداد-والحصول-على-كود-الوصول-والمفتاح)
  - [2. صيغة الاستدعاء العامة (Request Format)](#2-صيغة-الاستدعاء-العامة-request-format)
  - [3. العمليات والإجراءات المدعومة (Actions Reference)](#3-العمليات-والإجراءات-المدعومة-actions-reference)
    - [3.1 عرض جميع أكواد التفعيل في السيرفر (`get_active_codes`)](#31-عرض-جميع-أكواد-التفعيل-في-السيرفر-get_active_codes)
    - [3.2 جلب تفاصيل كود محدد وروابط المشغل (`get_active_code`)](#32-جلب-تفاصيل-كود-محدد-وروابط-المشغل-get_active_code)
    - [3.3 توليد أكواد تفعيل جديدة (فردي أو بالجملة) (`generate_active_codes`)](#33-توليد-أكواد-تفعيل-جديدة-فردي-أو-بالجملة-generate_active_codes)
    - [3.4 تعديل بيانات كود التفعيل والاشتراك (`edit_active_code`)](#34-تعديل-بيانات-كود-التفعيل-والاشتراك-edit_active_code)
    - [3.5 فحص حالة كود بدون استهلاكه (`check_active_code`)](#35-فحص-حالة-كود-بدون-استهلاكه-check_active_code)
    - [3.6 إيقاف كود التفعيل مؤقتاً (`disable_active_code`)](#36-إيقاف-كود-التفعيل-مؤقتاً-disable_active_code)
    - [3.7 إعادة تفعيل كود موقوف (`enable_active_code`)](#37-إعادة-تفعيل-كود-موقوف-enable_active_code)
    - [3.8 فك قفل جهاز العميل والماك (`reset_active_code_device`)](#38-فك-قفل-جهاز-العميل-والماك-reset_active_code_device)
    - [3.9 حذف كود تفعيل نهائياً (`delete_active_code`)](#39-حذف-كود-تفعيل-نهائياً-delete_active_code)
    - [3.10 العمليات الجماعية على عدة أكواد (`mass_active_codes`)](#310-العمليات-الجماعية-على-عدة-أكواد-mass_active_codes)
    - [3.11 إحصائيات وأرصدة الباتشات (`get_active_codes_batches`)](#311-إحصائيات-وأرصدة-الباتشات-get_active_codes_batches)
    - [3.12 تصدير كروت وبطاقات الباتش (`export_active_code_batch`)](#312-تصدير-كروت-وبطاقات-الباتش-export_active_code_batch)
  - [4. أمثلة برمجية لاستدعاء Admin API](#4-أمثلة-برمجية-لاستدعاء-admin-api)
- [الجزء الثاني: دليل واجهة الموزع (PART 2: Reseller API)](#الجزء-الثاني-دليل-واجهة-الموزع-part-2-reseller-api)
  - [1. كيفية الإعداد والحصول على كود وصول الموزع ومفتاحه](#1-كيفية-الإعداد-والحصول-على-كود-وصول-الموزع-ومفتاحه)
  - [2. نظام العزل الأمني وحماية الرصيد (Credits & Isolation)](#2-نظام-العزل-الأمني-وحماية-الرصيد-credits--isolation)
  - [3. صيغة الاستدعاء العامة للموزع (Request Format)](#3-صيغة-الاستدعاء-العامة-للموزع-request-format)
  - [4. العمليات المتاحة للموزع (Reseller Actions)](#4-العمليات-المتاحة-للموزع-reseller-actions)
    - [4.1 الاستعلام عن رصيد وبيانات الموزع (`user_info`)](#41-الاستعلام-عن-رصيد-وبيانات-الموزع-user_info)
    - [4.2 عرض أكواد الموزع وموزعيه التابعين (`get_active_codes`)](#42-عرض-أكواد-الموزع-وموزعيه-التابعين-get_active_codes)
    - [4.3 جلب تفاصيل كود تابع للموزع (`get_active_code`)](#43-جلب-تفاصيل-كود-تابع-للموزع-get_active_code)
    - [4.4 توليد أكواد تفعيل مع الخصم من الرصيد (`generate_active_codes`)](#44-توليد-أكواد-تفعيل-مع-الخصم-من-الرصيد-generate_active_codes)
    - [4.5 فحص كود التفعيل من جانب الموزع (`check_active_code`)](#45-فحص-كود-التفعيل-من-جانب-الموزع-check_active_code)
    - [4.6 فك قفل جهاز الماك لمشترك الموزع (`reset_active_code_device`)](#46-فك-قفل-جهاز-الماك-لمشترك-الموزع-reset_active_code_device)
    - [4.7 إيقاف وتفعيل كود التفعيل (`disable_active_code` / `enable_active_code`)](#47-إيقاف-وتفعيل-كود-التفعيل-disable_active_code--enable_active_code)
    - [4.8 حذف كود واسترجاع الكريديت تلقائياً (`delete_active_code`)](#48-حذف-كود-واسترجاع-الكريديت-تلقائياً-delete_active_code)
    - [4.9 عرض باتشات الموزع وتصديرها (`get_active_codes_batches` / `export_active_code_batch`)](#49-عرض-باتشات-الموزع-وتصديرها)
  - [5. أمثلة برمجية لاستدعاء Reseller API](#5-أمثلة-برمجية-لاستدعاء-reseller-api)
- [ملحق: واجهة تفعيل المشتركين والتطبيقات (Client Player API)](#ملحق-واجهة-تفعيل-المشتركين-والتطبيقات-client-player-api)

---

# نظرة عامة على معمارية الربط

يعمل نظام الـ API في **XC_VM** وفق معمارية آمنة مبنية على **أكواد الوصول (Access Codes)**:
1. **كود الوصول (Access Code):** هو مسار عشوائي أو مخصص يتم إنشاؤه من لوحة التحكم (`Settings -> Access Codes`). يقوم Nginx بتوليد إعدادات خاصة به لربطه بالـ Backend المعني.
   - كود نوع **3** (`Type 3`): يربط مسار الطلبات بـ **Admin API**.
   - كود نوع **4** (`Type 4`): يربط مسار الطلبات بـ **Reseller API**.
2. **مفتاح الواجهة (API Key):** هو المفتاح السري الخاص بحساب المستخدم في اللوحة (Admin أو Reseller)، ويتم تمريره في كل طلب للتحقق من الهوية والصلاحيات.

```
العميل / التطبيق الخارجي
       │
       ▼
http://your-server.com/<Access-Code>/?api_key=<API-KEY>&action=<ACTION>&...
       │
       ├─► كود وصول من نوع 3 (Admin)   ──► AdminAPIWrapper & ActiveCodeService (صلاحيات كاملة)
       └─► كود وصول من نوع 4 (Reseller) ──► ResellerAPIWrapper & ActiveCodeService (عزل + خصم رصيد)
```

---

# الجزء الأول: دليل واجهة المدير (PART 1: Admin API)

هذا الجزء مخصص لمدير اللوحة (Super Admin / Administrator) لإدارة نظام كود التفعيل بالكامل على مستوى السيرفر بدون أي قيود على الرصيد أو المستخدمين.

## 1. كيفية الإعداد والحصول على كود الوصول والمفتاح

1. **كود وصول الأدمن (Admin Access Code):**
   - ادخل إلى لوحة الأدمن `Admin Panel -> Settings -> Access Codes`.
   - اضغط على **Add Access Code**.
   - اختر **Type: Admin API** واكتب اسماً أو كوداً مخصصاً (مثلاً: `admin_api` أو كود عشوائي مثل `9ABDC3947EC81B3D15B7478975E32F68`).
   - تأكد من تفعيل الكود (`Enabled`).

2. **مفتاح الأدمن (Admin API Key):**
   - ادخل إلى `Admin Panel -> Manage Users -> Edit Profile / User`.
   - انسخ المفتاح الموجود في حقل **API Key** لحساب المدير (مثال: `9ABDC3947EC81B3D15B7478975E32F68`).

---

## 2. صيغة الاستدعاء العامة (Request Format)

يمكن إرسال المعاملات عبر **GET** (في الـ Query String) أو عبر **POST** (بصيغة `x-www-form-urlencoded` أو `multipart/form-data`):

```http
GET /<ADMIN_ACCESS_CODE>/?api_key=<ADMIN_API_KEY>&action=<ACTION>&[parameters] HTTP/1.1
Host: your-server.com
```

أو عبر POST:
```http
POST /<ADMIN_ACCESS_CODE>/ HTTP/1.1
Host: your-server.com
Content-Type: application/x-www-form-urlencoded

api_key=<ADMIN_API_KEY>&action=<ACTION>&[parameters]
```

---

## 3. العمليات والإجراءات المدعومة (Actions Reference)

### 3.1 عرض جميع أكواد التفعيل في السيرفر (`get_active_codes`)

يقوم بجلب قائمة كاملة بأكواد التفعيل في السيرفر مع إمكانية الترقيم (Pagination) والفلترة بعدة معايير.

**المعاملات (Parameters):**
| المعامل | النوع | إجباري؟ | الوصف |
| :--- | :--- | :--- | :--- |
| `action` | string | **نعم** | القيمة الثابتة: `get_active_codes` |
| `start` | integer | اختياري | نقطة البداية (Offset)، الافتراضي `0` |
| `limit` | integer | اختياري | عدد العناصر في الصفحة، الافتراضي `50` (الأقصى `500`) |
| `status` | string/int | اختياري | فلترة حسب الحالة: `stock` (1), `active` (2), `disabled` (3), `expired` |
| `package_id` | integer | اختياري | فلترة حسب معرّف الباقة |
| `batch_name` | string | اختياري | فلترة حسب اسم الباتش |
| `search` | string | اختياري | بحث في نص الكود، اسم المستخدم، الماك، أو الجهاز |

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=get_active_codes&limit=10&status=active"
```

**نموذج الاستجابة (JSON Response):**
```json
{
  "status": "STATUS_SUCCESS",
  "total": 120,
  "count": 10,
  "start": 0,
  "limit": 10,
  "data": [
    {
      "id": 22,
      "activation_code": "NDXL9ZQ3GR",
      "batch_name": "BATCH-20260913-13A1B",
      "status": 2,
      "status_key": "active",
      "status_label": "Active",
      "package_id": 2,
      "package_name": "⚡ VIP PREMIUM | 1 Month",
      "subscriber_id": 20,
      "line_username": "ac_156aa7d6b",
      "line_password": "newSecretPass123",
      "exp_date": 1793175442,
      "exp_date_formatted": "2026-10-28 08:17:22",
      "remaining_days": 45,
      "mac": "11:22:33:44:55:66",
      "device_id": null,
      "max_connections": 2,
      "is_trial": 0,
      "purchase_cost": 1,
      "created_by": 2,
      "creator_username": "reseller_ahmed",
      "created_at": 1789287410,
      "activated_at": 1789287442
    }
  ]
}
```

---

### 3.2 جلب تفاصيل كود محدد وروابط المشغل (`get_active_code`)

يجلب التفاصيل الكاملة لكود التفعيل بما في ذلك اشتراك الـ Line المرتبط وروابط التشغيل الجاهزة (M3U, Xtream API, EPG).

**المعاملات (Parameters):**
| المعامل | النوع | إجباري؟ | الوصف |
| :--- | :--- | :--- | :--- |
| `action` | string | **نعم** | القيمة الثابتة: `get_active_code` |
| `id` أو `code` | mixed | **نعم** | معرّف الكود الرقمي (`id=22`) أو نص الكود (`code=NDXL9ZQ3GR`) |

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=get_active_code&id=22"
```

**نموذج الاستجابة (JSON Response):**
```json
{
  "status": "STATUS_SUCCESS",
  "data": {
    "id": 22,
    "activation_code": "NDXL9ZQ3GR",
    "status": 2,
    "status_key": "active",
    "status_label": "Active",
    "package": {
      "id": 2,
      "name": "⚡ VIP PREMIUM | 1 Month"
    },
    "credentials": {
      "line_id": 20,
      "username": "ac_156aa7d6b",
      "password": "newSecretPass123"
    },
    "playback": {
      "player_api": "http://your-server.com/player_api.php?username=ac_156aa7d6b&password=newSecretPass123",
      "m3u_plus": "http://your-server.com/get.php?username=ac_156aa7d6b&password=newSecretPass123&type=m3u_plus&output=ts",
      "epg": "http://your-server.com/xmltv.php?username=ac_156aa7d6b&password=newSecretPass123"
    },
    "device_lock": {
      "mac": "11:22:33:44:55:66",
      "device_id": null
    }
  }
}
```

---

### 3.3 توليد أكواد تفعيل جديدة (فردي أو بالجملة) (`generate_active_codes`)

توليد دفعة من أكواد التفعيل الجاهزة (جاهزة للتوزيع ولا يبدأ عداد الوقت إلا عند أول تشغيل من المشترك).

**المعاملات (Parameters):**
| المعامل | النوع | إجباري؟ | الوصف |
| :--- | :--- | :--- | :--- |
| `action` | string | **نعم** | القيمة: `generate_active_codes` (أو `create_active_code`) |
| `package_id` | integer | **نعم** | رقم الباقة المراد التوليد عليها |
| `count` | integer | اختياري | عدد الأكواد المطلوبة (الافتراضي: 1، الأقصى: 500) |
| `tag` | string | اختياري | وسم اختياري يميز الباتش (مثال: `PromoVip`) |
| `code_format` | string | اختياري | نسق الكود: `alpha` (أحرف وأرقام)، `numeric` (أرقام فقط) |
| `code_length` | integer | اختياري | طول الكود (الافتراضي: 10 أو 12) |
| `assigned_owner_id` | integer | اختياري | إسناد الأكواد لموزع محدد (خاص بالأدمن فقط) |

**مثال الاستدعاء:**
```bash
curl -s -X POST "http://your-server.com/admin_api/" \
  -d "api_key=ADMIN_KEY" \
  -d "action=generate_active_codes" \
  -d "package_id=2" \
  -d "count=2" \
  -d "tag=SupermarketOffer"
```

**نموذج الاستجابة (JSON Response):**
```json
{
  "status": "STATUS_SUCCESS",
  "message": "Successfully generated 2 active codes.",
  "batch_name": "BATCH-20260913-91ABC",
  "qty": 2,
  "data": [
    {
      "code": "X7K9P2W4LM",
      "line_id": 25,
      "username": "ac_47d8e2091",
      "password": "a1b2c3d4e5",
      "batch_name": "BATCH-20260913-91ABC"
    },
    {
      "code": "J3N8Q5T1VR",
      "line_id": 26,
      "username": "ac_65d9f3012",
      "password": "f6g7h8i9j0",
      "batch_name": "BATCH-20260913-91ABC"
    }
  ]
}
```

---

### 3.4 تعديل بيانات كود التفعيل والاشتراك (`edit_active_code`)

تعديل خصائص الكود والاشتراك المرتبط به (مثل تغيير كلمة المرور، زيادة عدد الشاشات، تمديد تاريخ الانتهاء، أو تغيير الماك).

**المعاملات (Parameters):**
| المعامل | النوع | إجباري؟ | الوصف |
| :--- | :--- | :--- | :--- |
| `action` | string | **نعم** | القيمة الثابتة: `edit_active_code` |
| `id` | integer | **نعم** | معرّف الكود في قاعدة البيانات |
| `password` | string | اختياري | كلمة سر جديدة لخط المشترك |
| `max_connections` | integer | اختياري | عدد الاتصالات المتزامنة المسموحة |
| `exp_date` | string/int | اختياري | تاريخ انتهاء جديد (صيغة `YYYY-MM-DD` أو Timestamp) |
| `mac` | string | اختياري | تعيين عنوان ماك محدد |

**مثال الاستدعاء:**
```bash
curl -s -X POST "http://your-server.com/admin_api/" \
  -d "api_key=ADMIN_KEY" \
  -d "action=edit_active_code" \
  -d "id=22" \
  -d "max_connections=3" \
  -d "password=NewStrongPass2026"
```

---

### 3.5 فحص حالة كود بدون استهلاكه (`check_active_code`)

فحص كود التفعيل لمعرفة هل هو صالح أم منتهي أم مقفل على جهاز ماك، **دون استهلاك الاشتراك أو بدء العد التنازلي**.

**المعاملات (Parameters):**
| المعامل | النوع | إجباري؟ | الوصف |
| :--- | :--- | :--- | :--- |
| `action` | string | **نعم** | القيمة الثابتة: `check_active_code` |
| `code` | string | **نعم** | نص كود التفعيل المراد فحصه |

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=check_active_code&code=NDXL9ZQ3GR"
```

**نموذج الاستجابة (JSON Response):**
```json
{
  "status": "STATUS_SUCCESS",
  "data": {
    "status": "SUCCESS",
    "valid": true,
    "code": "NDXL9ZQ3GR",
    "code_status": 2,
    "status_label": "Active",
    "package_name": "⚡ VIP PREMIUM | 1 Month",
    "is_trial": false,
    "max_connections": 2,
    "is_activated": true,
    "activated_at": "2026-09-13 09:17:22",
    "exp_date": 1793175442,
    "exp_date_formatted": "2026-10-28 08:17:22",
    "remaining_days": 45,
    "is_device_locked": true,
    "locked_mac": "11:22:33:44:55:66"
  }
}
```

---

### 3.6 إيقاف كود التفعيل مؤقتاً (`disable_active_code`)

تعليق كود التفعيل والاشتراك فوراً ومنع المشترك من الاتصال بالبث حتى إعادة تفعيله.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=disable_active_code&id=22"
```

---

### 3.7 إعادة تفعيل كود موقوف (`enable_active_code`)

إلغاء تعليق الكود وإعادته للعمل الطبيعي.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=enable_active_code&id=22"
```

---

### 3.8 فك قفل جهاز العميل والماك (`reset_active_code_device`)

مسح عنوان الماك (`MAC Address`) ومعرّف الجهاز (`Device ID`) المسجلين على الكود، للسماح للمشترك بنقل اشتراكه إلى شاشة أو جهاز آخر.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=reset_active_code_device&id=22"
```
*(أو باستخدام الكود مباشرة: `&code=NDXL9ZQ3GR`)*

**نموذج الاستجابة (JSON Response):**
```json
{
  "status": "STATUS_SUCCESS",
  "message": "Device binding cleared successfully for code NDXL9ZQ3GR"
}
```

---

### 3.9 حذف كود تفعيل نهائياً (`delete_active_code`)

حذف كود التفعيل والـ Line المرتبط به نهائياً من قاعدة البيانات.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=delete_active_code&id=22"
```

---

### 3.10 العمليات الجماعية على عدة أكواد (`mass_active_codes`)

تطبيق إجراء جماعي على قائمة من معرّفات الأكواد دفعة واحدة.

**المعاملات (Parameters):**
| المعامل | النوع | إجباري؟ | الوصف |
| :--- | :--- | :--- | :--- |
| `action` | string | **نعم** | القيمة الثابتة: `mass_active_codes` |
| `sub_action` | string | **نعم** | نوع العملية: `delete`, `enable`, `disable`, `reset_device`, `extend` |
| `ids` | array/string | **نعم** | مصفوفة أو قائمة مفصولة بفواصل لمعرفات الأكواد (مثال: `[22, 23, 24]` أو `22,23,24`) |
| `days` | integer | اختياري | عدد الأيام (مطلوب فقط في حالة `sub_action=extend`) |

**مثال الاستدعاء:**
```bash
curl -s -X POST "http://your-server.com/admin_api/" \
  -d "api_key=ADMIN_KEY" \
  -d "action=mass_active_codes" \
  -d "sub_action=reset_device" \
  -d "ids=22,23,24"
```

---

### 3.11 إحصائيات وأرصدة الباتشات (`get_active_codes_batches`)

عرض إحصائيات مجمعة لكل دفعة أكواد (إجمالي الأكواد، كم كود تم تفعيله، كم كود ما زال مخزوناً، وكم كود منتهي أو موقوف).

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=get_active_codes_batches"
```

---

### 3.12 تصدير كروت وبطاقات الباتش (`export_active_code_batch`)

تصدير كافة أكواد الباتش بصيغة مهيأة للطباعة أو لربطها ببرامج الكروت وأنظمة المبيعات.

**المعاملات (Parameters):**
| المعامل | النوع | إجباري؟ | الوصف |
| :--- | :--- | :--- | :--- |
| `action` | string | **نعم** | القيمة الثابتة: `export_active_code_batch` |
| `batch_name` | string | **نعم** | اسم الباتش المراد تصديره (مثال: `BATCH-20260913-13A1B`) |
| `format` | string | اختياري | صيغة التصدير: `json` (الافتراضي) أو `txt` (قائمة أسطر نصية) |

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/admin_api/?api_key=ADMIN_KEY&action=export_active_code_batch&batch_name=BATCH-20260913-13A1B&format=json"
```

---

## 4. أمثلة برمجية لاستدعاء Admin API

### Python 3
```python
import requests

SERVER_URL = "http://your-server.com"
ADMIN_ACCESS_CODE = "admin_api"
ADMIN_API_KEY = "9ABDC3947EC81B3D15B7478975E32F68"

endpoint = f"{SERVER_URL}/{ADMIN_ACCESS_CODE}/"

# 1. توليد كود جديد
payload = {
    "api_key": ADMIN_API_KEY,
    "action": "generate_active_codes",
    "package_id": 2,
    "count": 1,
    "tag": "PythonClient"
}
res = requests.post(endpoint, data=payload)
data = res.json()
print("Generated Code:", data["data"][0]["code"])

# 2. فحص حالة الكود
code = data["data"][0]["code"]
check_res = requests.get(endpoint, params={
    "api_key": ADMIN_API_KEY,
    "action": "check_active_code",
    "code": code
})
print("Check Status:", check_res.json())
```

### PHP 8+
```php
<?php
$serverUrl = "http://your-server.com";
$accessCode = "admin_api";
$apiKey = "9ABDC3947EC81B3D15B7478975E32F68";

$url = "{$serverUrl}/{$accessCode}/?api_key={$apiKey}&action=get_active_codes&limit=5";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
print_r($result);
```

### JavaScript / Node.js (Fetch)
```javascript
const SERVER = 'http://your-server.com';
const ACCESS_CODE = 'admin_api';
const API_KEY = '9ABDC3947EC81B3D15B7478975E32F68';

async function resetDevice(codeId) {
    const params = new URLSearchParams({
        api_key: API_KEY,
        action: 'reset_active_code_device',
        id: codeId
    });

    const res = await fetch(`${SERVER}/${ACCESS_CODE}/?${params}`);
    const data = await res.json();
    console.log(data);
}

resetDevice(22);
```

---

# الجزء الثاني: دليل واجهة الموزع (PART 2: Reseller API)

هذا الجزء مخصص للموزعين (Resellers) ومطوري لوحات وشاشات وبوتات الموزعين.

## 1. كيفية الإعداد والحصول على كود وصول الموزع ومفتاحه

1. **كود وصول الموزع (Reseller Access Code):**
   - يقوم الأدمن من اللوحة (`Settings -> Access Codes`) بإنشاء كود وصول من **Type: Reseller API**.
   - يمكن تسميته باسم مخصص مثل `reseller_api` أو كود عشوائي.
   - هذا المسار يُتاح للموزعين لاستخدامه للاتصال بسيرفر الـ API.

2. **مفتاح الموزع (Reseller API Key):**
   - يحصل كل موزع على مفتاح **API Key** خاص به من خلال بيانات حسابه في اللوحة.
   - مثال: `D9AD0953309AB740CBC9684C465642F6`.

---

## 2. نظام العزل الأمني وحماية الرصيد (Credits & Isolation)

تطبق منصة XC_VM أعلى معايير الحماية للموزعين تلقائياً عبر الطبقات التالية:
1. **عزل الشجرة (Sub-Users Hierarchy Isolation):**
   - الموزع **لا يمكنه بأي حال من الأحوال** رؤية أو الوصول أو التعديل أو فحص أي كود تابع لمدير أو موزع آخر خارج شجرته التابعة (`reports`).
   - عند طلب `get_active_codes`، يتم حصر النتيجة حصرياً في الأكواد التي أنشأها الموزع أو أحد الموزعين التابعين له في شجرته.
2. **التحقق من الرصيد والخصم التلقائي (Transactional Credit Deduction):**
   - عند استدعاء `generate_active_codes`، يقوم النظام بحساب التكلفة الإجمالية ومقارنتها برصيد الموزع الحالي.
   - إذا كان الرصيد غير كافٍ، يُرفض الطلب فوراً بخطأ: `Insufficient credits. Required: X, Available: Y`.
   - إذا تم التوليد بنجاح، يتم خصم الرصيد تلقائياً وتسجيل المعاملة وإرجاع الرصيد المتبقي `remaining_credits`.
3. **الاسترجاع التلقائي للرصيد عند حذف المخزون (Auto Stock Refund):**
   - عند قيام الموزع بحذف كود تفعيل ما زال في المخزون (`status = 1` لم يفعّله المشترك بعد)، يقوم النظام **تلقائياً وبشكل فوري بإعادة تكلفة الكود كاملة إلى رصيد الموزع** وإرجاع رسالة تأكيد صريحة توضح قيمة الكريديت المسترجع ورصيده الجديد.

---

## 3. صيغة الاستدعاء العامة للموزع (Request Format)

```http
GET /<RESELLER_ACCESS_CODE>/?api_key=<RESELLER_API_KEY>&action=<ACTION>&[parameters] HTTP/1.1
Host: your-server.com
```

أو عبر POST:
```http
POST /<RESELLER_ACCESS_CODE>/ HTTP/1.1
Host: your-server.com
Content-Type: application/x-www-form-urlencoded

api_key=<RESELLER_API_KEY>&action=<ACTION>&[parameters]
```

---

## 4. العمليات المتاحة للموزع (Reseller Actions)

### 4.1 الاستعلام عن رصيد وبيانات الموزع (`user_info`)

معرفة بيانات حساب الموزع، عدد الكريديت المتوفر لديه، والباقات المتاحة له.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=user_info"
```

**نموذج الاستجابة (JSON Response):**
```json
{
  "status": "STATUS_SUCCESS",
  "data": {
    "id": "2",
    "username": "reseller_123",
    "credits": "5999",
    "status": "1",
    "api_key": "D9AD0953309AB740CBC9684C465642F6"
  }
}
```

---

### 4.2 عرض أكواد الموزع وموزعيه التابعين (`get_active_codes`)

جلب قائمة الأكواد التابعة للموزع فقط، مفلترة ومصنفة.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=get_active_codes&status=stock"
```

---

### 4.3 جلب تفاصيل كود تابع للموزع (`get_active_code`)

جلب تفاصيل كود محدد مع روابط المشغل، بشرط أن يكون الكود مملوكاً للموزع أو أحد وكلائه التابعين.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=get_active_code&id=22"
```

---

### 4.4 توليد أكواد تفعيل مع الخصم من الرصيد (`generate_active_codes`)

توليد كود أو عدة أكواد واقتطاع تكلفتها من رصيد الموزع تلقائياً.

**المعاملات (Parameters):**
| المعامل | النوع | إجباري؟ | الوصف |
| :--- | :--- | :--- | :--- |
| `action` | string | **نعم** | القيمة الثابتة: `generate_active_codes` |
| `package_id` | integer | **نعم** | رقم الباقة (يجب أن تكون مسموحة للموزع) |
| `count` | integer | اختياري | عدد الأكواد المطلوبة (الافتراضي: 1) |
| `tag` | string | اختياري | وسم لتمييز العملية |

**مثال الاستدعاء:**
```bash
curl -s -X POST "http://your-server.com/reseller_api/" \
  -d "api_key=RESELLER_KEY" \
  -d "action=generate_active_codes" \
  -d "package_id=2" \
  -d "count=1" \
  -d "tag=ClientAli"
```

**نموذج الاستجابة (JSON Response):**
```json
{
  "status": "STATUS_SUCCESS",
  "message": "Successfully generated 1 active codes.",
  "batch_name": "BATCH-20260913-BD8EC",
  "qty": 1,
  "total_cost": 1,
  "remaining_credits": 5998,
  "data": [
    {
      "code": "GGYJHA7SWW",
      "line_id": 24,
      "username": "ac_1ddc54ddd",
      "password": "cf04c9ca6b",
      "batch_name": "BATCH-20260913-BD8EC"
    }
  ]
}
```

---

### 4.5 فحص كود التفعيل من جانب الموزع (`check_active_code`)

التحقق من حالة وصلاحية أي كود (هل هو مفعل، تاريخ الانتهاء، الأيام المتبقية، وقفل الماك).

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=check_active_code&code=GGYJHA7SWW"
```

---

### 4.6 فك قفل جهاز الماك لمشترك الموزع (`reset_active_code_device`)

إزالة قفل الماك المسجل على كود المشترك لتمكينه من تشغيل الاشتراك على شاشة أو ريسيفر بديل.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=reset_active_code_device&code=GGYJHA7SWW"
```

---

### 4.7 إيقاف وتفعيل كود التفعيل (`disable_active_code` / `enable_active_code`)

- **الإيقاف المؤقت:**
  ```bash
  curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=disable_active_code&id=24"
  ```
- **إعادة التفعيل:**
  ```bash
  curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=enable_active_code&id=24"
  ```

---

### 4.8 حذف كود واسترجاع الكريديت تلقائياً (`delete_active_code`)

عندما يقرر الموزع حذف كود لم يتم تفعيله بعد من قبل الزبون (Stock)، يقوم النظام بإرجاع الكريديت فوراً لمحفظته.

**مثال الاستدعاء:**
```bash
curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=delete_active_code&id=24"
```

**نموذج الاستجابة (JSON Response):**
```json
{
  "status": "STATUS_SUCCESS",
  "message": "1 code(s) deleted successfully. Refunded 1 credits for unused stock.",
  "remaining_credits": 5999
}
```

---

### 4.9 عرض باتشات الموزع وتصديرها

- **عرض إحصائيات باتشات الموزع:**
  ```bash
  curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=get_active_codes_batches"
  ```
- **تصدير كروت الباتش للطباعة:**
  ```bash
  curl -s "http://your-server.com/reseller_api/?api_key=RESELLER_KEY&action=export_active_code_batch&batch_name=BATCH-NAME&format=json"
  ```

---

## 5. أمثلة برمجية لاستدعاء Reseller API

### Python 3 (تطبيق بيع وتوليد كروت للموزع)
```python
import requests

SERVER_URL = "http://your-server.com"
RESELLER_ACCESS_CODE = "reseller_api"
RESELLER_KEY = "D9AD0953309AB740CBC9684C465642F6"

endpoint = f"{SERVER_URL}/{RESELLER_ACCESS_CODE}/"

def generate_voucher(package_id, tag="BotClient"):
    response = requests.post(endpoint, data={
        "api_key": RESELLER_KEY,
        "action": "generate_active_codes",
        "package_id": package_id,
        "count": 1,
        "tag": tag
    })
    result = response.json()
    if result.get("status") == "STATUS_SUCCESS":
        code_info = result["data"][0]
        print(f"✅ تم إنشاء الكود بنجاح: {code_info['code']}")
        print(f"💰 الرصيد المتبقي للموزع: {result['remaining_credits']}")
        return code_info['code']
    else:
        print("❌ فشل التوليد:", result.get("error"))
        return None

# تجربة توليد
code = generate_voucher(2)
```

### PHP 8+ (بوت تيليجرام أو لوحة ويب للموزع)
```php
<?php
function resellerGenerateCode($packageId, $tag = 'WebStore') {
    $serverUrl = "http://your-server.com";
    $accessCode = "reseller_api";
    $apiKey = "D9AD0953309AB740CBC9684C465642F6";

    $endpoint = "{$serverUrl}/{$accessCode}/";

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'api_key'    => $apiKey,
        'action'     => 'generate_active_codes',
        'package_id' => $packageId,
        'count'      => 1,
        'tag'        => $tag
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

$res = resellerGenerateCode(2);
print_r($res);
```

---

# ملحق: واجهة تفعيل المشتركين والتطبيقات (Client Player API)

هذا المسار مخصص لتطبيقات المشغل والشاشات الذكية (Android TV, Samsung, LG, FireStick, STB) حيث يقوم العميل فقط بإدخال كود التفعيل دون الحاجة لأي مفتاح API:

## المسار الموحد للمشغلات
- **URL المسار:**
  ```http
  http://your-server.com/api/active_codes
  ```
  *(أو المسار التقليدي: `http://your-server.com/active_code.php`)*

### 1. التفعيل الأول وبدء الاشتراك (`action=auth`)
عند فتح التطبيق لأول مرة وإدخال الكود، يبدأ سريان العداد الزمني وقفل الماك:

```bash
curl -s -X POST "http://your-server.com/api/active_codes" \
  -d "action=auth" \
  -d "code=GGYJHA7SWW" \
  -d "mac=AA:BB:CC:11:22:33" \
  -d "device_id=SAMSUNG-SMART-TV-2026"
```

**الاستجابة الفورية للمشغل:**
```json
{
  "status": "SUCCESS",
  "message": "Activation successful.",
  "code": "GGYJHA7SWW",
  "credentials": {
    "username": "ac_1ddc54ddd",
    "password": "cf04c9ca6b"
  },
  "subscription": {
    "package_name": "⚡ VIP PREMIUM | 1 Month",
    "is_trial": false,
    "max_connections": 1,
    "activated_at": "2026-09-13 09:35:00",
    "exp_date": 1791879300,
    "exp_date_formatted": "2026-10-13 09:35:00",
    "remaining_days": 30
  },
  "playback": {
    "player_api": "http://your-server.com/player_api.php?username=ac_1ddc54ddd&password=cf04c9ca6b",
    "m3u_plus": "http://your-server.com/get.php?username=ac_1ddc54ddd&password=cf04c9ca6b&type=m3u_plus&output=ts",
    "epg": "http://your-server.com/xmltv.php?username=ac_1ddc54ddd&password=cf04c9ca6b"
  }
}
```

### 2. فحص الكود من التطبيق بدون تفعيل (`action=check`)
```bash
curl -s "http://your-server.com/api/active_codes?action=check&code=GGYJHA7SWW"
```

---

## ملخص مقارنة سريع (Quick Comparison)

| الخاصية / المعيار | كود وصول الأدمن (Admin API) | كود وصول الموزع (Reseller API) | واجهة المشغل (Client API) |
| :--- | :--- | :--- | :--- |
| **المسار** | `/<Admin-Access-Code>/` | `/<Reseller-Access-Code>/` | `/api/active_codes` |
| **المصادقة** | `api_key` الخاص بالمدير | `api_key` الخاص بالموزع | بدون مفتاح (فقط كود التفعيل والماك) |
| **نطاق الرؤية** | جميع أكواد السيرفر بالكامل | أكواد الموزع وموزعيه التابعين فقط | بيانات الكود المدخل فقط |
| **الرصيد والكريديت** | غير محدود (بدون خصم) | خصم تلقائي + استرجاع عند حذف المخزون | لا ينطبق |
| **الصلاحيات** | كاملة (إنشاء، تعديل، حذف، مسح أجهزة، تصدير) | مقيدة بصلاحيات باقات ورصيد الموزع | تسجيل الدخول واستلام روابط القنوات |
