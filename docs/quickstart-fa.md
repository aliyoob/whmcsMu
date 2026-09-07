# راه‌اندازی کامل (فارسی)

راهنمای گام‌به‌گام از یک سرور خام تا سیستمی که مشتری هنگام خرید هاست، قالب و
دموی وردپرس را انتخاب می‌کند و سایتش خودکار ساخته می‌شود.

> مستندات کامل انگلیسی: [installation.md](installation.md) ·
> [module-configuration.md](module-configuration.md) ·
> [troubleshooting.md](troubleshooting.md)

---

## پیش‌نیازها

**روی سیستم خودتان**

| ابزار | چرا |
|---|---|
| `php` نسخه ۷.۴ یا بالاتر | برای ساخت بسته‌ها و اجرای تست‌ها |
| `ssh` و `scp` | اتصال به سرور |
| `tar` | بسته‌بندی فایل‌ها |
| `expect` یا `sshpass` | فقط اگر با پسورد وارد می‌شوید |

روی مک `expect` از قبل نصب است. روی لینوکس:

```bash
sudo apt install expect        # Debian / Ubuntu
sudo dnf install expect        # AlmaLinux / Rocky
```

**سرور**

* اوبونتو ۲۲.۰۴ یا ۲۴.۰۴، یا AlmaLinux/Rocky نسخهٔ ۸ یا ۹
* حداقل ۲ گیگابایت رم و ۲۰ گیگابایت فضا
* دسترسی `root` (پسورد یا کلید SSH)
* معماری `x86_64` یا `aarch64` — ionCube فقط برای این دو لودر دارد

**چیزهایی که باید از قبل داشته باشید**

* فایل `whmcs.zip` از ناحیهٔ کاربری WHMCS (نرم‌افزار لایسنس‌دار است و
  نمی‌شود خودکار دانلود کرد)
* **کلید لایسنس WHMCS** — اگر بدهید، نصب WHMCS هم خودکار انجام می‌شود.
  اگر نداشته باشید، اسکریپت تا مرحلهٔ نصب وب پیش می‌رود و بقیه را خودتان
  در مرورگر تمام می‌کنید.
* یک دامنه که به آی‌پی سرور اشاره کند (مثلاً `billing.example.com`)
* یک سرور cPanel/WHM برای ساخت هاست مشتری‌ها — می‌تواند همین سرور نباشد

---

## گام ۱ — اجرای اسکریپت دیپلوی

فقط همین یک دستور:

```bash
cd ~/Documents/GitHub/whmcsMu
./infrastructure/deploy-all.sh
```

اسکریپت این‌ها را می‌پرسد:

```
Server
  Server IP address or hostname: 198.51.100.10
  SSH port [22]:
  SSH username [root]:

    1) Password  — you type the server's root password
    2) SSH key   — you already have a key on the server

  How should I sign in? [1]: 1
  Server password for root@198.51.100.10: ••••••••

WHMCS
  Domain WHMCS will run on: billing.example.com
  Your email: ops@example.com
  Where to install WHMCS [/var/www/whmcs]:
  PHP version (8.1, 8.2 or 8.3) [8.2]:
  Database name [whmcs]:
  Database username [whmcs]:
  Request a Let's Encrypt certificate now? [Y/n]: y
  Configure the firewall (allow 22, 80, 443)? [Y/n]: y
  Path to whmcs.zip on this machine: ~/Downloads/whmcs.zip

WHMCS installation
  Install WHMCS automatically? [Y/n]: y
  WHMCS licence key: ••••••••••••••••
  WHMCS administrator username [admin]:
  Administrator password (blank to generate one): ••••••••
  Rename the admin directory to something unguessable? [Y/n]: y

Theme provisioner
  Where to keep theme and demo packages [/var/lib/whmcs-wtp/packages]:
  Where to keep the encryption key [/etc/whmcs-wtp/wtp.env]:
```

بعد خلاصهٔ کار را نشان می‌دهد و تأیید می‌گیرد. کل عملیات بین ۳ تا ۱۰ دقیقه
طول می‌کشد.

### اسکریپت روی سرور چه کار می‌کند

1. **نصب بسته‌ها** — nginx، PHP-FPM با تمام افزونه‌های موردنیاز WHMCS،
   MariaDB، certbot، cron
2. **نصب ionCube Loader** — بدون آن WHMCS اصلاً بالا نمی‌آید
3. **تنظیم PHP** — `memory_limit=512M`، `max_execution_time=300`،
   غیرفعال‌کردن نمایش خطا، فعال‌کردن OPcache
4. **ساخت دیتابیس** و کاربر آن
5. **استخراج WHMCS** و تنظیم مالکیت و دسترسی فایل‌ها
6. **تنظیم nginx** با ریدایرکت HTTPS و مسیر `/provisioning/v1`
7. **گرفتن گواهی TLS** (اگر DNS آماده باشد)
8. **تنظیم فایروال**
9. **ساخت `/var/lib/whmcs-wtp/packages`** برای بستهٔ قالب‌ها و دموها
10. **ساخت کلید رمزنگاری** در `/etc/whmcs-wtp/wtp.env` با دسترسی `0640`
11. **نصب cron** — هم کران خود WHMCS، هم کارگر صف این ماژول
12. **بیلد و دیپلوی ماژول** و بستهٔ ایجنت
13. **نصب خود WHMCS** (اگر لایسنس داده باشید) — با نصب‌کنندهٔ رسمی خط فرمان
14. **امن‌سازی** — حذف `install/`، تغییر نام پوشهٔ `admin`، `chmod 0400` روی
    `configuration.php`، تنظیم System URL
15. **ساخت جدول‌های ماژول** تا فعال‌سازی افزونه فوری باشد
16. **اجرای self-test**

هر فایلی که جایگزین شود، اول در `/var/backups/wtp-setup-<تاریخ>/` کپی می‌شود.
اسکریپت را می‌توانید دوباره اجرا کنید؛ چیزی خراب نمی‌شود.

### گزینه‌های دیگر

```bash
./infrastructure/deploy-all.sh --dry-run       # فقط نشان بده چه می‌کنی
./infrastructure/deploy-all.sh --skip-server   # سرور آماده است، فقط ماژول را بفرست
./infrastructure/deploy-all.sh --skip-deploy   # فقط سرور را آماده کن
./infrastructure/deploy-all.sh --skip-whmcs    # WHMCS را نصب نکن، حتی با لایسنس
./infrastructure/deploy-all.sh --config infrastructure/answers.env
```

برای اجرای بدون سؤال، از روی نمونه یک فایل پاسخ بسازید:

```bash
cp infrastructure/answers.env.example infrastructure/answers.env
$EDITOR infrastructure/answers.env
./infrastructure/deploy-all.sh --config infrastructure/answers.env --yes
```

---

## گام ۲ — نصب WHMCS

### حالت خودکار (اگر لایسنس داده باشید)

اسکریپت خودش این کار را کرده است. از مسیر رسمی خط فرمان WHMCS استفاده می‌کند:

```bash
php -f bin/installer.php -- -i -n -c   # پیکربندی از stdin
```

| فلگ | معنی |
|---|---|
| `-i` | نصب (به‌جای ارتقا که پیش‌فرض است) |
| `-n` | حالت غیرتعاملی |
| `-c` | پیکربندی را به‌صورت JSON از stdin بخوان |

ساختار JSON دقیقاً همان چیزی است که مستندات WHMCS می‌گوید:

```json
{
  "admin": { "username": "admin", "password": "…" },
  "configuration": {
    "license": "…",
    "db_host": "localhost",
    "db_username": "whmcs",
    "db_password": "…",
    "db_name": "whmcs",
    "cc_encryption_hash": "۶۴ کاراکتر",
    "mysql_charset": "utf8"
  }
}
```

این «شبیه‌سازی کلیک روی ویزارد وب» نیست؛ مسیر پشتیبانی‌شدهٔ خود WHMCS است،
پس با نسخه‌های بعدی هم کار می‌کند.

**چند نکتهٔ امنیتی در پیاده‌سازی:**

* مستندات WHMCS صراحتاً هشدار می‌دهد اطلاعات حساس را روی خط فرمان نگذارید.
  به همین دلیل JSON در یک فایل با دسترسی `0600` نوشته و از `stdin` به
  نصب‌کننده داده می‌شود — هیچ‌چیز در `ps` سرور دیده نمی‌شود.
* JSON با `json_encode` خود PHP ساخته می‌شود، پس پسوردی که کوتیشن، بک‌اسلش
  یا حرف فارسی دارد سالم می‌ماند.
* خروجی نصب‌کننده قبل از نمایش، به‌صورت رشته‌ای (نه regex) از پسورد، لایسنس و
  هش رمزنگاری پاک می‌شود.
* `cc_encryption_hash` دقیقاً ۶۴ کاراکتر تولید می‌شود، همان‌طور که WHMCS
  می‌خواهد.

بعد از نصب، اسکریپت این کارها را هم انجام می‌دهد:

| کار | چرا |
|---|---|
| حذف پوشهٔ `install/` | تا کسی نتواند دوباره نصب را اجرا کند |
| تغییر نام `admin/` به `admin-<تصادفی>` و نوشتن `$customadminpath` | کاهش حملات خودکار روی مسیر پیش‌فرض |
| `chmod 0400` روی `configuration.php` | خواستهٔ خود WHMCS بعد از نصب |
| تنظیم System URL | تا ایجنت وردپرس بداند به کجا وصل شود |
| ساخت جدول‌های `mod_wtp_*` | تا فعال‌سازی افزونه فوری باشد |

در پایان، نام کاربری و پسورد ادمین و آدرس ورود چاپ می‌شود و در فایل
`infrastructure/credentials-<domain>.txt` هم ذخیره می‌شود.

> اگر نصب شکست خورد، اسکریپت علت‌های محتمل را می‌گوید. شایع‌ترین‌ها:
> لایسنس اشتباه یا منقضی، لایسنسی که به دامنه یا آی‌پی دیگری بسته شده
> (باید در ناحیهٔ کاربری WHMCS برای دامنهٔ جدید reissue شود)، یا نرسیدن
> سرور به سرویس لایسنس WHMCS.
>
> چیزی نیمه‌کاره نمی‌ماند: مشکل را حل کنید و دوباره اجرا کنید.

### حالت دستی (اگر لایسنس ندادید)

آدرس زیر را باز کنید:

```
https://billing.example.com/install/install.php
```

اطلاعات دیتابیس در خروجی اسکریپت و در فایل
`infrastructure/credentials-<domain>.txt` هست:

| فیلد | مقدار |
|---|---|
| Database Host | `localhost` |
| Database Name | `whmcs` |
| Database Username | `whmcs` |
| Database Password | (تولیدشده) |

بعد امن‌سازی را دستی انجام دهید:

```bash
ssh root@SERVER 'rm -rf /var/www/whmcs/install'
ssh root@SERVER 'chmod 0400 /var/www/whmcs/configuration.php'
```

و پوشهٔ `admin` را به نامی غیرقابل‌حدس تغییر دهید و در `configuration.php`
این خط را اضافه کنید:

```php
$customadminpath = 'نام-جدید-پوشه';
```

### هنوز فایل یا لایسنس ندارید؟

سر سؤال `Path to whmcs.zip` فقط Enter بزنید (یعنی `skip`).

اسکریپت سرور را **کامل** آماده می‌کند — nginx، PHP، ionCube، دیتابیس، TLS،
فایروال، cron، ماژول — فقط خود WHMCS نصب نمی‌شود. بعداً که فایل و لایسنس را
گرفتید:

```bash
./infrastructure/deploy-all.sh --skip-server
```

این بار مسیر `whmcs.zip` و کلید لایسنس را بدهید. چون سرور آماده است، فقط
فایل‌ها منتقل و WHMCS نصب می‌شود — چند ثانیه طول می‌کشد، نه چند دقیقه.

اگر WHMCS از قبل نصب باشد، اسکریپت تشخیص می‌دهد و دست نمی‌زند.

---

## گام ۳ — نکتهٔ لایسنس

لایسنس WHMCS به **دامنه و آی‌پی** گره می‌خورد. اگر لایسنس را قبلاً روی سرور
دیگری استفاده کرده‌اید، قبل از نصب در ناحیهٔ کاربری WHMCS آن را برای دامنهٔ
جدید reissue کنید، وگرنه نصب با خطای لایسنس شکست می‌خورد.

---

## گام ۴ — فعال‌سازی ماژول (تنها کلیک باقی‌مانده)

در پیشخوان WHMCS:

**System Settings → Addon Modules → WordPress Theme Provisioner → Activate**

سپس در همان صفحه دسترسی نقش‌های ادمین را مشخص کنید.

> WHMCS هیچ راه پشتیبانی‌شده‌ای برای فعال‌کردن یک افزونه از بیرون پیشخوان
> ندارد، پس این یک کلیک دستی می‌ماند. جدول‌ها از قبل ساخته شده‌اند، بنابراین
> فعال‌سازی آنی است.

اگر ترجیح می‌دهید جدول‌ها را دستی بسازید یا به‌روزرسانی کنید:

```bash
ssh root@SERVER 'sudo -u www-data WTP_ENV_FILE=/etc/whmcs-wtp/wtp.env \
  php /var/www/whmcs/modules/addons/wpthemeprovisioner/cron/worker.php --migrate'
```

هر دو مسیر یک کار می‌کنند و هر دو idempotent هستند.

بعد بررسی کنید:

```bash
ssh root@SERVER \
  'sudo -u www-data php /var/www/whmcs/modules/addons/wpthemeprovisioner/cron/worker.php --selftest'
```

خروجی باید همه `OK` باشد:

```
  database tables              OK    all present
  master key                   OK    present and usable
  package storage              OK    /var/lib/whmcs-wtp/packages
  agent package                OK    agent/whmcs-theme-provisioner.zip (82134 bytes)
  installer template           OK    wtp-bootstrap.php
  api base url                 OK    https://billing.example.com/provisioning/v1
  pending jobs                 OK    0 due now
```

اگر خطی `FAIL` بود، سراغ [troubleshooting.md](troubleshooting.md) بروید.

---

## گام ۵ — افزودن سرور cPanel

**System Settings → Servers → Add New Server**

| فیلد | مقدار |
|---|---|
| Hostname | هاست‌نیم WHM |
| IP Address | آی‌پی سرور — **حتماً پرش کنید** (برای زمانی که DNS هنوز پروپگیت نشده) |
| Type | cPanel |
| Username | `root` |
| Access Hash / API Token | **توکن API از WHM، نه پسورد روت** |
| Secure | فعال (پورت ۲۰۸۷) |

ساخت توکن در WHM: **Development → Manage API Tokens → Generate Token**.
دسترسی‌های لازم: `create-acct`، `suspend-acct`، `passwd`، `list-accts` و
دسترسی cPanel API.

توکن قابل ابطال، محدودکردنی و قابل ردیابی است؛ پسورد روت هیچ‌کدام نیست.

دکمهٔ **Test Connection** را بزنید. این دکمه هم WHM و هم خود ماژول را بررسی
می‌کند و اگر مشکلی باشد صریح می‌گوید کدام قسمت.

---

## گام ۶ — افزودن قالب و دمو

**Addons → WordPress Theme Provisioner → Themes & demos → Add a theme**

| فیلد | نکته |
|---|---|
| Name | چیزی که مشتری می‌بیند |
| Slug | **باید دقیقاً برابر نام پوشهٔ قالب داخل فایل zip باشد** |
| Preview image URL | آدرس تصویر پیش‌نمایش |

اسلاگ مهم‌ترین فیلد است. اگر داخل zip مسیر `astra/style.css` وجود دارد،
اسلاگ باید `astra` باشد. ناهماهنگی باعث خطای `theme_not_found` می‌شود.

### افزودن نسخه

اول فایل قالب را روی سرور بگذارید و چک‌سام بگیرید:

```bash
scp astra-4.6.1.zip root@SERVER:/tmp/
ssh root@SERVER '
  install -m 0640 -o www-data -g www-data \
    /tmp/astra-4.6.1.zip /var/lib/whmcs-wtp/packages/themes/astra-4.6.1.zip
  sha256sum /var/lib/whmcs-wtp/packages/themes/astra-4.6.1.zip
'
```

بعد در فرم نسخه:

| فیلد | مقدار |
|---|---|
| Version | `4.6.1` |
| Source | `Uploaded package (storage)` |
| Reference | `themes/astra-4.6.1.zip` |
| SHA-256 | خروجی `sha256sum` |
| Default | فعال |

چک‌سام تشریفات نیست: ایجنت هر بسته‌ای را که بایت‌هایش نخواند رد می‌کند، و
WHMCS هم وقتی فایل روی دیسک با کاتالوگ نخواند اجازهٔ دانلود نمی‌دهد. همین
جلوی آپلود ناقص یا فایل جابه‌جاشده را می‌گیرد.

### افزودن دمو

**Theme → Demos → Add a demo**

بعد فایل‌ها را با نقش درست آپلود کنید:

| نقش | فایل |
|---|---|
| `content` | خروجی WXR وردپرس (`.xml`) |
| `widgets` | JSON ابزارک‌ها |
| `customizer` | خروجی `.dat` سفارشی‌ساز |
| `elementor_kit` | کیت المنتور |
| `plugin` | فایل zip افزونه |

**پلاگین‌های موردنیاز** (JSON):

```json
[
  { "source": "wporg", "slug": "elementor", "activate": true }
]
```

**تنظیمات دمو** (JSON) — مهم‌ترین بخش:

```json
{
  "site": {
    "front_page": "Home",
    "menus": { "primary": "Main Menu" },
    "permalink_structure": "/%postname%/"
  },
  "options": {
    "source_site_url": "https://demo.example.com/agency",
    "fetch_attachments": true
  }
}
```

`source_site_url` را حتماً بگذارید. بدون آن، محتوای وارد‌شده همچنان به سایت
دموی شما لینک می‌دهد — شایع‌ترین علت «عکس‌ها نمی‌آید».

---

## گام ۷ — اتصال به محصول

**System Settings → Products/Services → محصول → Module Settings**

| فیلد | مقدار |
|---|---|
| Module | **WordPress Hosting (Theme Provisioner)** |
| cPanel package | نام پکیج در WHM (معمولاً با پیشوند ریسلر، مثل `reseller_starter`) |
| Install WordPress | بله |
| Site URL scheme | `https` |
| WordPress locale | `fa_IR` یا خالی |

سپس **Addons → WordPress Theme Provisioner → Products**:

| گزینه | معنی |
|---|---|
| Theme selection | نمایش انتخابگر در فرم سفارش |
| Required | تا وقتی دمو انتخاب نشده، اجازهٔ تسویه ندهد |
| Available themes | خالی = همهٔ قالب‌های فعال |
| Default theme / demo ID | برای سفارش‌هایی که فرم سفارش ندارند (ادمین یا API) |

مقدار پیش‌فرض را حتماً ست کنید، حتی وقتی انتخاب اجباری است — سفارشی که از
طریق API یا توسط خود شما ثبت شود هرگز فرم سفارش را نمی‌بیند.

---

## گام ۸ — تست کامل

یک سفارش آزمایشی ثبت کنید و مسیر را دنبال کنید:

**Addons → WordPress Theme Provisioner → Jobs → روی کار کلیک کنید**

تایم‌لاین ۱۷ مرحله را نشان می‌دهد، با تعداد تلاش، زمان و آخرین خطا، به‌علاوهٔ
لاگ کامل رویدادها. مشتری هم همین پیشرفت را در صفحهٔ سرویس خودش می‌بیند.

زمان معمول کل فرآیند: **۲ تا ۵ دقیقه**.

اگر کار پیش نرفت، اول کران را بررسی کنید — شایع‌ترین علت گیرکردن است:

```bash
ssh root@SERVER 'cat /etc/cron.d/whmcs-wtp'
ssh root@SERVER 'sudo -u www-data php /var/www/whmcs/modules/addons/wpthemeprovisioner/cron/worker.php --verbose'
```

---

## به‌روزرسانی بعدی

```bash
cd ~/Documents/GitHub/whmcsMu
git pull
./infrastructure/deploy-all.sh --skip-server
```

اگر ساختار دیتابیس تغییر کرده باشد، در تب **Settings** ماژول دکمهٔ
**Run pending migrations** را بزنید.

---

## اگر روی «Adding the PHP repository» گیر کرد

یعنی سرور نتوانسته به مخزن خارجی PHP وصل شود (`launchpad.net` روی اوبونتو).
روی شبکه‌هایی که ترافیک خروجی را فیلتر می‌کنند شایع است.

**بهترین راه‌حل: اصلاً به آن مخزن نیاز نداشته باشید.**

| توزیع | PHP خودش | برای بقیه مخزن لازم است |
|---|---|---|
| Ubuntu 24.04 | ۸.۳ | ۸.۱ و ۸.۲ |
| Ubuntu 22.04 | ۸.۱ | ۸.۲ و ۸.۳ |
| Debian 12 | ۸.۲ | ۸.۱ و ۸.۳ |

WHMCS 8.13 هر سه نسخه را پشتیبانی می‌کند، پس انتخاب نسخه‌ای که توزیع خودش
دارد کاملاً درست است و این وابستگی را حذف می‌کند. اسکریپت حالا همان را
پیش‌فرض پیشنهاد می‌دهد — روی اوبونتو ۲۴.۰۴ یعنی **۸.۳**.

اگر باز هم مخزن لازم شد و در دسترس نبود، اسکریپت دیگر بی‌صدا نمی‌میرد: خطای
واقعی را نشان می‌دهد و با نسخهٔ موجود ادامه می‌دهد.

بررسی از روی سرور:

```bash
curl -I https://launchpad.net
apt-cache policy php8.3-fpm php8.2-fpm php8.1-fpm
```

---

## اگر وسط کار اتصال قطع شد

نگران نباشید — آماده‌سازی سرور جدا از اتصال شما اجرا می‌شود (`setsid`). قطع
شدن SSH فقط *نمایش* را قطع می‌کند، نه خود کار را. اسکریپت دوباره وصل می‌شود و
از همان خطی که بود ادامه می‌دهد.

اگر خواستید مستقیم ببینید چه خبر است:

```bash
ssh root@SERVER 'tail -f /tmp/wtp-deploy-*/wtp-*.log'
```

> نسخه‌های قبلی این پروژه اینجا مشکل داشتند: روی اوبونتو ۲۲ به بعد، ابزار
> `needrestart` بعد از نصب هر بسته سرویس‌های قدیمی را ری‌استارت می‌کند — از
> جمله `ssh` — و همین اتصالی را که نصب را پیش می‌برد قطع می‌کرد. حالا قبل از
> کار با apt غیرفعال می‌شود.

---

## نکات امنیتی مهم

1. **`/etc/whmcs-wtp/wtp.env` را بکاپ بگیرید.** اگر `WTP_MASTER_KEY` را از
   دست بدهید، تمام اطلاعات رمزشده غیرقابل‌بازیابی می‌شود.
2. **از توکن API برای WHM استفاده کنید، نه پسورد روت.**
3. **فایل `credentials-*.txt` را بعد از ذخیره در جای امن حذف کنید.**
4. **بسته‌های دمو را از منبع معتبر بگیرید.** با اینکه سیستم آرشیوها را در
   برابر path traversal، سیم‌لینک، zip bomb و فایل اجرایی بررسی می‌کند،
   محتوای دمو در نهایت روی سایت مشتری اجرا می‌شود.
5. **`WTP_ENFORCE_HTTPS` را روی `1` نگه دارید.**

جزئیات کامل مدل امنیتی: [security.md](security.md)
