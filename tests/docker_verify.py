"""Run the documented installation on a disposable GitHub-hosted Docker runner."""
from pathlib import Path
from backend_http import Page
import hashlib
import json
import os
import secrets
import ssl
import subprocess
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, HTTPSHandler, ProxyHandler, build_opener

ROOT = Path(__file__).resolve().parents[1]
TABLES = ['clients', 'warehouses', 'sections', 'goods', 'employees', 'inventory',
          'movements', 'users', 'stock_requests', 'account_tokens', 'admin_audit']


def run(args, data=None, capture=False):
    result = subprocess.run(args, input=data, text=True, cwd=ROOT,
                            stdout=subprocess.PIPE if capture else None, check=True)
    return result.stdout.strip() if capture else ''


def query(compose, sql, params=None):
    code = ('$s=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);'
            'require "app/bootstrap.php";echo json_encode(rows($s["sql"],$s["params"]));')
    result = run(compose + ['exec', '-T', 'web', 'php', '-r', code],
                 json.dumps({'sql': sql, 'params': params or []}), capture=True)
    return json.loads(result)


def scalar(compose, sql):
    return int(next(iter(query(compose, sql)[0].values())))


def check_schema(compose):
    assert scalar(compose, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()') == 11
    assert scalar(compose, "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_type='FOREIGN KEY'") == 18
    print('PASS live MySQL schema: 11 tables, 18 foreign keys', flush=True)


def snapshot(compose):
    # Hash all rows, including account hashes, without writing their contents into CI logs.
    data = {t: query(compose, f'SELECT * FROM {t} ORDER BY id') for t in TABLES}
    return hashlib.sha256(json.dumps(data, sort_keys=True).encode()).hexdigest()


def ledger(compose, report):
    rows = query(compose, '''SELECT i.quantity, COUNT(m.id) AS events,
        SUM(IF(m.type='in',m.quantity,-m.quantity)) AS balance
        FROM inventory i JOIN movements m ON m.stock_id=i.id
        WHERE i.id=? GROUP BY i.id, i.quantity''', [report['stock_id']])
    assert len(rows) == 1 and [int(rows[0][k]) for k in ('quantity', 'events', 'balance')] == [7, 2, 7], rows
    print('PASS database balance = 7, movement count = 2, ledger = 10 - 3', flush=True)


def smoke(compose, mode, base, ca=None, verify=False):
    output = ROOT / '.local' / f'docker-{mode}.json'
    args = ['python3', 'tests/docker_smoke.py', '--mode', mode, '--base', base, '--output', str(output)]
    if ca:
        args += ['--ca-file', str(ca)]
    if verify:
        args.append('--verify-only')
    run(args)
    report = json.loads(output.read_text())
    ledger(compose, report)
    return report


def wait_http(base, ca=None):
    context = ssl.create_default_context(cafile=str(ca)) if ca else None
    opener = build_opener(ProxyHandler({}), HTTPSHandler(context=context))
    for attempt in range(60):
        try:
            with opener.open(base + '/', timeout=3) as response:
                assert response.status == 200
                login = Page(response.read().decode(), base + '/').form('login')
                assert login['action'] == 'actions.php'
                assert {'username', 'password', 'csrf'} <= login['fields'].keys()
                assert login['fields']['csrf'], 'Login form is missing its CSRF token.'
                return opener
        except (URLError, TimeoutError, ConnectionError):
            time.sleep(1)
    raise RuntimeError('Web server did not become ready within 60 attempts.')


def restart_and_verify(compose, mode, base, ca=None):
    before = snapshot(compose)
    run(compose + ['stop'])
    run(compose + ['up', '-d', '--wait', '--wait-timeout', '240'])
    wait_http(base, ca)
    assert snapshot(compose) == before, 'Rows changed after stop/up; initialization must run only once.'
    smoke(compose, mode, base, ca, verify=True)
    print('PASS every table survived container stop/up unchanged', flush=True)



def accounts(compose, base, ca=None):
    from account_http import exercise_accounts
    password = os.environ.get('WAREHOUSE_TEST_PASSWORD', 'WarehouseDemo!26')
    def issue(username):
        return run(compose + ['exec', '-T', 'web', 'php', 'bin/account.php', 'invite', username, '--base-url', base], capture=True)
    exercise_accounts(base, password, issue, lambda sql, params: query(compose, sql, params), str(ca) if ca else None)


def seeded():
    compose = ['docker', 'compose']
    # Same build/start as README; --wait only waits for readiness before automated requests.
    run(compose + ['up', '--build', '-d', '--wait', '--wait-timeout', '240'])
    wait_http('http://127.0.0.1:8080')
    check_schema(compose)
    expected = {'users': 4, 'clients': 2, 'goods': 5, 'inventory': 4, 'movements': 6, 'stock_requests': 0}
    for table, count in expected.items():
        assert scalar(compose, f'SELECT COUNT(*) FROM {table}') == count, table
    print('PASS fresh seed counts and four demonstration accounts', flush=True)
    run(compose + ['exec', '-T', 'web', 'sh', '-c', 'find app public bin -name "*.php" -print0 | xargs -0 -n1 php -l'])
    run(['python3', 'tests/demo_accounts.py'])
    smoke(compose, 'seeded', 'http://127.0.0.1:8080')
    accounts(compose, 'http://127.0.0.1:8080')
    restart_and_verify(compose, 'seeded', 'http://127.0.0.1:8080')
    run(compose + ['stop'])


def root_sql(compose, sql, database='warehouse'):
    assert database in ('warehouse', 'warehouse_upgrade_test')
    return run(compose + ['exec', '-T', 'db', 'sh', '-c',
                         'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -N -B -uroot ' + database], sql, capture=True)


def production():
    compose = ['./bin/production.sh']
    env_file = ROOT / '.env.production'
    assert not env_file.exists(), 'Refusing to overwrite an existing production environment.'
    db_password, root_password, app_password = [secrets.token_hex(24) for _ in range(3)]
    for value in (db_password, root_password, app_password):
        print('::add-mask::' + value, flush=True)
    env_file.write_text(f'WAREHOUSE_DOMAIN=localhost\nWAREHOUSE_DB_PASSWORD={db_password}\nWAREHOUSE_ROOT_PASSWORD={root_password}\n')
    env_file.chmod(0o600)
    os.environ['WAREHOUSE_TEST_PASSWORD'] = app_password
    run(compose + ['config', '--quiet'])
    run(compose + ['up', '--build', '-d', '--wait', '--wait-timeout', '240', 'db', 'web'])
    check_schema(compose)
    assert all(scalar(compose, f'SELECT COUNT(*) FROM {t}') == 0 for t in TABLES)
    print('PASS production schema starts empty with no public seed accounts', flush=True)
    root_sql(compose, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'warehouse'@'%';\nGRANT SELECT, INSERT, UPDATE, DELETE ON warehouse.* TO 'warehouse'@'%';")
    run(compose + ['exec', '-T', 'web', 'php', '-r', 'require "app/bootstrap.php";try{db()->exec("CREATE TABLE forbidden_test(id INT)");exit(1);}catch(PDOException $e){exit(($e->errorInfo[1]??0)===1142?0:2);}'])
    setup = (ROOT / 'deploy/business-setup.sql.example').read_text()
    # Use the documented provisioning template and only fictional CI data.
    setup = setup.replace('請填', '容器測試')
    root_sql(compose, setup)
    root_sql(compose, "INSERT INTO clients (name) VALUES ('容器測試第二客戶');\nINSERT INTO sections (warehouse_id,client_id,name,capacity) VALUES (1,2,'B-02',100);")
    for username, role, entity in [('client','client','1'),('client2','client','2'),('employee','employee','2'),('hr','admin','1')]:
        run(compose + ['exec', '-T', 'web', 'php', 'bin/account.php', 'create', username, role, entity], app_password + '\n')
    assert scalar(compose, 'SELECT COUNT(*) FROM users') == 4
    print('PASS documented initial data, restricted database grants and CLI accounts', flush=True)
    run(compose + ['up', '-d', '--wait', 'proxy'])
    ca = ROOT / '.local/docker-test-ca.crt'
    for attempt in range(60):
        result = subprocess.run(compose + ['exec','-T','proxy','test','-f','/data/caddy/pki/authorities/local/root.crt'], cwd=ROOT)
        if result.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Caddy local CA was not created.')
    run(compose + ['cp', 'proxy:/data/caddy/pki/authorities/local/root.crt', str(ca)])
    opener = wait_http('https://localhost', ca)
    for path in ('/app/bootstrap.php','/.env.production','/database/01-schema.sql'):
        try:
            opener.open('https://localhost' + path, timeout=5)
            raise AssertionError('Private path was served: ' + path)
        except HTTPError as error:
            assert error.code == 404, (path, error.code)
    class NoRedirect(HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    try:
        build_opener(ProxyHandler({}), NoRedirect()).open('http://localhost/', timeout=5)
        raise AssertionError('HTTP did not redirect to HTTPS.')
    except HTTPError as error:
        assert error.code in (301, 308) and error.headers['Location'].startswith('https://localhost/'), error
    print('PASS HTTPS certificate validation, HTTP redirect and private-path protection', flush=True)
    smoke(compose, 'production', 'https://localhost', ca)
    accounts(compose, 'https://localhost', ca)
    restart_and_verify(compose, 'production', 'https://localhost', ca)
    before = snapshot(compose)
    backup = run(compose + ['exec','-T','db','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF warehouse'], capture=True)
    backup_path = ROOT / '.local/docker-production.sql'
    backup_path.write_text(backup + '\n')
    backup_path.chmod(0o600)
    root_sql(compose, "INSERT INTO goods (client_id,name,unit) VALUES (1,'備份後的測試資料','箱');")
    assert snapshot(compose) != before
    run(compose + ['stop', 'proxy', 'web'])
    root_sql(compose, backup)
    run(compose + ['up','-d','--wait','--wait-timeout','240','web','proxy'])
    wait_http('https://localhost', ca)
    assert snapshot(compose) == before, 'Backup restore did not reproduce every table.'
    smoke(compose, 'production', 'https://localhost', ca, verify=True)
    print('PASS documented mysqldump backup and restore round-trip', flush=True)
    # Exercise the old eight-table installation, then apply the published migration.
    root_sql(compose, 'CREATE DATABASE warehouse_upgrade_test CHARACTER SET utf8mb4;')
    legacy = (ROOT / 'tests/fixtures/pre-account-schema.sql').read_text().split('CREATE TABLE stock_requests')[0]
    root_sql(compose, legacy + '\n' + (ROOT / 'tests/fixtures/pre-account-seed.sql').read_text(), 'warehouse_upgrade_test')
    migration = (ROOT / 'database/migrations/20261126-stock-requests.sql').read_text()
    root_sql(compose, migration, 'warehouse_upgrade_test')
    root_sql(compose, migration, 'warehouse_upgrade_test')
    result = root_sql(compose, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE(); SELECT COUNT(*) FROM movements; SELECT SUM(quantity) FROM inventory;", 'warehouse_upgrade_test')
    assert result.splitlines() == ['9','6','158'], result
    print('PASS migration from eight tables, repeated migration and original seed preservation', flush=True)
    account_migration = (ROOT / 'database/migrations/20261126-account-management.sql').read_text()
    before_business = root_sql(compose, 'SELECT * FROM movements ORDER BY id; SELECT * FROM inventory ORDER BY id;', 'warehouse_upgrade_test')
    root_sql(compose, account_migration, 'warehouse_upgrade_test')
    root_sql(compose, account_migration, 'warehouse_upgrade_test')
    after_business = root_sql(compose, 'SELECT * FROM movements ORDER BY id; SELECT * FROM inventory ORDER BY id;', 'warehouse_upgrade_test')
    assert before_business == after_business, 'Account migration changed stock or movement history.'
    upgraded = root_sql(compose, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE(); SELECT role FROM users WHERE username='hr'; SELECT COUNT(*) FROM employees WHERE monthly_salary IS NOT NULL;", 'warehouse_upgrade_test')
    assert upgraded.splitlines() == ['11', 'admin', '0'], upgraded
    print('PASS account migration preserves existing stock, credentials and unknown monthly salaries', flush=True)

    run(compose + ['stop'])


if __name__ == '__main__':
    if os.environ.get('GITHUB_ACTIONS') != 'true' or not os.environ.get('RUNNER_TEMP'):
        raise SystemExit('This orchestration runs only on disposable GitHub Actions runners; use docker_smoke.py on your own isolated test copy.')
    (ROOT / '.local').mkdir(exist_ok=True)
    mode = sys.argv[1] if len(sys.argv) > 1 else ''
    if mode not in ('seeded', 'production'):
        raise SystemExit('Usage: python3 tests/docker_verify.py seeded|production')
    globals()[mode]()
    summary = os.environ.get('GITHUB_STEP_SUMMARY')
    if summary:
        with open(summary, 'a') as output:
            output.write(f'### {mode}\n\nPassed real Docker installation, HTTP workflows, ledger and persistence checks.\n')
