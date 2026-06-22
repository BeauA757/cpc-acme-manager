# CPC Certificate Manager for iTop

## Overview

GUI-driven certificate orchestration for iTop 3.1.x and 3.2.x. This extension replaces the legacy `copycerts.sh` and `rsyncssl.sh` flow with a modern, integrated solution that pulls wildcard ECC certificates from a Synology NAS, updates local iTop certificates, and distributes them to downstream hosts via SSH.

## Version

1.2.0 — iTop 3.1/3.2 compatible

## Performance Enhancements

- **Config caching**: In-memory cache with `filemtime` validation; optional APCu cross-request caching for reduced disk I/O.
- **Buffered logging**: Log entries are batched and flushed on threshold or destruct; includes automatic rotation at 10 MB with retention of 5 files.
- **SSH multiplexing**: ControlMaster connections are reused for the duration of execution, eliminating per-command SSH handshake overhead.
- **Lazy service initialization**: Heavy services (SSH, logger, notifications) are only instantiated when first accessed.
- **Pipeline caching**: Computed plans (pull, deploy, downstream) are cached within a single request to avoid redundant computation.
- **Notification retry**: Configurable retry logic with exponential backoff for reliable delivery.

## File Index

```
cpc-acme-manager/
├── bin/
│   └── certmanager-cron-readme.txt     # Cron integration notes
├── src/
│   ├── Controller/
│   │   └── CertManagerPage.php          # Legacy standalone page (deprecated)
│   ├── Model/
│   │   └── Endpoint.php                 # Endpoint data model
│   ├── Service/
│   │   ├── CertificatePipeline.php       # Core orchestration logic
│   │   ├── Config.php                    # Cached configuration loader
│   │   ├── Logger.php                    # Buffered, rotating logger
│   │   ├── NotificationService.php       # Queued, retry-aware notifications
│   │   └── SshRunner.php                 # SSH multiplexing and batch SCP
│   └── Task/
│       └── CertManagerBackgroundTask.php # iBackgroundProcess cron task
├── pages/
│   └── CertManagerPage.php              # iTop 3.1/3.2 admin page controller
├── composer.json                        # PSR-4 autoloader
├── config.sample.json                   # Runtime configuration template
├── datamodel.cpc-acme-manager.xml       # iTop datamodel (menu, task, rights)
├── en.dict.cpc-acme-manager.php         # English dictionary strings
├── model.cpc-acme-manager.php           # iTop runtime bootstrap + installer
├── module.cpc-acme-manager.php          # iTop module manifest (v1.2.0)
└── README.md                            # Installation and usage guide
```

## Install

1. Place this folder in `extensions/cpc-acme-manager`
2. Run `composer install --no-dev -o`
3. Copy `config.sample.json` to `/var/opt/cert-manager/config.json`
4. Place endpoint SSH keys under `/var/opt/cert-manager/endpoints/`
5. Run iTop setup/upgrade
6. The admin page appears under **Admin Tools → CPC Certificate Manager**

## Cron

Run iTop cron on a schedule, for example:

```bash
*/5 * * * * /usr/bin/php /var/www/html/webservices/cron.php --auth_user=<user> --auth_pwd=<pwd>
```

The background task is automatically registered at module installation and runs every 5 minutes.

## Roles

Designed for Administrator and HelpDesk access policies. The admin page enforces `UserRights::IsAdministrator()` by default.

## Important

1. Ensure `/var/opt/cert-manager` is owned by the PHP runtime user (`www-data` or equivalent)
2. Restrict private-key file permissions to `0600`
3. Ensure iTop SMTP is configured for MS365 before production enablement
4. Add NGINX route for `/certmanager` to the iTop entry point if rewrite rules require it
