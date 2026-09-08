# Offline deployment

Installing this app on a school LAN with no internet access.

Written to be followed start to finish at the machine. Every command is literal:
type it exactly. Paths assume **Windows + XAMPP**, which is what this app is
developed against. Linux differences are noted where they matter.

Budget about 40 minutes for the install, plus 15 for the verification pass.

> **Do the whole thing before any class needs it.** Step 5 in particular can
> silently break every exam timer, and you will not notice until a student is
> sitting a paper.

---

## What you need before you start

| | |
|---|---|
| One machine to be the server | Stays powered on during exams. Wired to the switch, not Wi-Fi. |
| XAMPP installer | Copied onto a USB stick beforehand. It is ~150 MB and there is no internet here. |
| This repository | On the same USB stick, as a folder or a zip. |
| Administrator rights | On the server machine only. |
| The server's LAN IP | You will find this in step 8. |

Nothing in this app needs the internet. All fonts, styles and scripts ship with
the repository; there are no CDN links anywhere.

---

## 1. Install XAMPP

Run the installer. Accept the default install location:

```
C:\xampp
```

You only need two components. Tick these, untick the rest:

- **Apache**
- **MySQL**

Then open the XAMPP Control Panel:

```
C:\xampp\xampp-control.exe
```

Click **Start** next to Apache, then next to MySQL. Both go green.

If Apache refuses to start, something else already holds port 80. Usually
Skype, IIS or another web server. Check with:

```
netstat -ano | findstr :80
```

Then either stop that program or change Apache's port in
`C:\xampp\apache\conf\httpd.conf` (line 60, `Listen 80`). Changing the port
means every client URL gains `:8080`, so prefer stopping the other program.

---

## 2. Put the app files in place

Copy the repository folder so it ends up here:

```
C:\xampp\htdocs\exam-system
```

Check it landed correctly. This must show `index.php`:

```
dir C:\xampp\htdocs\exam-system\public
```

You should see `index.php`, `.htaccess`, `assets`, and `marketing`.

If `.htaccess` is missing, Windows may have hidden it during the copy. In File
Explorer turn on **View → Hidden items** and copy it again. **The app will not
route without it.** Every page except the front one will 404.

---

## 3. Create the database

The schema file creates the database, all 12 tables, and the constraints. It
contains no data.

```
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\exam-system\database\schema.sql
```

If the root account has a password, add `-p` and it will prompt you.

Confirm it worked:

```
C:\xampp\mysql\bin\mysql.exe -u root -e "USE exam_system; SHOW TABLES;"
```

Expect 12 tables, including `users`, `exams`, `exam_attempts` and
`attempt_answers`.

### Give the app its own database user

Do not let the app connect as root.

```
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE USER 'examapp'@'localhost' IDENTIFIED BY 'CHANGE-THIS-PASSWORD'; GRANT SELECT, INSERT, UPDATE, DELETE ON exam_system.* TO 'examapp'@'localhost'; FLUSH PRIVILEGES;"
```

Pick a real password and write it down. You need it in the next step.

---

## 4. Create config.php

Copy the example:

```
copy C:\xampp\htdocs\exam-system\config\config.example.php C:\xampp\htdocs\exam-system\config\config.php
```

Open it in Notepad:

```
notepad C:\xampp\htdocs\exam-system\config\config.php
```

Change these four lines:

```php
define('DB_USER', 'examapp');
define('DB_PASS', 'CHANGE-THIS-PASSWORD');   // the password from step 3
define('APP_NAME', 'Your School Exams');     // shows in the browser tab
define('BASE_URL', '/exam-system/public/');  // leave as-is for this layout
```

`BASE_URL` is a path, not an address. It stays the same whether someone reaches
the server by `localhost` or by its LAN IP. Keep the trailing slash.

`config.php` is deliberately excluded from version control, so it never travels
between machines. It is the only file holding this server's credentials.

---

## 5. Make the two clocks agree

**This is the step that quietly ruins exams.** Read it.

An attempt's deadline is written by **MySQL** (`NOW()`), and enforced by **PHP**
(`time()`). If those clocks disagree by an hour, every paper either expires the
moment it opens or runs an hour long. A fresh XAMPP install defaults PHP to UTC
while MySQL follows Windows, so on a machine set to West Africa Time they are
already an hour apart.

Set the timezone at the top of `config.php` to the server's own:

```php
date_default_timezone_set('Africa/Lagos');
```

Use whatever is correct for your location. The full list of valid values is at
`https://www.php.net/timezones`, but if you have no internet: `Africa/Lagos`,
`Africa/Accra`, `Africa/Nairobi`, `Africa/Johannesburg`, `Europe/London`.

Now check both clocks report the same time:

```
C:\xampp\php\php.exe -r "require 'C:/xampp/htdocs/exam-system/config/config.php'; echo 'PHP:   ' . date('Y-m-d H:i:s') . PHP_EOL;"
C:\xampp\mysql\bin\mysql.exe -u root -e "SELECT NOW() AS mysql_now, @@system_time_zone;"
```

**The two timestamps must match within a second or two.** If they do not, fix
the Windows clock and timezone (Settings → Time & Language), restart MySQL from
the XAMPP Control Panel, and run both commands again.

Do not continue until they agree.

---

## 6. Confirm mod_rewrite is on

Stock XAMPP already has this right, but confirm rather than assume, because without it
the front page works and every other page 404s, which is a confusing failure.

Check the module is loaded. Line 163 of `httpd.conf` must **not** start with `#`:

```
findstr /n "LoadModule rewrite_module" C:\xampp\apache\conf\httpd.conf
```

Expected: `163:LoadModule rewrite_module modules/mod_rewrite.so`

Check `.htaccess` files are honoured. Look at the `<Directory "C:/xampp/htdocs">`
block near line 253:

```
findstr /n "AllowOverride" C:\xampp\apache\conf\httpd.conf
```

The one inside that block must read `AllowOverride All`, not `AllowOverride None`.

If you changed anything, restart Apache from the XAMPP Control Panel.

Now test routing on the server itself:

```
curl -I http://localhost/exam-system/public/auth/login
```

**Expect the first line to read `HTTP/1.1 200 OK`.** A `404` means rewriting is
not working: re-check `.htaccess` exists (step 2) and `AllowOverride All` is set.

`curl` ships with Windows 10 and later at `C:\Windows\System32\curl.exe`. If it
is missing, just open `http://localhost/exam-system/public/auth/login` in a
browser on the server instead: the sign-in page should appear.

---

## 7. Create the first admin account

The schema ships with no accounts at all, so the first one is made by hand.

**Generate a password hash.** Replace `ChooseAStrongPassword` with the real
password, and keep it somewhere safe:

```
C:\xampp\php\php.exe -r "echo password_hash('ChooseAStrongPassword', PASSWORD_DEFAULT), PHP_EOL;"
```

It prints a 60-character string starting `$2y$10$`. Copy the whole thing.

**Insert the account**, pasting that hash between the quotes:

```
C:\xampp\mysql\bin\mysql.exe -u root exam_system -e "INSERT INTO users (full_name, email, password_hash, role, status) VALUES ('System Administrator', 'admin@school.local', 'PASTE-THE-HASH-HERE', 'admin', 'active');"
```

Confirm:

```
C:\xampp\mysql\bin\mysql.exe -u root exam_system -e "SELECT id, email, role, status FROM users;"
```

One row, role `admin`, status `active`.

> Never type the password itself into the `users` table. The column holds a
> hash. Pasting a plain password there produces an account nobody can sign in to.

---

## 8. Open the server to the LAN

**Find the server's IP address:**

```
ipconfig
```

Read the **IPv4 Address** under your wired adapter. It looks like `192.168.1.50`.
Write it on the whiteboard; every client machine needs it.

If it starts with `169.254`, the machine has no real network connection. Check
the cable and the switch before going further.

**Allow inbound port 80 through Windows Firewall.** Without this, the server
works on itself and is invisible to every other machine, and the single most common
reason "it works here but not there":

```
netsh advfirewall firewall add rule name="XAMPP Apache 80" dir=in action=allow protocol=TCP localport=80
```

Run that in a Command Prompt opened as Administrator.

**Do not open port 3306.** MySQL should stay reachable only from the server
itself. The app connects over `localhost`.

### Give the server a fixed address

If the IP is handed out by DHCP it can change after a reboot, and every client
bookmark breaks mid-term. Either reserve it on the router, or set it statically:
Settings → Network & Internet → Ethernet → IP assignment → Edit → Manual.

---

## 9. Point the client machines at it

On each student machine, open a browser and go to:

```
http://192.168.1.50/exam-system/public/
```

Substituting the IP from step 8. Note there is **no `s`** in `http`, because this is a
plain-HTTP LAN deployment.

The sign-in page should appear, styled, with the school name you set in
`APP_NAME`.

Bookmark it, and consider setting it as the browser home page on every lab
machine so students do not have to type it.

**If the page loads but looks unstyled**, `BASE_URL` in `config.php` does not
match the path you typed. It must be `/exam-system/public/`.

**If nothing loads at all**, the firewall rule in step 8 did not take. Test from
a client:

```
ping 192.168.1.50
```

If ping works but the browser does not, it is the firewall, not the network.

---

## 10. Verification pass

Do this before any real exam. It takes about 15 minutes and exercises the parts
that matter: sign-in, the timer, and autosave.

### 10.1 Sign in as admin

On a **client machine**, not the server. Sign in with the admin account from
step 7.

You should land on the admin dashboard. **Confirmed:** sign-in and sessions work
across the LAN.

### 10.2 Build a minimal exam

Still as admin:

1. **Users → Create user.** Make one lecturer and one student. Note both passwords.
2. **Courses → Create course.** Code `TEST101`, assign the lecturer.
3. **Courses → TEST101 → Enrolments.** Enrol the student.

Sign out. Sign in as the **lecturer**:

4. **Question bank → Add MCQ.** Add two questions, each with one correct option.
5. **Exams → Create exam.** Duration `10` minutes, questions per attempt `2`,
   window start today, window end tomorrow.
6. Add both questions to the pool, then **publish** the exam.

Publishing is what makes it visible to students. A draft exam will not appear.

### 10.3 Sit the exam as a student

Sign in as the **student** on a client machine.

- The exam appears on the dashboard. **Click Start exam.**
- The instructions page appears. **Click Attempt quiz.**
- **Confirmed:** the countdown in the right-hand panel is running and shows
  close to 10:00, not `--:--` and not a wild number. A wrong number here means
  step 5 was skipped.

### 10.4 Confirm autosave is reaching the server

This is the part worth being certain about, because it fails silently.

Answer the first question. Wait two seconds. **The meta panel on the left should
change from "Not yet answered" to "Answer saved"**, and the question's box in
the navigation panel should fill in.

Now prove it reached the database. On the **server**:

```
C:\xampp\mysql\bin\mysql.exe -u root exam_system -e "SELECT attempt_id, question_id, selected_option_id, updated_at FROM attempt_answers ORDER BY updated_at DESC LIMIT 5;"
```

**Confirmed:** a row exists with a timestamp from seconds ago.

Answer the second question and run it again. A second row appears. Autosave is
working.

If no row appears, the answer never left the browser. Check that the student
machine can reach the server (step 9) and that the browser is not showing a
certificate or mixed-content warning.

### 10.5 Finish and check the result

- Click **Finish attempt …**. The summary table lists both questions as
  "Answer saved".
- Click **Submit all and finish**.
- The review page shows Status, Started, Completed, Duration and Grade.

Correct answers stay hidden until the exam window closes. That is deliberate:
students sit at different times, and revealing answers at submission would hand
the first finisher an answer key. To see the full review, set that test exam's
window end to a time in the past and reload.

### 10.6 Clean up the test data

```
C:\xampp\mysql\bin\mysql.exe -u root exam_system -e "DELETE FROM exam_attempts WHERE exam_id IN (SELECT id FROM exams WHERE course_id = (SELECT id FROM courses WHERE course_code='TEST101')); DELETE FROM courses WHERE course_code='TEST101';"
```

Leave the admin account. Delete the test lecturer and student from **Users** in
the admin interface.

---

## Running it day to day

**Starting the server** after a reboot: open `C:\xampp\xampp-control.exe`, start
Apache and MySQL. Both must be green before the first student arrives.

To avoid doing that by hand every morning, install both as Windows services:
tick the red X boxes to the left of Apache and MySQL in the Control Panel, run as
Administrator. They then start with Windows.

**Backing up** before and after each exam day:

```
C:\xampp\mysql\bin\mysqldump.exe -u root exam_system > D:\backups\exam_system_2026-09-08.sql
```

Change the date each time. Keep these on a USB stick, not only on the server.

**Do not shut the server down while an exam is in progress.** In-flight attempts
keep their deadline, but students lose access until it comes back, and the clock
does not pause.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Front page works, everything else 404s | `.htaccess` missing or `AllowOverride None` | Step 2 and step 6 |
| Page loads with no styling | `BASE_URL` does not match the URL path | Step 4 |
| Timer shows a wrong or negative number | PHP and MySQL clocks disagree | Step 5 |
| Works on the server, not on clients | Windows Firewall blocking port 80 | Step 8 |
| "Connection failed" on every page | MySQL not started, or wrong `DB_USER` / `DB_PASS` | Control Panel; step 4 |
| Fatal error on a failed sign-in | `config.php` predates the throttling constants | Re-copy from `config.example.php` |
| Exam not visible to a student | Exam still a draft, or student not enrolled | Publish it; check Enrolments |
| Answers not saving | Client cannot reach the server | Step 10.4 |
| Account locked out | 5 failed sign-ins | Wait 15 minutes, or clear the row: `DELETE FROM login_attempts WHERE email='...';` |

---

## Notes for whoever maintains this

- **`config/config.php` is gitignored.** Updating the app never overwrites it,
  and it never travels between machines. Back it up separately.
- **Never run `git clean`** in the app directory. It deletes `config.php`.
- Fonts, stylesheets and scripts are all served from within the repository.
  Nothing loads from a CDN, so the app is fully functional air-gapped.
- Sessions use cookies with `Secure` set only when the request arrives over
  HTTPS. On a plain-HTTP LAN the flag is correctly absent, so sign-in works.
  If the school later puts this behind HTTPS, the flag turns itself on.
