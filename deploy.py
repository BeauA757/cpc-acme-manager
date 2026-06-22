import paramiko
import os
import sys
import getpass
from pathlib import Path
import re

# ---------------------------------------------------------------------------
# Secure deployment script for CPC Certificate Manager iTop extension
# Credentials are read from creds.txt, environment variables, or interactive prompts
# ---------------------------------------------------------------------------

def _read_creds():
    """Parse IP and password from creds.txt if it exists."""
    creds_path = Path(__file__).parent / 'creds.txt'
    ip, user, password = None, 'cpcadmin', None
    if creds_path.is_file():
        text = creds_path.read_text(encoding='utf-8', errors='replace')
        ip_match = re.search(r'IP:\s*([\d\.]+)', text)
        if ip_match:
            ip = ip_match.group(1)
        user_match = re.search(r'Username:\s*(\S+)', text)
        if user_match:
            user = user_match.group(1)
        pass_match = re.search(r'Password:\s*(\S+)', text)
        if pass_match:
            password = pass_match.group(1)
    return ip, user, password

_CRED_IP, _CRED_USER, _CRED_PASS = _read_creds()

HOST = os.environ.get('ITOP_DEPLOY_HOST', _CRED_IP or '')
USER = os.environ.get('ITOP_DEPLOY_USER', _CRED_USER or 'cpcadmin')
SSH_KEY = os.environ.get('ITOP_DEPLOY_KEY', '')  # path to private key
SUDO_PASSWORD = os.environ.get('ITOP_SUDO_PASSWORD', _CRED_PASS or '')

LOCAL_DIR = Path.home() / 'Desktop' / 'CERT Manager'
REMOTE_BASE = '/var/www/html/itop/extensions/cpc-acme-manager'
ITOP_ROOT = '/var/www/html/itop'
SETUP_PARAMS = 'install-full-params.xml'


def get_password(prompt: str) -> str:
    """Read password from env or prompt interactively."""
    pw = os.environ.get('ITOP_SUDO_PASSWORD', '')
    if pw:
        return pw
    return getpass.getpass(prompt)


def run_sudo_command(client, command, password):
    cmd = f'echo "{password}" | sudo -S bash -c \'{command}\''
    stdin, stdout, stderr = client.exec_command(cmd)
    out = stdout.read().decode('utf-8', errors='replace')
    err = stderr.read().decode('utf-8', errors='replace')
    return out, err, stdout.channel.recv_exit_status()


def upload_file(sftp, local_path, remote_path, client, password):
    remote_dir = os.path.dirname(remote_path)
    # Ensure directory exists with correct ownership
    out, err, rc = run_sudo_command(client, f'mkdir -p {remote_dir} && chown {USER}:{USER} {remote_dir}', password)
    if rc != 0:
        print(f'  WARN: mkdir failed for {remote_dir}: {err.strip()}')
    # Remove target if exists to avoid permission issues
    run_sudo_command(client, f'rm -f {remote_path}', password)
    sftp.put(str(local_path), remote_path)
    run_sudo_command(client, f'chown {USER}:{USER} {remote_path}', password)


def main():
    password = get_password('Enter sudo password for remote host: ')

    print(f'Connecting to {HOST} as {USER}...')
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    if SSH_KEY and Path(SSH_KEY).exists():
        client.connect(HOST, username=USER, key_filename=SSH_KEY, timeout=30)
    else:
        ssh_password = get_password('Enter SSH password: ') if not SSH_KEY else password
        client.connect(HOST, username=USER, password=ssh_password, timeout=30)
    print('Connected.')

    # Test sudo
    out, err, rc = run_sudo_command(client, 'whoami', password)
    print(f'sudo whoami: {out.strip()} (rc={rc})')
    if rc != 0:
        print(f'sudo error: {err}')
        sys.exit(1)

    # Check iTop
    out, err, rc = run_sudo_command(client, f'ls -la {ITOP_ROOT}/setup/', password)
    print(f'iTop setup check: rc={rc}')
    if rc != 0:
        print(f'iTop not found at {ITOP_ROOT}')
        sys.exit(1)

    # Ensure extension directory exists and is owned by deploy user
    out, err, rc = run_sudo_command(client, f'mkdir -p {REMOTE_BASE} && chown -R {USER}:{USER} {REMOTE_BASE} && chmod 755 {REMOTE_BASE}', password)
    print(f'Ensured remote dir: rc={rc}')
    if rc != 0:
        print(f'  WARN: {err.strip()}')

    sftp = client.open_sftp()

    files_to_sync = [
        'pages/CertManagerPage.php',
        'src/Controller/CertManagerPage.php',
        'src/Model/Endpoint.php',
        'src/Service/CertificatePipeline.php',
        'src/Service/Config.php',
        'src/Service/Logger.php',
        'src/Service/NotificationService.php',
        'src/Service/SshRunner.php',
        'src/Task/CertManagerBackgroundTask.php',
        'datamodel.cpc-acme-manager.xml',
        'en.dict.cpc-acme-manager.php',
        'model.cpc-acme-manager.php',
        'module.cpc-acme-manager.php',
        'extension.xml',
        'composer.json',
        'vendor/autoload.php',
        'config.sample.json',
        'tests/verify-cpc-acme-manager.php',
        'bin/certmanager-cron-readme.txt',
        'restore-and-integrate.sh',
        'upgrade-cpc-acme-manager.sh',
        'install-cpc-acme-manager.sh',
        'default-params.xml',
        SETUP_PARAMS,
    ]

    for rel_path in files_to_sync:
        local_path = LOCAL_DIR / rel_path
        if not local_path.exists():
            print(f'SKIP (not found): {rel_path}')
            continue
        remote_path = f'{REMOTE_BASE}/{rel_path.replace(chr(92), "/")}'
        remote_dir = os.path.dirname(remote_path)
        run_sudo_command(client, f'mkdir -p {remote_dir}', password)
        print(f'UPLOAD: {rel_path} -> {remote_path}')
        sftp.put(str(local_path), remote_path)
        run_sudo_command(client, f'chown {USER}:{USER} {remote_path}', password)

    # Runtime dirs
    out, err, rc = run_sudo_command(client, 'mkdir -p /var/opt/cert-manager/{logs,endpoints,work,archive,live}', password)
    print(f'Runtime dirs: rc={rc}')

    # Config
    out, err, rc = run_sudo_command(client, f'if [ ! -f /var/opt/cert-manager/config.json ]; then cp {REMOTE_BASE}/config.sample.json /var/opt/cert-manager/config.json; fi', password)
    print(f'Config check: rc={rc}')

    run_sudo_command(client, 'chown -R www-data:www-data /var/opt/cert-manager && chmod 0700 /var/opt/cert-manager/endpoints', password)

    # Symlink
    out, err, rc = run_sudo_command(client, f'cd {ITOP_ROOT}/datamodels && if [ ! -e latest ]; then ln -s 2.x latest; chown -h www-data:www-data latest; fi', password)
    print(f'Symlink check: rc={rc}')

    # Unattended install/upgrade (matches working approach from final-install-run.py)
    out, err, rc = run_sudo_command(client, f'cd {ITOP_ROOT}/setup/unattended-install && php unattended-install.php --param-file={REMOTE_BASE}/{SETUP_PARAMS} --use_itop_config 2>&1', password)
    print(f'Unattended install: rc={rc}')
    print(out[-3000:] if len(out) > 3000 else out)
    if err:
        print(f'STDERR: {err[-3000:]}')

    # Verify env-production
    out, err, rc = run_sudo_command(client, f'ls -d {ITOP_ROOT}/env-production/cpc-acme-manager 2>/dev/null && echo ENV_OK || echo ENV_MISSING', password)
    print(f'env-production check: {out.strip()}')

    # Verify menu
    out, err, rc = run_sudo_command(client, f'php -r \'require "{ITOP_ROOT}/approot.inc.php"; require "{ITOP_ROOT}/application/startup.inc.php"; echo MetaModel::GetMenuItem("CpcAcmeManagerMenu") ? "MENU_OK" : "MENU_MISSING";\' 2>&1', password)
    print(f'Menu verification: {out.strip()} (rc={rc})')

    # Test suite
    out, err, rc = run_sudo_command(client, f'cd {REMOTE_BASE} && php tests/verify-cpc-acme-manager.php 2>&1', password)
    print(f'Extension test suite: rc={rc}')
    print(out[-3000:] if len(out) > 3000 else out)

    sftp.close()
    client.close()
    print('Done.')


if __name__ == '__main__':
    main()
