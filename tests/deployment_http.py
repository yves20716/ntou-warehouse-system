"""Exercise account provisioning and password-change session revocation on QA only."""
import os
from pathlib import Path
import secrets
import subprocess
import sys
from http.cookiejar import CookieJar
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPCookieProcessor, ProxyHandler, build_opener
from backend_http import Page


def run(base, php):
    if os.environ.get('DB_NAME') != 'warehouse_qa' or os.environ.get('DB_HOST') != '127.0.0.1':
        raise SystemExit('Load only the isolated warehouse_qa environment.')
    parsed = urlsplit(base)
    if (parsed.scheme, parsed.hostname, parsed.port, parsed.path) != ('http', '127.0.0.1', 18767, ''):
        raise SystemExit('Use only http://127.0.0.1:18767.')
    root = Path(__file__).resolve().parents[1]
    tag = secrets.token_hex(5)
    password = secrets.token_urlsafe(20)

    def account(args, value, expected=0):
        result = subprocess.run([php, str(root / 'bin/account.php'), *args],
                                input=value + '\n', text=True, capture_output=True, cwd=root)
        assert result.returncode == expected, (args[:1], result.returncode, result.stderr)
        assert value not in result.stdout + result.stderr

    def login(username, value, role, success=True):
        browser = build_opener(ProxyHandler({}), HTTPCookieProcessor(CookieJar()))
        with browser.open(base + '/', timeout=10) as response:
            page = Page(response.read().decode(), response.url)
        fields = page.form('login')['fields'] | {'username': username, 'password': value}
        with browser.open(base + '/actions.php', urlencode(fields).encode(), timeout=10) as response:
            path = urlsplit(response.url).path
            assert path == ('/' + role + '.php' if success else '/index.php'), path
        return browser

    for role, entity in [('client', '1'), ('employee', '2'), ('admin', '1')]:
        username = 'deploy_' + tag + '_' + role
        account(['create', username, role, entity], password)
        browser = login(username, password, role)
        account(['create', username, role, entity], password, expected=1)
        if role == 'client':
            new_password = secrets.token_urlsafe(20)
            account(['password', username], new_password)
            with browser.open(base + '/client.php', timeout=10) as response:
                assert urlsplit(response.url).path == '/index.php', 'Old session survived password change.'
            login(username, password, role, success=False)
            login(username, new_password, role)
    print('PASS CLI creates all three roles; duplicate account is rejected; reset invalidates old login and password')
    account(['create', 'deploy_' + tag + '_badrole', 'superadmin', '1'], password, expected=1)
    account(['create', 'deploy_' + tag + '_missing', 'client', '2147483647'], password, expected=1)
    account(['password', 'deploy_' + tag + '_absent'], password, expected=1)
    account(['create', 'deploy_' + tag + '_short', 'client', '1'], 'short', expected=1)
    account(['create', 'deploy_' + tag + '_demo', 'client', '1'], 'WarehouseDemo!26', expected=1)
    print('PASS invalid roles, missing owner/account, weak or public demo passwords are rejected; QA tag ' + tag)


if __name__ == '__main__':
    run(sys.argv[1].rstrip('/'), sys.argv[2] if len(sys.argv) > 2 else 'php')
