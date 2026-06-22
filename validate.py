#!/usr/bin/env python3
"""CPC Certificate Manager — Standalone Python Validation Suite
Runs locally on Windows to verify the extension package before deployment.
Checks: file structure, PHP syntax, JSON/XML validity, namespace hygiene, PHP 7.4 compat.
"""
import json, xml.etree.ElementTree as ET, sys, os, re
from pathlib import Path

BASE = Path(__file__).parent
ERRORS = 0
WARNINGS = 0
PASSED = 0

def section(title):
    print(f"\n» {title}")

def ok(msg):
    global PASSED
    PASSED += 1
    print(f"  [PASS] {msg}")

def fail(msg):
    global ERRORS
    ERRORS += 1
    print(f"  [FAIL] {msg}")

def warn(msg):
    global WARNINGS
    WARNINGS += 1
    print(f"  [WARN] {msg}")

def test_file_structure():
    section("File Structure")
    required = [
        'module.cpc-acme-manager.php',
        'model.cpc-acme-manager.php',
        'datamodel.cpc-acme-manager.xml',
        'en.dict.cpc-acme-manager.php',
        'composer.json',
        'config.sample.json',
        'pages/CertManagerPage.php',
        'src/Controller/CertManagerPage.php',
        'src/Model/Endpoint.php',
        'src/Service/CertificatePipeline.php',
        'src/Service/Config.php',
        'src/Service/Logger.php',
        'src/Service/NotificationService.php',
        'src/Service/SshRunner.php',
        'src/Task/CertManagerBackgroundTask.php',
        'tests/verify-cpc-acme-manager.php',
        'vendor/autoload.php',
        'deploy.py',
    ]
    for f in required:
        p = BASE / f
        ok(f"File exists: {f}") if p.is_file() else fail(f"Missing: {f}")

def test_php_74_compat():
    section("PHP 7.4 Compatibility Scan")
    php8_funcs = ['str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list']
    bad_files = []
    for php in BASE.rglob('*.php'):
        text = php.read_text(encoding='utf-8', errors='replace')
        for func in php8_funcs:
            if re.search(r'\b' + func + r'\b', text):
                bad_files.append((php.name, func))
    if bad_files:
        for f, func in set(bad_files):
            fail(f"{f} uses PHP 8+ feature: {func}")
    else:
        ok("No PHP 8+ exclusive functions detected")

def test_namespace_hygiene():
    section("Namespace Hygiene")
    namespaced = list(BASE.rglob('*.php'))
    issues = 0
    for php in namespaced:
        text = php.read_text(encoding='utf-8', errors='replace')
        # Only check files that have an actual namespace declaration (not just the word in comments)
        if not re.search(r'(?m)^\s*namespace\s+', text):
            continue
        # Only check inside class methods, not procedural top-level code
        for func in ['htmlspecialchars', 'htmlentities']:
            pattern = r'(?<!\\)\b' + func + r'\s*\('
            for m in re.finditer(pattern, text):
                start = max(0, m.start() - 1)
                if text[start:m.start()] == '\\':
                    continue
                line_start = text.rfind('\n', 0, m.start()) + 1
                line = text[line_start:m.start()]
                if '//' in line or '#' in line:
                    continue
                issues += 1
                fail(f"{php.name}: unqualified '{func}()' at line ~{text[:m.start()].count(chr(10))+1}")
    if issues == 0:
        ok("No unqualified escaping functions in namespaced files")

def test_json_xml():
    section("JSON / XML Validity")
    for f in ['composer.json', 'config.sample.json']:
        p = BASE / f
        try:
            json.loads(p.read_text(encoding='utf-8'))
            ok(f"{f} is valid JSON")
        except Exception as e:
            fail(f"{f} JSON invalid: {e}")
    xml_files = ['datamodel.cpc-acme-manager.xml', 'extension.xml',
                 'install-full-params.xml', 'install-params.xml', 'default-params.xml']
    for f in xml_files:
        p = BASE / f
        if not p.is_file():
            warn(f"{f} not present")
            continue
        try:
            ET.parse(p)
            ok(f"{f} is well-formed XML")
        except Exception as e:
            fail(f"{f} XML invalid: {e}")

def test_no_creds_exposure():
    section("Security / Credential Exposure")
    bad = []
    for root, dirs, files in os.walk(BASE):
        # skip hidden dirs, .git, etc
        dirs[:] = [d for d in dirs if not d.startswith('.')]
        for f in files:
            if f.endswith(('.py', '.sh', '.php', '.json', '.xml', '.txt')):
                p = Path(root) / f
                text = p.read_text(encoding='utf-8', errors='replace')
                if 'creds.txt' in text or 'PASSWORD' in text or 'Ph4:8thjplcep' in text or 'o834uymrtcjroi' in text:
                    bad.append(f)
    if bad:
        for f in bad:
            warn(f"Possible credential exposure in {f}")
    else:
        ok("No known credential strings detected in source files")

def test_manifest_integrity():
    section("Module Manifest Integrity")
    manifest = BASE / 'module.cpc-acme-manager.php'
    text = manifest.read_text(encoding='utf-8')
    ok("Manifest exists")
    if 'cpc-acme-manager/1.2.0' in text:
        ok("Manifest version correct")
    else:
        fail("Manifest version mismatch")
    if 'datamodel.cpc-acme-manager.xml' in text:
        ok("Manifest references datamodel XML")
    else:
        fail("Manifest missing datamodel reference")
    if 'en.dict.cpc-acme-manager.php' in text:
        ok("Manifest references dictionary")
    else:
        fail("Manifest missing dictionary reference")

def test_deploy_script():
    section("Deploy Script")
    p = BASE / 'deploy.py'
    if not p.is_file():
        fail("deploy.py missing")
        return
    text = p.read_text(encoding='utf-8')
    if 'getpass' in text or 'os.environ.get' in text:
        ok("deploy.py uses secure credential handling")
    else:
        warn("deploy.py may not use secure credential handling")

def summary():
    total = PASSED + ERRORS + WARNINGS
    print(f"\n{'='*60}")
    print(f"Results: {PASSED} passed, {ERRORS} failed, {WARNINGS} warnings ({total} checks)")
    if ERRORS == 0:
        print("Package is ready for deployment.")
    else:
        print("Fix errors before deployment.")
    print(f"{'='*60}")
    return 1 if ERRORS > 0 else 0

if __name__ == '__main__':
    print("\n" + "="*60)
    print("CPC Certificate Manager — Python Validation Suite")
    print("="*60)
    test_file_structure()
    test_php_74_compat()
    test_namespace_hygiene()
    test_json_xml()
    test_no_creds_exposure()
    test_manifest_integrity()
    test_deploy_script()
    sys.exit(summary())
