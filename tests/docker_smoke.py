"""Exercise a disposable Docker installation through its real HTTP forms.

Seeded: python3 tests/docker_smoke.py --base http://127.0.0.1:8080 --output result.json
HTTPS:  WAREHOUSE_TEST_PASSWORD=... python3 tests/docker_smoke.py \
        --mode production --base https://localhost --ca-file caddy-root.crt --output result.json
Persistence: repeat the same options with --verify-report result.json after a restart.
"""

import argparse
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import ssl
from urllib.parse import urlsplit
from urllib.request import HTTPCookieProcessor, HTTPSHandler, ProxyHandler, build_opener
import uuid

from backend_http import Browser, LocalRedirect, NoRedirect


class SmokeBrowser(Browser):
    def __init__(self, base, username, role, password, context):
        self.base, self.role = base, role
        cookies = CookieJar()
        self.opener = build_opener(ProxyHandler({}), LocalRedirect(),
                                  HTTPCookieProcessor(cookies), HTTPSHandler(context=context))
        self.direct = build_opener(ProxyHandler({}), NoRedirect(),
                                  HTTPCookieProcessor(cookies), HTTPSHandler(context=context))
        page = self.fetch('index.php')
        fields = page.form('login')['fields'] | {'username': username, 'password': password}
        logged_in = self.fetch('actions.php', fields)
        assert urlsplit(logged_in.url).path == '/' + role + '.php', 'Login failed for ' + username
        if urlsplit(base).scheme == 'https':
            assert cookies and all(cookie.secure for cookie in cookies), 'HTTPS session cookie is not Secure.'


def matching_rows(page, name):
    return [row for row in page.rows if any(name in cell for cell in row)]


def pending_id(client, name):
    forms = [form for form in client.view('requests', q=name).forms
             if form['fields'].get('action') == 'client_request_cancel']
    assert len(forms) == 1, 'Expected exactly one pending request for the fixture.'
    return forms[0]['fields']['id']


def request_row(client, name, request_id):
    rows = matching_rows(client.view('requests', q=name), name)
    matches = [row for row in rows if re.search(r'#' + re.escape(str(request_id)) + r'\b', row[0])]
    assert len(matches) == 1, 'Request is missing or appears more than once.'
    return ' '.join(matches[0])


def check_stock(client, name, quantity):
    page = client.view('inventory', q=name)
    row = page.record(name)
    assert row[2].startswith(str(quantity) + ' 箱'), ('Unexpected balance', row[2])
    match = re.search(r'批次\s+#(\d+)', row[0])
    assert match, 'No batch identifier in the inventory row.'
    return match.group(1)


def verify_result(browsers, report):
    client, other, employee, _ = browsers
    name = report['goods_name']
    assert check_stock(client, name, 7) == str(report['stock_id'])
    assert check_stock(employee, name, 7) == str(report['stock_id'])
    movement_rows = matching_rows(client.view('movements', q=name), name)
    assert len(movement_rows) == 2, 'Expected exactly one receipt and one dispatch.'
    assert any(row[1] == '入庫' and row[-1] == '10 箱' for row in movement_rows)
    assert any(row[1] == '出庫' and row[-1] == '3 箱' for row in movement_rows)
    for key in ['in_request_id', 'out_request_id']:
        assert '已完成' in request_row(client, name, report[key])
    assert '已取消' in request_row(client, name, report['cancel_request_id'])
    assert '未受理' in request_row(client, name, report['reject_request_id'])
    for view in ['goods', 'inventory', 'movements', 'requests']:
        assert not matching_rows(other.view(view, q=name), name), 'Cross-client data leak in ' + view


def exercise(browsers, mode):
    client, other, employee, hr = browsers
    tag = uuid.uuid4().hex[:12]
    name = 'Docker收納盒-' + tag
    client.submit(client.view('goods'), 'client_goods_save', {'name': name, 'unit': '箱'})
    goods = client.view('goods', q=name)
    ids = re.findall(r'view=goods&amp;edit=(\d+)#client-goods-form', goods.body)
    assert len(ids) == 1, 'Fixture goods were not created exactly once.'
    goods_id = ids[0]
    assert not matching_rows(other.view('goods', q=name), name)
    # A production install has no seeded goods; create one for the second client so
    # its genuine inbound-request form exists when testing forged ownership.
    other_name = 'Docker隔離品項-' + tag
    other.submit(other.view('goods'), 'client_goods_save', {'name': other_name, 'unit': '箱'})
    assert not matching_rows(client.view('goods', q=other_name), other_name)
    print('PASS all four logins and client-owned goods creation', flush=True)

    # A new request is intentionally not a stock movement.
    payload = client.view('requests').form('client_request_in')['fields'] | {'goods_id': goods_id, 'quantity': '10'}
    client.fetch('actions.php', payload).outcome('success')
    client.fetch('actions.php', payload).outcome('error')
    receipt_id = pending_id(client, name)
    assert not matching_rows(client.view('inventory', q=name), name)
    assert not matching_rows(client.view('movements', q=name), name)
    other.submit(other.view('requests'), 'client_request_in', {'goods_id': goods_id, 'quantity': '1'}, expected='error')
    complete = employee.view('requests', q=name).form('request_complete', receipt_id)
    sections = [option['value'] for option in complete['options']['section_id'] if option.get('value')]
    assert sections, 'No section was provisioned for the test client.'
    payload = complete['fields'] | {'section_id': sections[0]}
    employee.fetch('actions.php', payload).outcome('success')
    employee.fetch('actions.php', payload).outcome('error')
    stock_id = check_stock(client, name, 10)
    print('PASS pending receipt leaves stock unchanged; warehouse completion creates 10 boxes once', flush=True)

    client.submit(client.view('requests'), 'client_request_out', {'stock_id': stock_id, 'quantity': '3'})
    dispatch_id = pending_id(client, name)
    assert check_stock(client, name, 10) == stock_id
    complete = employee.view('requests', q=name).form('request_complete', dispatch_id)['fields']
    employee.fetch('actions.php', complete).outcome('success')
    employee.fetch('actions.php', complete).outcome('error')
    assert check_stock(client, name, 7) == stock_id
    client.submit(client.view('requests'), 'client_request_out', {'stock_id': stock_id, 'quantity': '8'}, expected='error')
    print('PASS pending dispatch does not reserve stock; completion leaves 7 boxes and rejects overship', flush=True)

    client.submit(client.view('requests'), 'client_request_in', {'goods_id': goods_id, 'quantity': '2'})
    cancel_id = pending_id(client, name)
    cancel = client.view('requests', q=name).form('client_request_cancel', cancel_id)['fields']
    client.fetch('actions.php', cancel).outcome('success')
    client.fetch('actions.php', cancel).outcome('error')
    assert '已取消' in request_row(client, name, cancel_id)

    client.submit(client.view('requests'), 'client_request_out', {'stock_id': stock_id, 'quantity': '1'})
    reject_id = pending_id(client, name)
    note = 'Docker測試：取消這次取貨安排。'
    employee.submit(employee.view('requests', q=name), 'request_reject',
                    {'response_note': note}, record_id=reject_id)
    assert note in client.view('requests', q=name).body
    assert '未受理' in request_row(client, name, reject_id)
    print('PASS customer cancellation and warehouse rejection keep the balance unchanged', flush=True)

    # All mutations are submitted through the actual role-specific forms.
    for browser, action in [(client, 'request_complete'), (hr, 'stock_out'), (employee, 'employee_save')]:
        csrf = browser.view('overview').form('logout')['fields']['csrf']
        assert browser.fetch('actions.php', {'csrf': csrf, 'action': action}).status == 403

    staff = 'Docker臨時員工-' + tag
    hr.submit(hr.view('staff'), 'employee_save', {'name': staff, 'job_title': '測試員', 'hourly_rate': '200'})
    staff_id = hr.view('staff', q=staff).record(staff)[0].lstrip('#')
    hr.submit(hr.view('staff', edit=staff_id), 'employee_save', {'hourly_rate': '210', 'job_title': '測試理貨員'})
    row = hr.view('staff', q=staff).record(staff)
    assert '210.00 元' in row and '測試理貨員' in row
    hr.submit(hr.view('staff', q=staff), 'employee_delete', record_id=staff_id)
    assert not matching_rows(hr.view('staff', q=staff), staff)
    print('PASS role write boundaries and HR create/update/delete', flush=True)

    result = {
        'mode': mode, 'tag': tag, 'goods_name': name, 'goods_id': int(goods_id),
        'other_goods_name': other_name,
        'stock_id': int(stock_id), 'section_id': int(sections[0]), 'quantity': 7,
        'in_request_id': int(receipt_id), 'out_request_id': int(dispatch_id),
        'cancel_request_id': int(cancel_id), 'reject_request_id': int(reject_id),
        'movement_count': 2, 'receipt_quantity': 10, 'dispatch_quantity': 3,
        'hr_fixture_deleted': True,
    }
    verify_result(browsers, result)
    print('PASS receipt/dispatch history, tenant isolation and final ledger shown in UI', flush=True)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True, help='Disposable Docker site on loopback only.')
    parser.add_argument('--mode', choices=['seeded', 'production'], default='seeded')
    parser.add_argument('--username-prefix', default='', help='Prefix of client, client2, employee, hr accounts.')
    parser.add_argument('--ca-file', help='CA certificate for the local HTTPS reverse proxy.')
    parser.add_argument('--output', type=Path, help='Write fixture identifiers and expected balances as JSON.')
    parser.add_argument('--verify-report', type=Path, help='Read-only persistence check of an earlier output file.')
    parser.add_argument('--verify-only', action='store_true', help='Read the fixture from --output and check persistence without business writes.')
    args = parser.parse_args()
    if args.verify_only and not args.output:
        parser.error('--verify-only requires --output pointing to an existing fixture JSON.')
    parsed = urlsplit(args.base)
    if parsed.scheme not in ['http', 'https'] or parsed.hostname not in ['127.0.0.1', 'localhost', '::1'] \
            or parsed.username or parsed.password or parsed.path not in ['', '/'] or parsed.query or parsed.fragment:
        parser.error('--base must be a loopback HTTP(S) origin with no credentials or path.')
    if args.mode == 'production' and parsed.scheme != 'https':
        parser.error('Production verification requires HTTPS.')
    password = os.environ.get('WAREHOUSE_TEST_PASSWORD') if args.mode == 'production' else 'WarehouseDemo!26'
    if not password:
        parser.error('Production mode requires WAREHOUSE_TEST_PASSWORD in the environment.')
    context = ssl.create_default_context(cafile=args.ca_file)
    base = args.base.rstrip('/') + '/'
    browsers = [SmokeBrowser(base, args.username_prefix + username, role, password, context)
                for username, role in [('client', 'client'), ('client2', 'client'),
                                       ('employee', 'employee'), ('hr', 'hr')]]
    report_path = args.output if args.verify_only else args.verify_report
    if report_path:
        result = json.loads(report_path.read_text())
        verify_result(browsers, result)
        print('PASS fixture balance, history, request outcomes and isolation survived restart', flush=True)
    else:
        result = exercise(browsers, args.mode)
    if args.output:
        args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print('SMOKE_RESULT=' + json.dumps(result, ensure_ascii=False, sort_keys=True), flush=True)


if __name__ == '__main__':
    main()
