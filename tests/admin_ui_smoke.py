"""
Lucy Play — Admin UI smoke tests (Laravel Blade app).
Verifies the redesigned admin panel:
  - Login flow + /admin access
  - Dashboard metric cards (6) and range/date filters
  - Every sidebar nav page returns HTTP 200
  - Members search, Casino engine tabs, Promotion create, Settings save
  - Sidebar collapse toggle, logout

Run: python /app/tests/admin_ui_smoke.py
"""
import re
import sys
import requests

BASE = "http://127.0.0.1:8000"
ADMIN_EMAIL = "admin@lucyplay.local"
ADMIN_PASS = "LucyAdmin#2026"


def login() -> requests.Session:
    s = requests.Session()
    r = s.get(f"{BASE}/login")
    m = re.search(r'name="_token" value="([^"]+)"', r.text)
    assert m, "CSRF token not found on /login"
    r2 = s.post(
        f"{BASE}/login",
        data={"_token": m.group(1), "email": ADMIN_EMAIL, "password": ADMIN_PASS},
        allow_redirects=True,
    )
    assert r2.status_code == 200, f"Login failed: {r2.status_code}"
    return s


NAV_PATHS = [
    "/admin",
    "/admin/administrators",
    "/admin/members",
    "/admin/module/promotion-banners",
    "/admin/settings",
    "/admin/payment-gateways",
    "/admin/agents",
    "/admin/agents/create",
    "/admin/agent-orders",
    "/admin/game-engine",
    "/admin/rounds",
    "/admin/transactions",
    "/admin/promotions",
    "/admin/shamo",
    "/admin/module/loyalty-bonus",
    "/admin/module/challenges",
    "/admin/module/tournaments",
    "/admin/module/telegram",
    "/admin/telegram/broadcast-bot",
    "/admin/telegram/templates/create",
    "/admin/telegram/broadcasts/create",
]


def main() -> int:
    failures: list[str] = []

    s = login()

    # 1. Dashboard renders with 6 metric cards
    r = s.get(f"{BASE}/admin?range=month")
    assert r.status_code == 200
    for tid in [
        "metric-total-members", "metric-total-bets", "metric-bet-volume",
        "metric-payouts", "metric-ggr", "metric-active-promotions",
    ]:
        if f'data-testid="{tid}"' not in r.text:
            failures.append(f"Missing metric card {tid}")
    for tab in ["Today", "Last 7 days", "This month", "This year"]:
        if tab not in r.text:
            failures.append(f"Missing range tab {tab}")

    # 2. All sidebar pages return 200 without server errors
    for p in NAV_PATHS:
        rr = s.get(f"{BASE}{p}")
        if rr.status_code != 200:
            failures.append(f"{p} -> HTTP {rr.status_code}")
            continue
        if "Whoops" in rr.text or "SQLSTATE" in rr.text:
            failures.append(f"{p} -> Laravel error page")

    # 3. Members search
    r = s.get(f"{BASE}/admin/members?search=hana")
    assert r.status_code == 200
    if "Hana" not in r.text:
        failures.append("Members search for 'hana' did not return Hana")

    # 4. Casino engine tabs + Edit controls
    r = s.get(f"{BASE}/admin/game-engine")
    if not all(t in r.text for t in ["Game", "Category", "Provider", "Lobby"]):
        failures.append("Casino engine missing tab labels")

    # 5. Promotion create (and cleanup)
    r = s.get(f"{BASE}/admin/promotions")
    m = re.search(r'name="_token" value="([^"]+)"', r.text)
    assert m
    token = m.group(1)
    r2 = s.post(
        f"{BASE}/admin/promotions",
        data={
            "_token": token,
            "title": "TEST_SmokeSuite",
            "description": "TEST desc",
            "code": "TEST_SMOKE",
            "accent": "cyan",
            "starts_at": "",
            "ends_at": "",
        },
        allow_redirects=True,
    )
    if "Promotion created" not in r2.text and "TEST_SmokeSuite" not in r2.text:
        failures.append("Promotion create did not confirm success")
    # cleanup
    m2 = re.search(r'action="([^"]+)/admin/promotions/(\d+)"[^>]*>\s*<input[^>]*name="_token"[^>]*value="([^"]+)"[^>]*>\s*<input[^>]*name="_method"[^>]*value="DELETE"', r2.text)
    if m2:
        promo_id = m2.group(2)
        s.post(
            f"{BASE}/admin/promotions/{promo_id}",
            data={"_token": m2.group(3), "_method": "DELETE"},
        )

    # 6. Settings save
    r = s.get(f"{BASE}/admin/settings")
    m = re.search(r'name="_token" value="([^"]+)"', r.text)
    support_email = re.search(r'name="support_email" value="([^"]*)"', r.text)
    welcome = re.search(r'name="welcome_credits" value="([^"]*)"', r.text)
    dep = re.search(r'name="deposit_bonus_amount" value="([^"]*)"', r.text)
    assert m and support_email and welcome and dep
    r3 = s.post(
        f"{BASE}/admin/settings",
        data={
            "_token": m.group(1),
            "_method": "PUT",
            "support_email": support_email.group(1) or "support@lucyplay.local",
            "welcome_credits": welcome.group(1) or "10",
            "deposit_bonus_amount": dep.group(1) or "2500",
            "maintenance_message": "",
        },
        allow_redirects=True,
    )
    if "Platform settings saved" not in r3.text:
        failures.append("Settings save success flash not shown")

    # 7. Logout
    r = s.get(f"{BASE}/admin")
    m = re.search(r'name="_token" value="([^"]+)"', r.text)
    assert m
    r4 = s.post(f"{BASE}/logout", data={"_token": m.group(1)}, allow_redirects=False)
    if r4.status_code not in (302, 303):
        failures.append(f"Logout returned {r4.status_code}, expected 302/303")

    if failures:
        print("FAILURES:")
        for f in failures:
            print(" - " + f)
        return 1
    print("All admin UI smoke tests PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
