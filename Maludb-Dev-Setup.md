# Maludb-Dev-Setup.md

This guide walks through installing **Apache, PHP, the PostgreSQL/MySQL drivers, Composer, the MaluDB PHP SDK, Node.js, and Claude Code / Codex** on **Ubuntu 24.04**.

It assumes a **fresh Ubuntu 24.04 server** (for example, a freshly provisioned ProxMox VM) and that you have a user with `sudo` privileges. Commands are meant to be run one block at a time so you can confirm each step succeeded before moving on.

> **Why this repo needs both PostgreSQL *and* MySQL drivers:** the MaluDB API server stores its **data in PostgreSQL** but resolves each request's tenant credentials from a local **MySQL `users` table**. So you install `php8.3-pgsql` *and* `php8.3-mysql`.

The process has four stages:

1. **Preparing Ubuntu** — grow the disk, update packages.
2. **Installing Apache, PHP, required libraries, and database drivers.**
3. **Installing Composer and the MaluDB PHP client.**
4. **Installing Node.js and Claude Code or Codex.**

---

## 1. Prepare Ubuntu

### 1a. Extend the root filesystem to use all provisioned space

When you provision **50 GB or more** for an Ubuntu VM on ProxMox, the installer's default LVM layout does **not** claim all of the disk. The `lvextend` command grows the **logical volume**, but the **filesystem on top of it must also be resized** — otherwise the extra space stays invisible.

Check what you have now:

```bash
df -h /
sudo vgdisplay | grep Free   # shows unallocated space in the volume group
```

Grow the logical volume to use 100% of the free space, then resize the filesystem:

```bash
# Extend the logical volume AND resize the ext4 filesystem in one step (-r)
sudo lvextend -r -l +100%FREE /dev/mapper/ubuntu--vg-ubuntu--lv
```

> If your `lvextend` is older and does not support `-r`, run the two steps manually:
> ```bash
> sudo lvextend -l +100%FREE /dev/mapper/ubuntu--vg-ubuntu--lv
> sudo resize2fs /dev/mapper/ubuntu--vg-ubuntu--lv
> ```

Confirm the new size:

```bash
df -h /
```

### 1b. Update and upgrade the Ubuntu installation

```bash
sudo apt update
sudo apt upgrade -y
```

> If the upgrade installs a new kernel, reboot before continuing: `sudo reboot`.

---

## 2. Install Apache, PHP, and the database drivers

### 2a. Install Apache

```bash
sudo apt install apache2 -y
sudo systemctl enable apache2     # start automatically on boot
sudo systemctl start apache2
```

Verify Apache is running — you should see `active (running)`:

```bash
systemctl status apache2 --no-pager
```

You can also open `http://<server-ip>/` in a browser; the default Apache welcome page confirms it works.

### 2b. Install PHP 8.3, the Apache PHP module, and the drivers

Ubuntu 24.04 ships **PHP 8.3** in its default repositories. Install PHP, the Apache module, the common extensions this project uses, **and both database drivers** in a single command:

```bash
sudo apt install -y \
  php8.3 libapache2-mod-php8.3 php8.3-cli \
  php8.3-pgsql php8.3-mysql \
  php8.3-curl php8.3-gd php8.3-mbstring php8.3-xml php8.3-zip
```

> **Driver note:** `php8.3-pgsql` provides `pdo_pgsql` (the data store) and `php8.3-mysql` provides `pdo_mysql` (the auth store). Both are required by this repo.

### 2c. Enable PHP and `mod_rewrite`, then restart Apache

This project maps every `/v1/...` URL onto a single PHP file using **`.htaccess` rewrite rules**, so `mod_rewrite` **must** be enabled and `.htaccess` overrides **must** be allowed.

```bash
sudo a2enmod php8.3
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Allow `.htaccess` rewrites in the web root. Open the default site config:

```bash
sudo nano /etc/apache2/sites-available/000-default.conf
```

Add this block inside the `<VirtualHost>` (or edit `/etc/apache2/apache2.conf` for the `/var/www/` directory):

```apache
<Directory /var/www/html>
    AllowOverride All
    Require all granted
</Directory>
```

Save, then reload:

```bash
sudo systemctl reload apache2
```

### 2d. Verify PHP

```bash
php -v                                   # should report PHP 8.3.x
php -m | grep -E 'pdo_pgsql|pdo_mysql'   # both lines should print
```

---

## 3. Install Composer and the MaluDB PHP client

### 3a. Install Composer system-wide

```bash
# Download and run the official Composer installer
curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php

# Verify (/usr/local/bin is already on the default PATH)
composer --version
```

### 3b. (Optional) Put Composer's global package bin on your PATH

This step only matters if you later install Composer packages **globally** (`composer global require ...`); their executables live under `~/.config/composer/vendor/bin`. It is **not** needed for the project install in 3c.

```bash
echo 'export PATH="$HOME/.config/composer/vendor/bin:$PATH"' >> ~/.profile
. ~/.profile
```

### 3c. Install the MaluDB PHP client with Composer

Run this **inside the project directory** so Composer writes `composer.json` and the `vendor/` folder there:

```bash
cd /var/www
composer require maludb/client
```

This creates `/var/www/vendor/` and an autoloader at `/var/www/vendor/autoload.php`.

---

## 4. Install Node.js and Claude Code or Codex

### 4a. Install Node.js (v24 LTS) from NodeSource

```bash
# Add the NodeSource repository and install Node.js 24.x
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
sudo apt install -y nodejs

# Verify
node -v
npm -v
```

Configure npm to install global packages under your home directory (so global installs don't need `sudo`):

```bash
mkdir -p ~/.npm-global
npm config set prefix ~/.npm-global
echo 'export PATH=~/.npm-global/bin:$PATH' >> ~/.bashrc
source ~/.bashrc
```

### 4b. Install Claude Code **or** Codex

```bash
# Anthropic Claude Code
npm install -g @anthropic-ai/claude-code
```

or

```bash
# OpenAI Codex
npm install -g @openai/codex
```

Verify the CLI is on your PATH:

```bash
claude --version    # or: codex --version
```

---

## 5. Sample test connection script

Create `/var/www/html/test-pdo.php` to confirm PHP can reach your PostgreSQL database. Replace every `<...>` placeholder with your real connection values **before** loading the page.

```php
<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    $pdo = new PDO(
        'pgsql:host=<ip>;port=5432;dbname=<database>;sslmode=disable',
        '<user>',
        '<password>',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    header('Content-Type: text/plain');
    echo "connected\n";
    echo $pdo->query('select current_user, current_database()')->fetchColumn() . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo get_class($e) . "\n";
    echo $e->getMessage() . "\n";
}
```

Load it in a browser at:

```
http://<server-ip>/test-pdo.php
```

**Expected output** (plain text):

```
connected
<user>
```

**Troubleshooting:**

- `could not find driver` → `php8.3-pgsql` is missing or Apache wasn't restarted (`sudo systemctl restart apache2`).
- `Connection refused` / timeout → the Postgres host/port is wrong, or a firewall is blocking 5432.
- `password authentication failed` → wrong `<user>`/`<password>`, or the Postgres `pg_hba.conf` rejects the connection.

> **Security:** delete `test-pdo.php` once the connection is verified — it would otherwise expose database error details publicly:
> ```bash
> sudo rm /var/www/html/test-pdo.php
> ```

---

## Summary checklist

- [ ] Disk extended (`df -h` shows full capacity)
- [ ] Ubuntu updated and upgraded
- [ ] Apache installed, enabled, running
- [ ] PHP 8.3 + `pdo_pgsql` + `pdo_mysql` installed
- [ ] `mod_rewrite` enabled and `AllowOverride All` set for `/var/www/html`
- [ ] Composer installed; `composer require maludb/client` run in `/var/www`
- [ ] Node.js 24 installed; Claude Code or Codex installed
- [ ] `test-pdo.php` prints `connected` (then deleted)
