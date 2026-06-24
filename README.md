# CPC Certificate Manager for iTop

A production-ready iTop extension that replaces the legacy `copycerts.sh` and `rsyncssl.sh` certificate flow. It provides a GUI-driven admin dashboard, automated certificate pulls from a Synology NAS, local iTop certificate updates, and downstream distribution via SSH. Compatible with **iTop 3.2.x**.

## Scope
- Admin dashboard under **Admin Tools → Cert Manager**
- Runtime data under `/var/opt/cert-manager`
- Pulls wildcard ECC certificates for `cpcwebtech.com` and `corp.cpcot.org`
- Updates local iTop certs under `/home/cpcadmin/certs/<domain>/`
- Distributes to downstream hosts via SSH multiplexing
- Buffered logging and rotation under `/var/opt/cert-manager/logs`
- Background execution via iTop cron (`webservices/cron.php`)

---

## Requirements

- iTop 3.2.x (tested on 3.2.1)
- PHP 8.1+ (extension uses typed properties and `match` expressions)
- Composer (optional — a manual PSR-4 autoloader is included as fallback)
- SSH access to Synology NAS and downstream endpoints
- SSH key pairs for each endpoint (no password authentication)

---

## Installation

### Step 1: Deploy the extension

1. Clone or extract the extension into your iTop `extensions/` directory:
   ```bash
   cp -r cpc-acme-manager /var/www/html/itop/extensions/
   ```

2. (Optional) Install Composer dependencies:
   ```bash
   cd /var/www/html/itop/extensions/cpc-acme-manager
   composer install --no-dev -o
   ```
   If Composer is not available, the extension falls back to a built-in PSR-4 autoloader defined in `model.cpc-acme-manager.php`.

### Step 2: Prepare the runtime environment

```bash
sudo mkdir -p /var/opt/cert-manager/{logs,endpoints,work,archive,live}
sudo cp /var/www/html/itop/extensions/cpc-acme-manager/config.sample.json /var/opt/cert-manager/config.json
sudo chown -R www-data:www-data /var/opt/cert-manager
sudo chmod 0700 /var/opt/cert-manager/endpoints
```

Place your SSH private keys for Synology and downstream endpoints under `/var/opt/cert-manager/endpoints/` and restrict them:
```bash
sudo chmod 0600 /var/opt/cert-manager/endpoints/*
```

### Step 3: Configure `config.json`

Edit `/var/opt/cert-manager/config.json` to match your environment:

```json
{
  "runtime_base": "/var/opt/cert-manager",
  "paths": {
    "logs": "/var/opt/cert-manager/logs",
    "endpoints": "/var/opt/cert-manager/endpoints",
    "working": "/var/opt/cert-manager/work",
    "archive": "/var/opt/cert-manager/archive",
    "live": "/var/opt/cert-manager/live",
    "local_itop_certs": "/home/cpcadmin/certs"
  },
  "synology": {
    "host": "192.168.100.6",
    "user": "CertAdmin",
    "ssh_key": "/var/opt/cert-manager/endpoints/synology-pull",
    "source_base": "/usr/local/share/acme.sh",
    "domains": {
      "cpcwebtech.com": {
        "source_dir": "/usr/local/share/acme.sh/cpcwebtech.com_ecc",
        "cert_names": ["cpcwebtech.com", "*.cpcwebtech.com"]
      }
    }
  },
  "notifications": {
    "email_subject_prefix": "CPC Cert Alert",
    "teams_channel_email": "b15dd0f9.cpcot.org@amer.teams.ms",
    "also_email": []
  },
  "targets": [
    {"name": "Local iTop", "host": "192.168.100.213", "user": "cpcadmin", "base_destination": "/home/cpcadmin/certs"}
  ]
}
```

### Step 4: Run iTop Setup

For a first install or after any extension file changes, re-run the iTop setup wizard:

```bash
cd /var/www/html/itop/setup/unattended-install
php unattended-install.php \
  --param-file=/var/www/html/itop/extensions/cpc-acme-manager/install-full-params.xml \
  --use_itop_config
```

Or use the browser-based wizard at `https://<itop>/setup` and select **Cert Manager** on the Extensions step.

### Step 5: Verify the menu

After setup completes, log in to iTop. The admin page appears under **Admin Tools → Cert Manager**.

---

## iTop 3.2 Compatibility Notes

### WebPage vs iTopWebPage

In iTop 3.2, `exec.php` page execution does not initialize the full theme and navigation stack required by `iTopWebPage`. The extension therefore uses `WebPage` (a lighter base class) for the admin page when executed via `exec.php`, avoiding fatal errors from `SetupUtils` and `NavigationMenuFactory` initialization.

The `SetupUtils` class must be explicitly loaded before `application.inc.php` in the `exec.php` context. This is handled automatically in `pages/CertManagerPage.php`:
```php
require_once(APPROOT.'/setup/setuputils.class.inc.php');
require_once(APPROOT.'/application/application.inc.php');
require_once(APPROOT.'/application/startup.inc.php');
```

### Datamodel version

The extension datamodel declares `version="3.2"` to match the iTop 3.2 schema. Dependencies are pinned to `itop-config-mgmt/3.2.1` and `itop-tickets/3.2.1`.

---

## Cron Configuration

Add a system cron entry to run iTop's cron dispatcher every 5 minutes:

```bash
*/5 * * * * /usr/bin/php /var/www/html/itop/webservices/cron.php --auth_user=<admin_user> --auth_pwd=<admin_password> > /dev/null 2>&1
```

The background task `CpcCertManagerCron` is registered at module install time and executes every 300 seconds (5 minutes).

You can verify the task in the database:
```sql
SELECT class_name, status, periodicity FROM priv_backgroundtask WHERE class_name LIKE '%CertManager%';
```

---

## Post-Install Checklist

Before enabling in production, confirm the following:
1. `config.json` exists at `/var/opt/cert-manager/config.json` and is valid JSON.
2. Endpoint SSH keys are placed under `/var/opt/cert-manager/endpoints/`.
3. The runtime directory is owned by the PHP user (`www-data:www-data` or equivalent).
4. Private key permissions are `0600`.
5. iTop SMTP is configured and tested for MS365 delivery.
6. The server cron job for `webservices/cron.php` is active and running without errors.
7. The iTop Twig cache directory (`/var/www/html/itop/data/cache-production/twig`) is writable by `www-data`.

---

## Roles & Permissions

The admin page enforces `UserRights::IsAdministrator()` by default. Only iTop administrators can access the Cert Manager dashboard.

---

## Testing

A standalone test script is included to verify the extension before and after installation.

### Pre-install (standalone CLI)
From the extension directory, run:
```bash
cd /var/www/html/itop/extensions/cpc-acme-manager
php tests/verify-cpc-acme-manager.php
```
This validates:
- File structure and required files
- Composer autoload and class resolution
- Config service (loading, caching, dot-notation getter)
- Logger service (buffering, flush, rotation)
- SSH runner (multiplexing, batch SCP)
- Pipeline plans (pull, local deploy, downstream, notifications)
- Module manifest, datamodel XML, dictionary, and page controller

### Post-install (inside iTop)
After installing through the iTop setup wizard, verify the runtime integration:
```bash
cd /var/www/html/itop/extensions/cpc-acme-manager
php tests/verify-cpc-acme-manager.php --itop
```
This adds runtime checks for:
- Module registration in `MetaModel`
- Menu entry registration in the compiled datamodel
- Background task registration in the compiled datamodel

### Exit codes
- `0` = all checks passed
- `1` = one or more checks failed
- `2` = `--itop` flag used outside an iTop environment

---

## Upgrade Notes

When upgrading from a previous version of this extension:
1. Replace the extension folder in `extensions/cpc-acme-manager`.
2. Re-run `composer install --no-dev -o` if `composer.json` changed.
3. Run the iTop Setup wizard or the unattended install script to reload the datamodel delta.
4. The `CpcAcmeManagerInstaller` class will handle any new directory requirements automatically.

---

## Automated Deployment (Development)

For development or rapid iteration, the repository includes Python scripts that use `paramiko` to sync files and run the unattended setup over SSH. Credentials are read from `creds.txt`. These scripts are not required for production installation and are provided for development convenience only.
