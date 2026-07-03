# FileAudit

FileAudit is a small internal PHP dashboard for storing and searching Windows file audit events. It uses plain PHP, MariaDB, PDO, and no external dependencies.

![FileAudit dashboard](FileAudit%20screenshot.jpg)

## Install on Debian

```bash
sudo apt update
sudo apt install apache2 mariadb-server php8.4 php8.4-mysql libapache2-mod-php8.4
```

If your Debian release does not provide PHP 8.4 packages, use the default supported PHP packages instead:

```bash
sudo apt install apache2 mariadb-server php php-mysql libapache2-mod-php
```

Enable Apache rewrite only if your environment needs it. This app does not require pretty routing.

## Create the database

Log in to MariaDB as root:

```bash
mysql -u root -p
```

Then run:

```sql
CREATE DATABASE fileaudit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fileaudit_user'@'localhost' IDENTIFIED BY 'change_this_password';
GRANT ALL PRIVILEGES ON fileaudit.* TO 'fileaudit_user'@'localhost';
FLUSH PRIVILEGES;
```

Import the schema:

```bash
mysql -u fileaudit_user -p fileaudit < database/schema.sql
```

Run that import command from the normal Debian shell prompt, not from inside the `mysql>` or `MariaDB>` prompt. If you are already inside MariaDB, type `exit` first.

## Configure the app

Copy the example config and edit the values:

```bash
cp config/config.example.php config/config.php
```

Create an admin password hash:

```bash
php -r "echo password_hash('change_this_admin_password', PASSWORD_DEFAULT), PHP_EOL;"
```

Put the generated hash in `ADMIN_PASSWORD_HASH`. Set `API_TOKEN` to a long random value. If you want to restrict collectors by IP, add addresses to `TRUSTED_COLLECTOR_IPS`; leave it empty to allow any source IP with the correct token.

Set `APP_TIMEZONE` to the timezone you want displayed in the dashboard, for example:

```php
'APP_TIMEZONE' => 'Europe/London',
```

Collector timestamps are stored in UTC; the dashboard converts them for display.

## Apache

Point the Apache virtual host document root at the `public` directory, not the repository root.

Create or edit a site file under `/etc/apache2/sites-available/`:

```bash
sudo nano /etc/apache2/sites-available/fileaudit.conf
```

Example if the project is installed at `/var/www/fileaudit`:

```apache
<VirtualHost *:80>
    ServerName fileaudit.internal
    DocumentRoot /var/www/fileaudit/public

    <Directory /var/www/fileaudit/public>
        Require all granted
        AllowOverride None
    </Directory>
</VirtualHost>
```

If you copied the project to `/var/www/html`, use this path instead:

```apache
DocumentRoot /var/www/html/public

<Directory /var/www/html/public>
    Require all granted
    AllowOverride None
</Directory>
```

Enable the site and reload Apache:

```bash
sudo a2ensite fileaudit.conf
sudo systemctl reload apache2
```

If Apache still shows the default page, disable the default site and reload again:

```bash
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

`fileaudit.internal` only works if your DNS or hosts file resolves that name to the server. For local testing on the server itself, use `localhost` or `127.0.0.1`.

## Test the API

Run this from the normal Debian terminal after Apache, MariaDB, `config/config.php`, and the schema are in place. Replace the bearer token with the exact `API_TOKEN` value from `config/config.php`.

```bash
curl -X POST http://localhost/api/ingest.php \
  -H "Authorization: Bearer replace_this_with_a_long_random_token" \
  -H "Content-Type: application/json" \
  -d '{
    "server_name": "FS01",
    "computer_name": "FS01",
    "event_id": 4663,
    "record_id": 123456,
    "time_created": "2026-06-30T10:15:00Z",
    "username": "j.smith",
    "domain_name": "EXAMPLE",
    "source_ip": "192.0.2.55",
    "object_name": "C:\\Shares\\Finance\\budget.xlsx",
    "action": "Modified",
    "access_mask": "0x2",
    "process_name": "C:\\Windows\\explorer.exe",
    "status": "Success"
  }'
```

If Apache `DocumentRoot` points to `/var/www/html` instead of `/var/www/html/public`, the test URL would be `http://localhost/public/api/ingest.php`. The cleaner setup is to point `DocumentRoot` at the `public` directory and use `http://localhost/api/ingest.php`.

Expected first successful response:

```json
{"ok":true,"inserted":1,"duplicates":0,"errors":[]}
```

Running the same event again should be treated as a duplicate:

```json
{"ok":true,"inserted":0,"duplicates":1,"errors":[]}
```

If you see `Bearer token required`, check that the curl command starts with `curl -X POST` and includes exactly one authorization header like:

```bash
-H "Authorization: Bearer replace_this_with_a_long_random_token"
```

## Deletion correlation and Windows collector

FileAudit supports Windows event IDs `4656` and `4659` and stores correlation fields used to match deletion confirmation events with nearby path-bearing events.

Required event IDs:

- `4656` - A handle to an object was requested
- `4659` - A handle to an object was requested with intent to delete
- `4660` - An object was deleted
- `4663` - An attempt was made to access an object
- `4670` - Permissions on an object were changed
- `5145` - Detailed file share access check

Event `4660` often confirms deletion but does not include the file or folder path. Event `4659` is a strong delete-intent signal. FileAudit stores `handle_id` and `logon_id` so the event detail page can show related `4656`, `4659`, or `4663` events from the same server within plus/minus 2 minutes.

### Database schema

Fresh installs use `database/schema.sql`, which already includes the correlation fields and indexes. No separate migration is required.

### Windows Server auditing setup

Enable Advanced Audit Policy on the file server:

- Object Access > Audit File System: Success and Failure
- Object Access > Audit File Share: Failure only, optional
- Avoid enabling successful read/list auditing unless specifically required

Folder SACL guidance:

1. Open the file share folder properties.
2. Go to Security > Advanced > Auditing.
3. Add narrow auditing entries for `Domain Users`, a target security group, or `Everyone`.

Recommended Success permissions:

- Create files / write data
- Create folders / append data
- Write attributes
- Write extended attributes
- Delete
- Delete subfolders and files
- Change permissions
- Take ownership

Recommended Failure permissions:

- Write data
- Delete
- Delete subfolders and files
- Change permissions
- Take ownership

Do not audit successful `Read data/List folder` unless you really need it. Successful read/list auditing can generate very large event volumes.

### Security log size

Start with a 1 GB Security log for small sites, or 2 GB if the server is busy. Use overwrite-as-needed retention.

```cmd
wevtutil sl Security /ms:1073741824
wevtutil gl Security
```

### Collector install

The collector is in the `collector` directory and works with Windows PowerShell 5.1. PowerShell 7 is not required.

On the Windows file server:

1. Copy the `collector` folder to the server.
2. Copy `collector-config.example.ps1` to `collector-config.ps1`.
3. Set `$ApiUrl` to your FileAudit API URL.
4. Set `$ApiToken` to the same value as `API_TOKEN` in `config/config.php`.
5. Run PowerShell as Administrator.
6. Run `FileAuditCollector.ps1` manually first.
7. Check `C:\ProgramData\FileAudit\collector.log`.
8. Confirm events arrive in the dashboard.
9. Create the Scheduled Task.

Default collector config:

```powershell
$ApiUrl = "https://fileaudit.local/api/ingest.php"
$ApiToken = "CHANGE_ME"
$ServerName = $env:COMPUTERNAME
$EventIds = @(4656,4659,4660,4663,4670,5145)
$BatchSize = 100
$MaxEventsPerRun = 2000
$FirstRunLookbackMinutes = 30
$StatePath = "C:\ProgramData\FileAudit\state.json"
$LogPath = "C:\ProgramData\FileAudit\collector.log"
```

The collector stores `last_record_id` in the state file and only processes events with a higher `RecordId`. On first run, when no state file exists, it reads only recent events from the last `$FirstRunLookbackMinutes` minutes so it does not import the entire Security log.

State is only updated after the API accepts all posted batches.

For HTTPS with internal certificates, install a trusted certificate on the Windows server. Do not disable certificate validation by default. For early lab testing, HTTP on an internal network is simpler.

If the collector reports no matching Security events but you can find those event IDs in Event Viewer, check the event times and the collector state file. They may be older than `$FirstRunLookbackMinutes`, or `C:\ProgramData\FileAudit\state.json` may already contain a `last_record_id` higher than those events.

Useful manual checks:

```powershell
Get-WinEvent -FilterHashtable @{LogName="Security"; Id=4656,4659,4660,4663,4670,5145; StartTime=(Get-Date).AddMinutes(-30)} -MaxEvents 20 |
    Select-Object TimeCreated, Id, RecordId, ProviderName

Get-Content C:\ProgramData\FileAudit\state.json
```

For initial testing, you can temporarily increase `$FirstRunLookbackMinutes` in `collector-config.ps1`, or delete `C:\ProgramData\FileAudit\state.json` and rerun the collector after generating fresh test activity.

### Scheduled task

Review `collector/install-scheduled-task.example.ps1` and edit the script path before using it. It demonstrates a task that runs every 5 minutes using:

```cmd
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Path\To\FileAuditCollector.ps1"
```

The example uses `SYSTEM`. You can instead use a dedicated service account if that account can read the local Security event log.

### Collector test checklist

Use a temporary audited folder and test:

- Create a file
- Modify a file
- Copy a file into the share
- Rename a file
- Move a file inside the share
- Move a file out of the share
- Delete a file
- Change permissions on a test folder

Expected interpretation notes:

- Copies usually appear as `Created`, `Written`, or `Modified`.
- Moves may appear as `Deleted`, `Created`, or `Modified` depending on whether the move stays on the same volume and how the client performs it.
- Event `4660` confirms deletion but may not contain the path, so FileAudit uses related `4656`, `4659`, and `4663` events with the same Handle ID and Logon ID for correlation.
- Event `4659` means Windows requested a handle with intent to delete. FileAudit stores it as `DeleteRequested`, and the dashboard can display correlated path-bearing events as `Deleted` when `4659` or `4660` evidence exists.
- Event `4663` with access mask `0x10000` means DELETE access was requested or used successfully. It does not always prove the file was removed, so the collector records it as `DeleteRequested`.
- The Deletions page shows both `Deleted` and `DeleteRequested` events so path-bearing deletion activity remains visible.

For deletion reporting, do not rely on `4660` alone. Windows often logs the useful path-bearing signal as `4663` with DELETE access immediately before, or sometimes instead of, a `4660` confirmation. FileAudit displays stored `DeleteRequested` events as `Delete Activity` in tables because they are usually the best way to answer who performed deletion-related activity against a specific path.

To reduce application noise in reports, consider filtering out paths ending in `.tmp` or Office lock files containing `~$`.
