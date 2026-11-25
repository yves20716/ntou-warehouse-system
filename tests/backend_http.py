"""Regression checks that write only to the dedicated loopback QA instance."""

from concurrent.futures import ThreadPoolExecutor
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, ProxyHandler, Request, build_opener
import sys
import uuid


class Page(HTMLParser):
    def __init__(self, body, url, status=200):
        super().__init__()
        self.url, self.status, self.body = url, status, body
        self.forms, self.rows, self.notices = [], [], []
        self.current_form = self.current_row = self.current_cell = None
        self.select = self.notice = None
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            self.current_form = {"fields": {}, "options": {}, "action": attrs.get("action", "")}
            self.forms.append(self.current_form)
        if tag == "input" and self.current_form is not None and "name" in attrs:
            self.current_form["fields"][attrs["name"]] = attrs.get("value", "")
        if tag == "select" and self.current_form is not None:
            self.select = attrs.get("name")
            self.current_form["fields"][self.select] = ""
            self.current_form["options"][self.select] = []
        if tag == "option" and self.select is not None:
            self.current_form["options"][self.select].append(attrs)
            if "selected" in attrs:
                self.current_form["fields"][self.select] = attrs.get("value", "")
        if tag == "tr":
            self.current_row = []
        if tag == "td" and self.current_row is not None:
            self.current_cell = []
        if tag == "div" and "notice" in attrs.get("class", "").split():
            self.notice = [attrs.get("class", ""), []]

    def handle_data(self, text):
        if self.current_cell is not None:
            self.current_cell.append(text)
        if self.notice is not None:
            self.notice[1].append(text)

    def handle_endtag(self, tag):
        if tag == "form":
            self.current_form = None
        if tag == "select":
            self.select = None
        if tag == "td" and self.current_cell is not None:
            self.current_row.append(" ".join("".join(self.current_cell).split()))
            self.current_cell = None
        if tag == "tr" and self.current_row is not None:
            if self.current_row:
                self.rows.append(self.current_row)
            self.current_row = None
        if tag == "div" and self.notice is not None:
            self.notices.append((self.notice[0], "".join(self.notice[1])))
            self.notice = None

    def form(self, action, record_id=None):
        matches = [form for form in self.forms if form["fields"].get("action") == action
                   and (record_id is None or form["fields"].get("id") == str(record_id))]
        assert len(matches) == 1, (action, record_id, len(matches))
        return matches[0]

    def outcome(self, expected):
        assert self.status == 200 and len(self.notices) == 1, (self.status, self.notices)
        assert expected in self.notices[0][0].split(), self.notices
        assert "Fatal error" not in self.body and "Warning:" not in self.body
        return self

    def record(self, name):
        matches = [row for row in self.rows if name in row]
        assert len(matches) == 1, (name, matches)
        return matches[0]


class LocalRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        assert urlsplit(newurl).netloc == urlsplit(req.full_url).netloc
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Browser:
    def __init__(self, base, username, role):
        self.base, self.role = base, role
        cookies = CookieJar()
        self.opener = build_opener(ProxyHandler({}), LocalRedirect(), HTTPCookieProcessor(cookies))
        self.direct = build_opener(ProxyHandler({}), NoRedirect(), HTTPCookieProcessor(cookies))
        page = self.fetch("index.php")
        fields = page.form("login")["fields"] | {"username": username, "password": "WarehouseDemo!26"}
        page = self.fetch("actions.php", fields)
        assert urlsplit(page.url).path == "/" + role + ".php", (username, page.url)

    def fetch(self, path, fields=None, redirect=True):
        url = urljoin(self.base, path)
        assert urlsplit(url).netloc == urlsplit(self.base).netloc
        request = Request(url, data=None if fields is None else urlencode(fields).encode())
        try:
            response = (self.opener if redirect else self.direct).open(request, timeout=15)
        except HTTPError as error:
            response = error
        with response:
            return Page(response.read().decode(), response.url, response.status)

    def view(self, view, **params):
        return self.fetch(self.role + ".php?" + urlencode({"view": view, **params}))

    def submit(self, page, action, values=None, expected="success", record_id=None):
        fields = page.form(action, record_id)["fields"] | (values or {})
        return self.fetch("actions.php", fields).outcome(expected)


def run(base):
    employee = Browser(base, "employee", "employee")
    client = Browser(base, "client", "client")
    other = Browser(base, "client2", "client")
    hr = Browser(base, "hr", "hr")
    tag = uuid.uuid4().hex[:8]
    name = "後端驗證" + tag

    assert employee.fetch("actions.php").status == 405
    fields = employee.view("goods").form("goods_save")["fields"]
    for csrf in ["", "wrong"]:
        assert employee.fetch("actions.php", fields | {"csrf": csrf}).status == 403
    assert employee.fetch("actions.php", fields | {"action": "unknown"}).status == 400
    for browser, action in [(client, "stock_in"), (other, "goods_delete"), (hr, "stock_out"), (employee, "employee_save")]:
        page = browser.view("overview")
        csrf = page.form("logout")["fields"]["csrf"]
        assert browser.fetch("actions.php", {"csrf": csrf, "action": action}).status == 403
    print("PASS method, CSRF and cross-role write rejection", flush=True)
    for browser, view, action in [(employee, "goods", "goods_save"), (hr, "staff", "employee_save")]:
        missing = browser.view(view, edit="2147483647")
        assert all(form["fields"].get("action") != action for form in missing.forms)

    page = employee.view("goods")
    created = employee.submit(page, "goods_save", {"name": name, "client_id": "1", "unit": "箱"})
    goods_id = created.record(name)[0].lstrip("#")
    page = employee.view("goods", edit=goods_id)
    bad = employee.submit(page, "goods_save", {"name": name + "修改", "unit": ""}, expected="error")
    assert "edit=" + goods_id in bad.url
    assert bad.form("goods_save")["fields"]["name"] == name + "修改"
    fields = employee.view("goods").form("goods_save")["fields"] | {"name[]": "bad", "client_id": "1", "unit": "箱"}
    fields.pop("name", None)
    employee.fetch("actions.php", fields).outcome("error")
    print("PASS malformed field rejected and failed edit retained", flush=True)

    page = employee.view("inventory")
    token = page.form("stock_in")["fields"]
    payload = token | {"goods_id": goods_id, "section_id": "1", "quantity": "10"}
    created = employee.fetch("actions.php", payload).outcome("success")
    batch = created.record(name)[0].lstrip("#")
    employee.fetch("actions.php", payload).outcome("error")
    assert len([r for r in employee.view("inventory").rows if name in r]) == 1
    out = employee.view("inventory").form("stock_out")["fields"] | {"stock_id": batch, "quantity": "3"}
    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _: employee.fetch("actions.php", out, redirect=False), range(2)))
    assert all(page.status == 303 for page in results)
    row = employee.view("inventory").record(name)
    assert "7 箱" in row, row
    employee.submit(employee.view("inventory"), "stock_out", {"stock_id": batch, "quantity": "8"}, expected="error")
    employee.submit(employee.view("inventory"), "stock_in", {"goods_id": goods_id, "section_id": "3", "quantity": "1"}, expected="error")
    protected = employee.submit(employee.view("goods"), "goods_delete", record_id=goods_id, expected="error")
    assert "已有入庫紀錄" in protected.notices[0][1]
    employee.submit(employee.view("goods", edit=goods_id), "goods_save", {"unit": "台"}, expected="error")
    print("PASS repeated/concurrent form submits, overship, owner mismatch and historical goods protection", flush=True)

    for view in ["goods", "inventory", "movements"]:
        assert name in client.view(view, q=name).body
        assert name not in " ".join(" ".join(row) for row in other.view(view, q=name).rows)
        assert name not in " ".join(" ".join(row) for row in other.view(view).rows)
    assert not employee.view("goods", q="%_").rows
    print("PASS tenant isolation and literal wildcard search", flush=True)

    staff = "後端人資" + tag
    created = hr.submit(hr.view("staff"), "employee_save", {"name": staff, "job_title": "測試員", "hourly_rate": "210", "insurer": "0"})
    staff_id = created.record(staff)[0].lstrip("#")
    assert hr.view("staff", edit=staff_id).form("employee_save")["fields"]["insurer"] == "0"
    bad = hr.submit(hr.view("staff", edit=staff_id), "employee_save", {"hourly_rate": "-1", "name": staff + "改"}, expected="error")
    assert "edit=" + staff_id in bad.url and bad.form("employee_save")["fields"]["name"] == staff + "改"
    for changes in [{"birth_date": "2099-01-01"}, {"birth_date": "2026-02-30"}, {"manager_id": staff_id}, {"hourly_rate": "1000000"}]:
        hr.submit(hr.view("staff", edit=staff_id), "employee_save", changes, expected="error")
    created = hr.submit(hr.view("staff"), "employee_save", {"name": staff + "主管", "job_title": "測試主管", "hourly_rate": "210", "manager_id": staff_id})
    manager_id = created.record(staff + "主管")[0].lstrip("#")
    hr.submit(hr.view("staff", edit=staff_id), "employee_save", {"manager_id": manager_id}, expected="error")
    protected = hr.submit(hr.view("staff"), "employee_delete", record_id=staff_id, expected="error")
    assert "部屬" in protected.notices[0][1]
    fields = hr.view("staff", edit=staff_id).form("employee_save")["fields"] | {"insurer[]": "bad"}
    fields.pop("insurer", None)
    hr.fetch("actions.php", fields).outcome("error")
    print("PASS optional text, dates, numeric bounds, supervisor cycles and foreign keys", flush=True)
    print("PASS backend HTTP regressions; QA tag", tag, flush=True)


if __name__ == "__main__":
    if len(sys.argv) != 2 or sys.argv[1].rstrip("/") not in {"http://127.0.0.1:18767", "http://localhost:18767"}:
        sys.exit("This test only writes to the dedicated loopback QA instance on port 18767.")
    run(sys.argv[1].rstrip("/") + "/")
