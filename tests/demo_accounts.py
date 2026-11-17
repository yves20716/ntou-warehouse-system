"""Exercise the login form and tenant-specific inventory on the seeded demo."""

from html.parser import HTMLParser
from http.cookiejar import CookieJar
from os import environ
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, build_opener


class CsrfField(HTMLParser):
    def __init__(self):
        super().__init__()
        self.value = None

    def handle_starttag(self, tag, attrs):
        fields = dict(attrs)
        if tag == "input" and fields.get("name") == "csrf":
            self.value = fields.get("value")


def check_account(base, username, role, visible=None, excluded=None):
    browser = build_opener(HTTPCookieProcessor(CookieJar()))
    token = CsrfField()
    with browser.open(base + "/", timeout=10) as login:
        token.feed(login.read().decode())
    if not token.value:
        raise AssertionError("登入頁缺少表單驗證欄位")
    form = urlencode({
        "action": "login", "csrf": token.value,
        "username": username, "password": "WarehouseDemo!26",
    }).encode()
    with browser.open(base + "/actions.php", form, timeout=10) as response:
        assert response.geturl() == f"{base}/{role}.php", (username, response.geturl())
        assert "登出" in response.read().decode(), username
    if visible:
        with browser.open(base + "/client.php?view=inventory", timeout=10) as response:
            inventory = response.read().decode()
        assert visible in inventory and excluded not in inventory, username
    print(f"{username} OK")


if __name__ == "__main__":
    url = environ.get("WAREHOUSE_URL", "http://127.0.0.1:8080").rstrip("/")
    check_account(url, "client", "client", "玻璃保鮮盒", "登山頭燈")
    check_account(url, "client2", "client", "登山頭燈", "玻璃保鮮盒")
    check_account(url, "employee", "employee")
    check_account(url, "hr", "hr")
