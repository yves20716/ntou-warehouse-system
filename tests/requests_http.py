"""Request workflow HTTP regressions; only the dedicated local QA port is accepted."""
from backend_http import Browser
from urllib.parse import urlsplit
import sys
import re
import uuid


def run(base):
    client = Browser(base, 'client', 'client')
    other = Browser(base, 'client2', 'client')
    employee = Browser(base, 'employee', 'employee')
    hr = Browser(base, 'hr', 'admin')
    tag = uuid.uuid4().hex[:8]
    name = '申請HTTP' + tag
    goods = client.view('goods')
    saved = client.submit(goods, 'client_goods_save', {'name': name, 'unit': '箱', 'client_id': '2'})
    assert name in saved.body and not any(name in cell for row in other.view('goods', q=name).rows for cell in row)
    assert name in client.view('goods', q=name).body
    own = client.view('goods', q=name)
    ids = re.findall(r'view=goods&amp;edit=(\d+)#client-goods-form', own.body)
    assert len(ids) == 1, ids
    goods_id = ids[0]
    for browser, action in [(employee, 'client_request_in'), (hr, 'request_complete'), (client, 'request_complete'), (client, 'goods_save')]:
        csrf = browser.view('overview').form('logout')['fields']['csrf']
        assert browser.fetch('actions.php', {'csrf': csrf, 'action': action}).status == 403
    form = client.view('requests').form('client_request_in')['fields']
    payload = form | {'goods_id': goods_id, 'quantity': '4'}
    assert client.fetch('actions.php', payload | {'csrf': 'bad'}).status == 403
    assert client.fetch('actions.php', payload | {'mutation_token': 'bad'}).outcome('error')
    created = client.fetch('actions.php', payload).outcome('success')
    client.fetch('actions.php', payload).outcome('error')
    assert not any(name in cell for row in client.view('inventory', q=name).rows for cell in row)
    assert not any(name in cell for row in other.view('requests', q=name).rows for cell in row)
    other.submit(other.view('requests'), 'client_request_in', {'goods_id': goods_id, 'quantity': '1'}, expected='error')
    print('PASS request role/CSRF/token checks, isolated creation and no stock mutation while pending', flush=True)

    # Locate the created request through its cancellation form, avoiding assumptions about presentation.
    cancel_forms = [f for f in client.view('requests', q=name).forms if f['fields'].get('action') == 'client_request_cancel']
    assert len(cancel_forms) == 1, 'Request submission was duplicated.'
    request_id = cancel_forms[0]['fields']['id']
    other_fields = other.view('requests').form('client_request_in')['fields']
    # A token is action-bound, even if the attacker changes both the action and object identifier.
    other.fetch('actions.php', other_fields | {'action': 'client_request_cancel', 'id': request_id}).outcome('error')
    processed = employee.submit(employee.view('requests', q=name), 'request_complete',
                                {'id': request_id, 'section_id': '1'}, record_id=request_id)
    assert name in processed.body
    assert '4 箱' in client.view('inventory', q=name).body
    client.fetch('actions.php', cancel_forms[0]['fields']).outcome('error')
    print('PASS employee completion and stale customer cancellation rejection', flush=True)
    print('PASS request HTTP regressions; QA tag ' + tag, flush=True)


if __name__ == '__main__':
    base = sys.argv[1] if len(sys.argv) > 1 else ''
    parsed = urlsplit(base)
    if parsed.scheme != 'http' or parsed.hostname != '127.0.0.1' or parsed.port != 18767 or parsed.path not in ['', '/']:
        raise SystemExit('Use only http://127.0.0.1:18767 for the isolated QA instance.')
    run(base.rstrip('/') + '/')
