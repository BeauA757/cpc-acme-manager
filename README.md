# CPC Certificate Manager for iTop

A production-ready iTop extension that replaces the legacy `copycerts.sh` and `rsyncssl.sh` certificate flow. It provides a GUI-driven admin dashboard, automated certificate pulls from a Synology NAS, local iTop certificate updates, and downstream distribution via SSH. Compatible with iTop 3.1.x and 3.2.x.

## Scope
- Admin dashboard under **Admin Tools → CPC Certificate Manager**
- Runtime data under `/var/opt/cert-manager`
- Pulls wildcard ECC certificates for `cpcwebtech.com` and `corp.cpcot.org`
- Updates local iTop certs under `/home/cpcadmin/certs/<domain>/`
- Distributes to downstream hosts
- Buffered logging and rotation under `/var/opt/cert-manager/logs`
- Background execution via iTop cron (`webservices/cron.php`)

---

## Install — iTop 3.1

### Step 1: Deploy the extension
1. Extract `cpc-acme-manager-v1.2.0.zip` (or clone the folder).
2. Copy the entire folder into your iTop `extensions/` directory:
   ```bash
   cp -r cpc-acme-manager /var/www/html/extensions/
   ```
3. Install Composer dependencies:
   ```bash
   cd /var/www/html/extensions/cpc-acme-manager
   composer install --no-dev -o
   ```

### Step 2: Prepare the runtime environment
```bash
sudo mkdir -p /var/opt/cert-manager/{logs,endpoints,work,archive,live}
sudo cp config.sample.json /var/opt/cert-manager/config.json
sudo chown -R www-data:www-data /var/opt/cert-manager
sudo chmod 0700 /var/opt/cert-manager/endpoints
```

Place your Synology and endpoint SSH private keys under `/var/opt/cert-manager/endpoints/` and restrict them:
```bash
sudo chmod 0600 /var/opt/cert-manager/endpoints/*
```

### Step 3: Run iTop Setup
1. Open your browser to `https://<itop>/setup`.
2. Log in as an administrator.
3. On the **Extensions** step, tick **CPC Certificate Manager** and click **Next**.
4. The installer will automatically create required subdirectories if they do not exist.
5. Complete the setup wizard.

### Step 4: Verify the menu
After setup completes, log in to iTop. The admin page appears under **Admin Tools → CPC Certificate Manager**. The menu is automatically registered via the `datamodel.cpc-acme-manager.xml` and `model.cpc-acme-manager.php` bootstrap.

---

## Install — iTop 3.2

### Step 1: Deploy the extension
1. Extract `cpc-acme-manager-v1.2.0.zip`.
2. Copy the folder into the iTop extensions directory:
   ```bash
   cp -r cpc-acme-manager /var/www/html/extensions/
   ```
3. Install Composer dependencies:
   ```bash
   cd /var/www/html/extensions/cpc-acme-manager
   composer install --no-dev -o
   ```

### Step 2: Prepare the runtime environment
```bash
sudo mkdir -p /var/opt/cert-manager/{logs,endpoints,work,archive,live}
sudo cp config.sample.json /var/opt/cert-manager/config.json
sudo chown -R www-data:www-data /var/opt/cert-manager
sudo chmod 0700 /var/opt/cert-manager/endpoints
```

Place SSH private keys and restrict permissions:
```bash
sudo chmod 0600 /var/opt/cert-manager/endpoints/*
```

### Step 3: Run iTop Setup (or use the `toolkit` for delta reload)

#### Option A — Full Setup wizard (recommended for first install)
1. Open `https://<itop>/setup` in your browser.
2. Log in as an administrator.
3. On the **Extensions** step, select **CPC Certificate Manager**.
4. Complete the wizard.

#### Option B — Toolkit delta reload (for upgrades or recompilation)
If the extension files are already present and you only need to reload the datamodel/menu changes:
1. Navigate to **Admin Tools → iTop Toolkit** (or `/toolkit` if available).
2. Click **Update iTop code** to recompile the delta XML and PHP bootstrap.
3. Click **Rebuild menus and cache** to refresh the menu registry.

### Step 4: Verify the menu and cron task
After setup or toolkit reload:
- Log in to iTop and open **Admin Tools → CPC Certificate Manager**.
- The background task is automatically registered. You can verify it under **Admin Tools → Run Queries** or by checking the `priv_backgroundtask` table for `CpcCertManagerCron`.

---

## Cron Configuration

### iTop 3.1
Add a system cron entry to run iTop's cron dispatcher:
```bash
*/5 * * * * /usr/bin/php /var/www/html/webservices/cron.php --auth_user=<admin_user> --auth_pwd=<admin_password> > /dev/null 2>&1
```
The background task `CpcCertManagerCron` is registered at module install time and executes every 5 minutes (`300` seconds).

### iTop 3.2
Same cron configuration as 3.1. In iTop 3.2, background tasks are managed through the unified cron service:
```bash
*/5 * * * * /usr/bin/php /var/www/html/webservices/cron.php --auth_user=<admin_user> --auth_pwd=<admin_password> > /dev/null 2>&1
```
You can also verify the task in the **Background Tasks** admin panel (if installed) or via SQL:
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
7. If using a reverse proxy (NGINX), add a rewrite rule for `/certmanager` to the iTop entry point if required.

---

## Roles & Permissions

The admin page enforces `UserRights::IsAdministrator()` by default. You can adjust the profile in `datamodel.cpc-acme-manager.xml` under the `<user_rights>` section if HelpDesk or other profiles need access.

---

## Testing

A standalone test script is included to verify the extension before and after installation.

### Pre-install (standalone CLI)
From the extension directory, run:
```bash
cd /var/www/html/extensions/cpc-acme-manager
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
cd /var/www/html/extensions/cpc-acme-manager
php tests/verify-cpc-acme-manager.php --itop
```
This adds runtime checks for:
- Module registration in `MetaModel`
- Menu entry registration
- Background task registration

### Exit codes
- `0` = all checks passed
- `1` = one or more checks failed
- `2` = `--itop` flag used outside an iTop environment

---

## Upgrade Notes

When upgrading from a previous version of this extension:
1. Replace the extension folder in `extensions/cpc-acme-manager`.
2. Re-run `composer install --no-dev -o` if `composer.json` changed.
3. Run the iTop Setup wizard or use the **iTop Toolkit** to reload the datamodel delta.
4. The `CpcAcmeManagerInstaller` class will handle any new directory requirements automatically.
