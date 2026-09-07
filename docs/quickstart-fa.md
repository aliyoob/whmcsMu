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
* لایسنس WHMCS
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
13. **اجرای self-test**

هر فایلی که جایگزین شود، اول در `/var/backups/wtp-setup-<تاریخ>/` کپی می‌شود.
اسکریپت را می‌توانید دوباره اجرا کنید؛ چیزی خراب نمی‌شود.

### گزینه‌های دیگر

```bash
./infrastructure/deploy-all.sh --dry-run       # فقط نشان بده چه می‌کنی
./infrastructure/deploy-all.sh --skip-server   # سرور آماده است، فقط ماژول را بفرست
./infrastructure/deploy-all.sh --skip-deploy   # فقط سرور را آماده کن
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

اسکریپت اینجا عمداً متوقف می‌شود: نصب وب WHMCS به لایسنس شما نیاز دارد و
پسورد ادمین را می‌سازد؛ هیچ‌کدام جای‌شان در یک اسکریپت یا در این ریپازیتوری
نیست.

آدرس زیر را باز کنید:

```
https://billing.example.com/install/install.php
```

اطلاعات دیتابیس را اسکریپت در انتها چاپ کرده و در فایل
`infrastructure/credentials-<domain>.txt` هم نوشته است:

| فیلد | مقدار |
|---|---|
| Database Host | `localhost` |
| Database Name | `whmcs` |
| Database Username | `whmcs` |
| Database Password | (تولیدشده) |

> فایل credentials حاوی رمز است، در `.gitignore` قرار دارد، و بعد از
> ذخیره‌کردن در جای امن باید حذفش کنید.

---

## گام ۳ — امن‌سازی

```bash
ssh root@SERVER 'rm -rf /var/www/whmcs/install'
ssh root@SERVER 'chmod 0400 /var/www/whmcs/configuration.php'
```

سپس پوشهٔ `admin` را به یک نام غیرقابل‌حدس تغییر دهید و در
`configuration.php` این خط را اضافه کنید:

```php
$customadminpath = 'نام-جدید-پوشه';
```

---

## گام ۴ — فعال‌سازی ماژول

در پیشخوان WHMCS:

**System Settings → Addon Modules → WordPress Theme Provisioner → Activate**

با فعال‌سازی، جدول‌های `mod_wtp_*` ساخته می‌شوند. در همان صفحه دسترسی نقش‌های
ادمین را هم مشخص کنید.

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
