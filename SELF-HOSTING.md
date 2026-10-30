# Self-hosting MAESTRO

> [!TIP]
> **You do not need to install anything to use MAESTRO.** The
> [hosted studio](https://maestro-lab.science/app/projects.php) offers every feature of
> MAESTRO, including the AI assistant, the example studies and all data exports, and runs
> directly in your browser. Self-hosting is optional, and most researchers never need this guide.

This guide is for teams that want to run MAESTRO on their own infrastructure, for example
because the data protection rules of their university require that study data stays on
university servers. A self-hosted MAESTRO works exactly like the hosted studio.

← Back to the [README](README.md)

**Contents**

1. [Choose a setup](#choose-a-setup)
2. Install MAESTRO with one of three options:
   [A: Your own computer](#option-a-your-own-computer) ·
   [B: A Linux server](#option-b-a-linux-server) ·
   [C: Shared web hosting](#option-c-shared-web-hosting)
3. [After the installation](#after-the-installation): API keys, updates and backups
4. [Optional: The WebSocket relay](#optional-the-websocket-relay)
5. [Troubleshooting](#troubleshooting)
6. [Reference](#reference)

## Choose a setup

Pick one setup and follow only the section of that setup. Each section is complete on its own.

| Setup | When the setup fits | What you need |
|---|---|---|
| **[A: Your own computer](#option-a-your-own-computer)** | You want to try MAESTRO or prepare a study. Only your computer can open the studio and the chat. | A Mac, a Linux computer, or Windows with WSL |
| **[B: A Linux server](#option-b-a-linux-server)** | You collect data with online participants, for example on Prolific, and you want full control. | A server or virtual machine with Ubuntu, and a domain name |
| **[C: Shared web hosting](#option-c-shared-web-hosting)** | You collect data with online participants, and you do not want to administer a server. | A hosting plan with PHP and MySQL, for example the web space of your university |

Every setup needs three things:

- PHP 8.1 or newer with the extensions `mysqli`, `curl`, `mbstring`, `iconv` and `zip`
- MySQL 8 or newer, or MariaDB 10.6 or newer
- An API key for OpenAI or Anthropic, or access to an OpenAI-compatible gateway, for example
  the gateway of your university

MAESTRO consists of plain PHP and JavaScript files without a build step. The same files
therefore run on Intel and AMD processors (x86-64) and on ARM processors, for example on Apple
silicon Macs, a Raspberry Pi or ARM cloud servers.

---

## Option A: Your own computer

**You need** a Mac, a Linux computer, or Windows with WSL. **The result** is a studio at
`http://localhost:8020/app/` that only your computer can open. The setup uses the development
server of PHP, which suits one researcher and test chats. For data collection with online
participants, use option B or C.

### 1. Install PHP and MySQL

On **macOS**, install [Homebrew](https://brew.sh) first. The following commands install PHP and
MySQL and start MySQL:

```bash
brew install php mysql
brew services start mysql
```

On **Ubuntu or Debian**, install PHP, MariaDB and Git:

```bash
sudo apt update
sudo apt install php-cli php-mysql php-curl php-mbstring php-zip mariadb-server git
```

On **Windows**, install the [Windows Subsystem for Linux](https://learn.microsoft.com/windows/wsl/install)
with Ubuntu, and follow the Ubuntu steps in the Ubuntu terminal. The PHP development server on
Windows handles only one request at a time, so a running chat would block every other request.
If MariaDB does not run inside WSL yet, start MariaDB with `sudo service mariadb start`.

### 2. Download MAESTRO

```bash
git clone https://github.com/maestro-lab-science/maestro.git
cd maestro
```

As an alternative to Git, you can download the ZIP file on
[GitHub](https://github.com/maestro-lab-science/maestro) (**Code → Download ZIP**). The ZIP
file unpacks into a folder called `maestro-main`, which you rename to `maestro`.

### 3. Create the database

Open the database console. On macOS, the command is `mysql -u root`. On Ubuntu, Debian and
WSL, the command is `sudo mariadb`. In the console, create a database and a user for MAESTRO.
Replace `choose-a-password` with a password of your choice:

```sql
CREATE DATABASE maestro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'maestro'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL PRIVILEGES ON maestro.* TO 'maestro'@'localhost';
```

Type `exit` to leave the database console.

### 4. Create the configuration file

Copy the template of the configuration file:

```bash
cp app/includes/config.local.example.php app/includes/config.local.php
```

Open `app/includes/config.local.php` in a text editor and add these lines at the end of the
file:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'maestro');
define('DB_USER', 'maestro');
define('DB_PASS', 'choose-a-password');
```

Git ignores `config.local.php`, so your passwords and keys never end up in the repository.

### 5. Create the tables

```bash
php app/install/install.php
```

The installer prints `Database and tables are ready.` when the installer has succeeded.

### 6. Start MAESTRO

Run this command in the `maestro` folder:

```bash
PHP_CLI_SERVER_WORKERS=8 php -S localhost:8020 -t .
```

Open <http://localhost:8020/app/> in your browser and create your first study. The variable
`PHP_CLI_SERVER_WORKERS` starts several PHP processes, so a running chat does not block the
saving of messages. Press <kbd>Ctrl</kbd>+<kbd>C</kbd> to stop the server.

**Next:** Continue with [After the installation](#after-the-installation).

---

## Option B: A Linux server

**You need** a server or virtual machine with Ubuntu 24.04 LTS or newer, and a domain name that
points to the server, for example `maestro.example.org`. Debian 12 and newer work with the same
commands. **The result** is a studio at `https://maestro.example.org` with Apache, PHP, MariaDB
and a free HTTPS certificate. Replace `maestro.example.org` with your domain name in every step
below.

### 1. Install the packages

```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php php-cli php-mysql php-curl php-mbstring php-zip mariadb-server git
```

### 2. Download MAESTRO

```bash
sudo git clone https://github.com/maestro-lab-science/maestro.git /var/www/maestro
cd /var/www/maestro
```

### 3. Create the database

Open the database console with `sudo mariadb`. In the console, create a database and a user for
MAESTRO. Replace `choose-a-password` with a strong password:

```sql
CREATE DATABASE maestro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'maestro'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL PRIVILEGES ON maestro.* TO 'maestro'@'localhost';
```

Type `exit` to leave the database console.

### 4. Create the configuration file

Copy the template and open the copy in a text editor:

```bash
sudo cp app/includes/config.local.example.php app/includes/config.local.php
sudo nano app/includes/config.local.php
```

Add the database settings and a secret for the share links of the data page at the end of the
file. The command `openssl rand -hex 32` prints a suitable random string for the secret:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'maestro');
define('DB_USER', 'maestro');
define('DB_PASS', 'choose-a-password');
define('DATA_ACCESS_KEY', 'paste-the-random-string-here');
```

Save the file with <kbd>Ctrl</kbd>+<kbd>O</kbd> and close the editor with
<kbd>Ctrl</kbd>+<kbd>X</kbd>. Afterwards, restrict the access to the configuration file, so that
only Apache can read the passwords:

```bash
sudo chown root:www-data app/includes/config.local.php
sudo chmod 640 app/includes/config.local.php
```

### 5. Create the tables

```bash
sudo php app/install/install.php
```

The installer prints `Database and tables are ready.` when the installer has succeeded.

### 6. Configure Apache

Create a configuration file for the website:

```bash
sudo nano /etc/apache2/sites-available/maestro.conf
```

Paste the following configuration into the file:

```apache
<VirtualHost *:80>
    ServerName maestro.example.org
    DocumentRoot /var/www/maestro

    <Directory /var/www/maestro>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>

    # The studio lives in app/, so the bare address leads to the studio.
    RedirectMatch ^/$ /app/

    # The server code, the installer, the tests and the Git history stay off the web.
    <LocationMatch "^/(\.git|app/(includes|install|tests))(/|$)">
        Require all denied
    </LocationMatch>
</VirtualHost>
```

`AllowOverride All` matters: MAESTRO ships `.htaccess` files that block the relay folder and
keep browsers from mixing cached and new scripts after an update. Activate the website and
restart Apache:

```bash
sudo a2enmod headers
sudo a2ensite maestro
sudo systemctl restart apache2
```

If the server uses the firewall `ufw`, open the web ports with `sudo ufw allow "Apache Full"`.

### 7. Switch on HTTPS

[Certbot](https://certbot.eff.org) gets a free certificate from Let's Encrypt, adds an HTTPS
version of the website and renews the certificate automatically:

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d maestro.example.org
```

Open `https://maestro.example.org` in your browser and create your first study.

**Next:** Continue with [After the installation](#after-the-installation). If many
participants will chat at the same time, also read
[Optional: The WebSocket relay](#optional-the-websocket-relay).

---

## Option C: Shared web hosting

**You need** a hosting plan with PHP and MySQL, for example the web space of your university.
**The result** is a studio at `https://your-domain.example/maestro/app/`. This setup needs no
command line.

1. **Prepare the hosting account.** In the control panel of your hosting provider, select
   PHP 8.1 or newer and create a MySQL database with a database user. Note the host name, the
   database name, the user name and the password. Many providers show `localhost` as the host
   name.
2. **Download MAESTRO.** Download the ZIP file on
   [GitHub](https://github.com/maestro-lab-science/maestro) (**Code → Download ZIP**) and
   unpack the ZIP file on your computer. The ZIP file unpacks into a folder called
   `maestro-main`, which you rename to `maestro`.
3. **Create the configuration file.** In the unpacked folder, copy
   `app/includes/config.local.example.php` to `app/includes/config.local.php`. Open the copy
   in a text editor and add these lines at the end, with the values from the control panel and
   a long random string as the secret for the share links of the data page:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'name-of-the-database');
   define('DB_USER', 'name-of-the-database-user');
   define('DB_PASS', 'password-of-the-database-user');
   define('DATA_ACCESS_KEY', 'a-long-random-string');
   ```

4. **Upload the files.** Upload the `maestro` folder to your web space. The file manager of the
   control panel or an SFTP program such as [FileZilla](https://filezilla-project.org) or
   [Cyberduck](https://cyberduck.io) can upload the folder.
5. **Create the tables.** Open `https://your-domain.example/maestro/app/install/install.php`
   in your browser. The page shows `Database and tables are ready.` when the installer has
   succeeded.
6. **Delete the installer.** Delete the folders `app/install/` and `app/tests/` from your web
   space. Anyone could run the installer from a browser, so the installer must not stay on a
   public server.
7. **Open the studio.** Open `https://your-domain.example/maestro/app/` and create your first
   study.

Shared web hosting cannot run the WebSocket relay, because the relay is a permanent background
process. Some providers end HTTP responses after a fixed time, which can interrupt long texts
of the AI assistant on the settings page. If this problem occurs, ask your provider about the
time limit or move to option B.

**Next:** Continue with [After the installation](#after-the-installation).

---

## After the installation

### Add an API key

MAESTRO sends every model call from the server, so participants never receive an API key.
Researchers enter an API key for each study in Step 2 of the study settings, and no further
setup is needed.

As an alternative, you can store a server-wide key in `app/includes/config.local.php`. Every
study without a key of its own uses the server-wide key:

```php
define('OPENAI_API_KEY', 'sk-...');
define('ANTHROPIC_API_KEY', 'sk-ant-...');
```

> [!IMPORTANT]
> Researchers can read the server-wide keys in Step 2 of the study settings, and anyone who can
> open the studio can create a study without an account. Store server-wide keys only on a
> computer or server that trusted people alone can reach, for example inside a university
> network. On a server that anyone on the internet can reach, leave the server-wide keys out,
> so that every researcher enters a key in Step 2.

### Update MAESTRO

Updates can add columns to the database, and the new code expects every column to exist.
Back up the database before an update, and update outside of data collection.

**With Git (options A and B):** Pull the new code and run the installer right away. The
installer adds only what is missing, so the installer is safe to run at any time. The commands
below use the folder of option B. On your own computer, run the same commands in your
`maestro` folder without `sudo`.

```bash
cd /var/www/maestro
sudo git pull
sudo php app/install/install.php
```

If you use the relay, restart the relay afterwards with `sudo systemctl restart maestro-relay`.

**With shared web hosting (option C):** Download and unpack the new ZIP file. Upload the
folder `app/install/` first and run the installer in your browser. After the installer has
succeeded, upload all other files. The ZIP file contains no `config.local.php`, so the upload
keeps your configuration. Finally, delete `app/install/` and `app/tests/` from your web space
again.

### Back up the data

The database holds all studies and conversations. Back up the database regularly, and keep a
copy of `app/includes/config.local.php`, because the share links of the data page depend on
`DATA_ACCESS_KEY`.

On a Linux server, this command saves the database into a compressed file in your home folder.
Keep backups outside of `/var/www/maestro`, because files in that folder are reachable from the
web.

```bash
sudo mariadb-dump --single-transaction maestro | gzip > ~/maestro-$(date +%F).sql.gz
```

This command restores the database from such a file:

```bash
gunzip < ~/maestro-2026-10-30.sql.gz | sudo mariadb maestro
```

On shared web hosting, the control panel or phpMyAdmin can export the database.

---

## Optional: The WebSocket relay

This section applies to option B only, and most studies work well without the relay. Without
the relay, every model response streams through Apache, and every active stream occupies one
Apache process for a few seconds. The relay helps if many participants chat at the same time,
for example in a lab session, or if a proxy in front of your server cuts responses after a fixed
time. If the browser cannot reach the relay, the browser falls back to direct streaming, so a
stopped relay never stops a study.

### 1. Install Node.js

The relay (`app/relay/server.js`) needs Node.js 20 or newer and no further packages. Check the
installed version with `node --version`. Ubuntu 24.04 ships an older version, so install a
current version from [NodeSource](https://github.com/nodesource/distributions):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo bash -
sudo apt install nodejs
```

### 2. Create a folder for relay jobs

PHP stores every model call in this folder, and the relay picks the call up from there:

```bash
sudo mkdir -m 700 /var/lib/maestro-relay
sudo chown www-data:www-data /var/lib/maestro-relay
```

### 3. Create the relay service

Open a new service file with `sudo nano /etc/systemd/system/maestro-relay.service` and paste
the following configuration:

```ini
[Unit]
Description=MAESTRO WebSocket relay
After=network.target

[Service]
User=www-data
Group=www-data
ExecStart=/usr/bin/node /var/www/maestro/app/relay/server.js
Environment=RELAY_HOST=127.0.0.1
Environment=RELAY_PORT=8021
Environment=RELAY_ORIGINS=https://maestro.example.org
Environment=RELAY_JOB_DIR=/var/lib/maestro-relay
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

Start the relay, and let the relay start again after every reboot:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now maestro-relay
```

### 4. Forward the relay through Apache

Certbot has created the HTTPS configuration `/etc/apache2/sites-available/maestro-le-ssl.conf`.
Add this line inside the `<VirtualHost>` block of that file:

```apache
    ProxyPass /relay http://127.0.0.1:8021/relay upgrade=websocket
```

Activate the proxy modules and restart Apache:

```bash
sudo a2enmod proxy proxy_http proxy_wstunnel
sudo systemctl restart apache2
```

The command `curl https://maestro.example.org/relay/health` now prints `{"ok":true,…}`.

### 5. Switch on the relay in MAESTRO

Add these lines to `app/includes/config.local.php`:

```php
define('RELAY_WS_URL', '/relay');
define('RELAY_JOB_DIR', '/var/lib/maestro-relay');
```

The change applies at once. A restart of the relay cancels the model calls that are running, so
restart the relay only outside of data collection. The command
`sudo journalctl -u maestro-relay` shows the log of the relay.

---

## Troubleshooting

**The studio shows "Database connection failed".** Check the database settings in
`app/includes/config.local.php`, and check that MySQL or MariaDB is running.

**The browser shows an empty page or the error 500.** A PHP extension is probably missing. The
command `php -m` lists the installed extensions, and the list must contain `mysqli`, `curl`,
`mbstring`, `iconv` and `zip`. On Ubuntu, install a missing extension with
`sudo apt install php-curl` (or the name of the missing extension) and restart Apache. The
error log of Apache (`/var/log/apache2/error.log`) names the exact error.

**The address of the server shows "Not Found" or a list of files.** The studio lives in the
folder `app/`. Open the address with `/app/` at the end, or add the redirect from
[step 6 of option B](#6-configure-apache).

**The messages of the AI actors arrive all at once, or the conductor feels slow.** Some web
servers and gateways hold back streamed output. MAESTRO pads every streamed event by default
(`LLM_SSE_PAD_BYTES`), which solves the problem for Apache. Some university gateways deliver a
reply only when the reply is complete. In that case, run the conductor on OpenAI or Anthropic
directly, or select the format of the Responses API in Step 2 of the study settings.

**Many participants share one network, for example in a lab session.** MAESTRO limits the
conductor calls per network address to 120 per minute. Raise the limit with
`RATE_DIRECTOR_PER_IP_MIN` in `app/includes/config.local.php`.

---

## Reference

### Settings of the configuration file

All settings are PHP constants in `app/includes/config.local.php`. Every constant is optional.
A constant that you leave out keeps the default from `app/includes/config.php`.

<details>
<summary>Show all settings of the configuration file</summary>

| Constant | Purpose | Default |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` | These constants set the database connection. | `127.0.0.1`, `3306`, `root`, no password, `aiteam_studio` |
| `OPENAI_API_KEY`, `OPENAI_MODEL` | The server-wide OpenAI key serves every study that stores no key of its own. | none, `gpt-5-mini` |
| `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` | The server-wide Anthropic key serves every study that stores no key of its own. | none, `claude-haiku-4-5-20251001` |
| `CUSTOM_LLM_ENDPOINT`, `CUSTOM_LLM_KEY`, `CUSTOM_LLM_MODEL`, `CUSTOM_LLM_API_VERSION` | These constants connect an OpenAI-compatible gateway, for example Azure API Management. | none |
| `SERVER_KEY_PROVIDERS` | This list names the providers whose server-wide key every study may use. Add `'custom'` to share the gateway key and the gateway endpoint as well. | `['openai', 'anthropic']` |
| `ASSISTANT_PROVIDER`, `ASSISTANT_MODEL` | These constants set the default model of the AI assistant on the settings page. Researchers can pick another model in the assistant panel. | `anthropic`, `claude-opus-5-5` |
| `DATA_ACCESS_KEY` | This secret protects the share links of the data page. Each study gets a link key of its own, so a link opens only the study of the link. | none, so share links stay off |
| `RELAY_WS_URL`, `RELAY_JOB_DIR` | These constants set the address of the WebSocket relay and the folder for relay jobs. | none, so the relay stays off |
| `LLM_SSE_PAD_BYTES` | This number sets the padding of streamed events for web servers that release output in blocks. Use `0` on hosts that pass output straight through. | `4200` |
| `EXAMPLE_PROJECTS` | This list maps each example condition to a study on your server. These studies appear as example studies on the studies page, and everyone can open and copy the studies without a password. | none |
| `RATE_DIRECTOR_PER_CONVERSATION_MIN`, `RATE_DIRECTOR_PER_CONVERSATION` | These limits count the conductor calls of one conversation per minute and in total, because the participant link is public. | `12`, `300` |
| `RATE_DIRECTOR_PER_IP_MIN` | This limit counts the conductor calls per minute from one network address. | `120` |
| `PASSWORD_MAX_FAILURES`, `PASSWORD_LOCK_MINUTES` | After this many wrong passwords from one network address, further tries wait for the given number of minutes. | `10`, `15` |

</details>

### Settings of the relay

The relay reads its settings from environment variables, for example from the `Environment=`
lines of the relay service.

<details>
<summary>Show all settings of the relay</summary>

| Variable | Purpose | Default |
|---|---|---|
| `RELAY_HOST`, `RELAY_PORT` | The relay listens on this address and port. Use `127.0.0.1` behind a reverse proxy. | `0.0.0.0`, `8021` |
| `RELAY_PATH` | This path serves the WebSocket endpoint and the health check (`<path>/health`). | `/relay` |
| `RELAY_ORIGINS` | This comma-separated list names the page origins that may connect. | any origin |
| `RELAY_PHP` | The relay starts this PHP binary for every model call. | `php` |
| `RELAY_MAX_WORKERS` | This number of model calls runs at the same time, and further calls wait in line. | `40` |
| `RELAY_MAX_QUEUE` | This number of calls may wait in line, and the relay refuses further calls. | `400` |
| `RELAY_JOB_DIR` | This folder must match `RELAY_JOB_DIR` in the PHP configuration. If the folder is set, the relay refuses requests with made-up tokens before a worker starts. | not set |

</details>

### For developers

The unit tests need Node.js 20 or newer, run without a browser and need no packages:

```bash
npm test
```

The [developer notes](DEVELOPER-NOTES.md) explain how the parts of MAESTRO work together. Model
prices live in `app/includes/pricing.php`, which is the only file to update when a provider
changes prices or releases new models.

<details>
<summary>Show the project structure</summary>

```
app/
├── projects.php    studies page: create or open a study, example studies, optional account
├── settings.php    the four steps of a study
├── aiTeam.php      the participant chat (?project=<ID>)
├── data.php        conversations, exports and costs of a study
├── api/            endpoints of the pages: conductor calls, messages, settings, accounts, exports
├── includes/       server code: model providers and streaming, conductor prompt, segments,
│                   AI assistant, prices, team, limits, PDF transcripts (fonts in includes/fonts/)
├── assets/         styles, fonts, images and the browser code (the chat runtime in js/chat/)
├── relay/          optional WebSocket relay and its PHP worker
├── install/        database schema and installer
└── tests/          unit tests
```

</details>

### Third-party components

- [Font Awesome Free](https://fontawesome.com) 5.15.4 provides the icons
  (`app/assets/fonts/font-awesome/`). The icons are licensed under CC BY 4.0, the fonts under
  the SIL Open Font License 1.1 and the code under the MIT license.
- [Noto Sans and Noto Emoji](https://notofonts.github.io) render the PDF transcripts
  (`app/includes/fonts/`) and are licensed under the SIL Open Font License 1.1.
