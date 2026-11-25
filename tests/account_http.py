"""Account governance checks for a disposable loopback installation.

The caller supplies host-only token issuance and SQL access. Neither capability
is exposed through the account administrator's browser.
"""
from http.cookiejar import CookieJar
from concurrent.futures import ThreadPoolExecutor
import secrets
import ssl
from urllib.parse import parse_qs, urlsplit
from urllib.request import HTTPCookieProcessor, HTTPSHandler, ProxyHandler, build_opener

from backend_http import Browser, LocalRedirect, NoRedirect
from docker_smoke import SmokeBrowser


def guest(base, ca=None):
    browser = Browser.__new__(Browser)
    browser.base, browser.role = base.rstrip('/') + '/', ''
    cookies = CookieJar()
    context = ssl.create_default_context(cafile=ca)
    browser.opener = build_opener(ProxyHandler({}), LocalRedirect(), HTTPCookieProcessor(cookies), HTTPSHandler(context=context))
    browser.direct = build_opener(ProxyHandler({}), NoRedirect(), HTTPCookieProcessor(cookies), HTTPSHandler(context=context))
    return browser


def exercise_accounts(base, password, issue, query, ca=None):
    """Use real forms; keep fixtures separate from the ordinary demo accounts."""
    assert urlsplit(base).hostname in ('127.0.0.1', 'localhost', '::1')
    base = base.rstrip('/') + '/'
    context = ssl.create_default_context(cafile=ca)
    admin = SmokeBrowser(base, 'hr', 'admin', password, context)
    client = SmokeBrowser(base, 'client', 'client', password, context)
    employee = SmokeBrowser(base, 'employee', 'employee', password, context)
    tag = secrets.token_hex(6)
    name = 'qa_account_' + tag
    new_password = secrets.token_urlsafe(24)
    all_tokens = []
    movement_count = query('SELECT COUNT(*) AS n FROM movements', [])[0]['n']

    def account(username):
        found = query('SELECT * FROM users WHERE username=?', [username])
        assert len(found) == 1, 'Expected one fixture account.'
        return found[0]

    def create(username, role='client', owner='1', expected='success'):
        fields = {'username': username, 'role': role, 'client_id': owner if role == 'client' else '',
                  'employee_id': owner if role != 'client' else ''}
        return admin.submit(admin.view('accounts'), 'account_create', fields, expected=expected)

    def action(username, action_name, values=None, expected='success'):
        record = account(username)
        return admin.submit(admin.view('accounts', q=username), action_name, values,
                            record_id=record['id'], expected=expected)

    def token(username):
        link = issue(username)
        parsed = urlsplit(link.strip())
        assert parsed.path.endswith('/activate.php') and not parsed.query
        value = parse_qs(parsed.fragment)['token'][0]
        assert len(value) >= 64
        all_tokens.append(value)
        return value

    def activate(value, value_password=new_password, expected='success', browser=None):
        browser = browser or guest(base, ca)
        form = browser.fetch('activate.php').form('password_activate')['fields']
        page = browser.fetch('actions.php', form | {'token': value, 'password': value_password,
                                                    'password_confirmation': value_password})
        page.outcome(expected)
        return page

    def login(username, value, success=True):
        browser = guest(base, ca)
        form = browser.fetch('index.php').form('login')['fields']
        page = browser.fetch('actions.php', form | {'username': username, 'password': value})
        if success:
            browser.role = account(username)['role']
            assert urlsplit(page.url).path.endswith('/' + browser.role + '.php'), 'Fixture login failed.'
        else:
            assert urlsplit(page.url).path.endswith('/index.php'), 'Blocked credentials authenticated.'
        return browser

    for browser in [client, employee]:
        csrf = browser.view('overview').form('logout')['fields']['csrf']
        for operation in ['account_create', 'account_status', 'account_reset_request']:
            assert browser.fetch('actions.php', {'csrf': csrf, 'action': operation}).status == 403
        assert browser.fetch('admin.php').status == 403
    assert admin.fetch('employee.php').status == 403
    assert admin.fetch('client.php').status == 403
    assert not any(x in admin.view('accounts').body for x in ['password_hash', 'token_hash'])
    create(name + '_super', role='admin', expected='error')
    create(name + '_owner', role='employee', owner='1', expected='error')
    create(name)
    pending = account(name)
    assert pending['role'] == 'client' and pending['status'] == 'pending'
    assert str(pending['client_id']) == '1' and pending['employee_id'] is None
    login(name, password, success=False)

    # Forge an active transition and edits to immutable identity using a genuine
    # action token. The endpoint must reject them independently of visible UI.
    form = admin.view('accounts', q=name).form('account_status', pending['id'])['fields']
    admin.fetch('actions.php', form | {'status': 'active'}).outcome('error')
    action(name, 'account_status', {'status': 'disabled'})
    action(name, 'account_status', {'status': 'active'})
    assert account(name)['status'] == 'pending', 'Restoring an unactivated account bypassed password setup.'
    form = admin.view('accounts', q=name).form('account_status', pending['id'])['fields']
    admin.fetch('actions.php', form | {'id': account('hr')['id'], 'status': 'disabled'}).outcome('error')
    assert account('hr')['status'] == 'active'
    staff_form = admin.view('staff').form('employee_save')['fields']
    admin.fetch('actions.php', staff_form | {'id': '1', 'name': 'Forbidden', 'job_title': 'Forbidden',
                                            'employment_status': 'inactive'}).outcome('error')
    print('PASS account administration role boundaries, protected administrators and pending activation', flush=True)

    first = token(name)
    second = token(name)
    activate(first, expected='error')
    activate(second, value_password='short', expected='error')
    activate(second)
    activate(second, expected='error')
    session = login(name, new_password)
    dormant_session = login(name, new_password)
    stale_form = session.view('goods').form('client_goods_save')['fields']
    before = account(name)
    form = admin.view('accounts', q=name).form('account_status', before['id'])['fields']
    admin.fetch('actions.php', form | {'status': 'disabled', 'client_id': '2', 'role': 'admin',
                                      'employee_id': '1'}).outcome('success')
    after = account(name)
    assert (after['role'], after['client_id'], after['employee_id']) == ('client', before['client_id'], None)
    assert account(name)['status'] == 'disabled'
    blocked = session.fetch('actions.php', stale_form | {'name': 'revoked_' + tag, 'unit': '箱'})
    assert urlsplit(blocked.url).path.endswith('/index.php')
    assert not query('SELECT id FROM goods WHERE name=?', ['revoked_' + tag])
    assert urlsplit(session.view('inventory').url).path.endswith('/index.php')
    login(name, new_password, success=False)
    action(name, 'account_status', {'status': 'active'})
    assert urlsplit(session.view('inventory').url).path.endswith('/index.php'), 'Restoring account revived a revoked session.'
    assert urlsplit(dormant_session.view('inventory').url).path.endswith('/index.php'), 'A dormant session survived suspension and restoration.'
    session = login(name, new_password)
    action(name, 'account_reset_request')
    assert urlsplit(session.view('inventory').url).path.endswith('/index.php')
    login(name, new_password, success=False)
    reset = token(name)
    changed_password = secrets.token_urlsafe(24)
    activate(reset, changed_password)
    login(name, new_password, success=False)
    login(name, changed_password)
    print('PASS one-time setup, token replacement/replay, immutable ownership and immediate session revocation', flush=True)

    expired = token(name)
    query('UPDATE account_tokens SET expires_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE) WHERE user_id=?', [pending['id']])
    activate(expired, expected='error')
    disabled_token = token(name)
    action(name, 'account_status', {'status': 'disabled'})
    activate(disabled_token, expected='error')
    print('PASS expired and disabled-account links rejected', flush=True)

    race_name = name + '_race'
    create(race_name)
    race_token = token(race_name)
    attempts = []
    for _ in range(2):
        browser = guest(base, ca)
        fields = browser.fetch('activate.php').form('password_activate')['fields']
        attempts.append((browser, fields | {'token': race_token, 'password': new_password,
                                            'password_confirmation': new_password}))
    with ThreadPoolExecutor(max_workers=2) as pool:
        outcomes = list(pool.map(lambda attempt: attempt[0].fetch('actions.php', attempt[1]), attempts))
    assert sum(any('success' in css.split() for css, _ in page.notices) for page in outcomes) == 1
    assert sum(any('error' in css.split() for css, _ in page.notices) for page in outcomes) == 1
    race_id = account(race_name)['id']
    assert int(query("SELECT COUNT(*) AS n FROM admin_audit WHERE target_user_id=? AND event='account_activated'", [race_id])[0]['n']) == 1
    print('PASS two simultaneous setup submissions activate once', flush=True)

    staff_name = '帳號測試員工-' + tag
    admin.submit(admin.view('staff'), 'employee_save', {'name': staff_name, 'job_title': '理貨員',
                  'monthly_salary': '36000', 'employment_status': 'active'})
    staff = query('SELECT id FROM employees WHERE name=?', [staff_name])[0]
    employee_name = name + '_worker'
    create(employee_name, 'employee', str(staff['id']))
    activate(token(employee_name))
    worker_session = login(employee_name, new_password)
    admin.submit(admin.view('staff', edit=staff['id']), 'employee_save', {'employment_status': 'inactive'})
    assert urlsplit(worker_session.view('inventory').url).path.endswith('/index.php')
    login(employee_name, new_password, success=False)
    create(name + '_inactive', 'employee', str(staff['id']), expected='error')
    print('PASS inactive employees cannot retain sessions or receive new accounts', flush=True)

    audit = query('SELECT * FROM admin_audit ORDER BY id', [])
    import json
    serialized = json.dumps(audit, ensure_ascii=False)
    assert any(str(row.get('target_user_id')) == str(pending['id']) for row in audit), 'Missing account audit events.'
    assert any(str(row.get('target_employee_id')) == str(staff['id']) for row in audit), 'Missing employee audit events.'
    assert all(value not in serialized for value in all_tokens + [password, new_password, changed_password])
    assert not query('SELECT id FROM users WHERE username=?', [name + '_super'])
    assert not query('SELECT id FROM users WHERE username=?', [name + '_owner'])
    assert query('SELECT COUNT(*) AS n FROM movements', [])[0]['n'] == movement_count
    print('PASS account and personnel audit records contain no credentials; rejected privilege grants leave no account', flush=True)
    return {'fixture': name, 'user_id': pending['id'], 'checks': 'passed'}


if __name__ == '__main__':
    import json
    import os
    from pathlib import Path
    import subprocess
    import sys
    if len(sys.argv) not in (2, 3) or sys.argv[1].rstrip('/') != 'http://127.0.0.1:18767':
        raise SystemExit('Usage: python3 tests/account_http.py http://127.0.0.1:18767 [php]')
    if os.environ.get('DB_NAME') != 'warehouse_qa' or os.environ.get('DB_HOST') != '127.0.0.1':
        raise SystemExit('Load only the isolated warehouse_qa database environment.')
    root = Path(__file__).resolve().parents[1]
    php = sys.argv[2] if len(sys.argv) == 3 else 'php'
    def local_query(sql, params):
        code = ('$p=json_decode(stream_get_contents(STDIN),true);require "app/bootstrap.php";'
                '$s=db()->prepare($p["sql"]);$s->execute($p["params"]);echo json_encode($s->fetchAll());')
        result = subprocess.run([php, '-r', code], cwd=root, text=True, capture_output=True,
                                input=json.dumps({'sql': sql, 'params': params}), check=True)
        return json.loads(result.stdout)
    def local_issue(username):
        return subprocess.run([php, 'bin/account.php', 'invite', username, '--base-url', sys.argv[1]],
                              cwd=root, text=True, capture_output=True, check=True).stdout
    exercise_accounts(sys.argv[1], os.environ.get('WAREHOUSE_TEST_PASSWORD', 'WarehouseDemo!26'),
                      local_issue, local_query)
