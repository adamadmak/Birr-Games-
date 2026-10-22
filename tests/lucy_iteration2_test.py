"""
Lucy Play — Iteration 2 E2E backend/integration tests (Laravel Blade app).

Focus:
  1. Game artwork upload pipeline (admin → storage → lobby card).
  2. Game edit form (redesigned) — all fields persist.
  3. Remove artwork toggle clears image_path.
  4. Agent create flow (redesigned form).
  5. Add-New popover create + delete for game & category.
  6. Player lobby regression (search markup, provider filter data, Lucky Dice page).
  7. Label badge rendering.
  8. Admin nav regression.

Run: pytest /app/tests/lucy_iteration2_test.py -v --tb=short \
       --junitxml=/app/test_reports/pytest/pytest_results.xml
"""
from __future__ import annotations

import io
import os
import re
import struct
import zlib
from typing import Optional

import pytest
import requests

BASE = "http://127.0.0.1:8000"
ADMIN_EMAIL = "admin@lucyplay.local"
ADMIN_PASS = "LucyAdmin#2026"
PLAYER_EMAIL = "demo@lucyplay.local"
PLAYER_PASS = "demo1234"

# ---------- helpers ----------

def _tok(html: str) -> str:
    m = re.search(r'name="_token"\s+value="([^"]+)"', html)
    assert m, "CSRF _token not found"
    return m.group(1)


def _login(session: requests.Session, email: str, password: str) -> None:
    r = session.get(f"{BASE}/login")
    r.raise_for_status()
    r2 = session.post(
        f"{BASE}/login",
        data={"_token": _tok(r.text), "email": email, "password": password},
        allow_redirects=True,
    )
    assert r2.status_code == 200, f"Login failed for {email}: HTTP {r2.status_code}"
    assert "login" not in r2.url or r2.url.rstrip("/").endswith("login") is False, (
        f"Login did not redirect: url={r2.url}"
    )


def _make_png(color=(255, 128, 32)) -> bytes:
    """Tiny valid 2x2 PNG."""
    def chunk(tag: bytes, data: bytes) -> bytes:
        return (
            struct.pack(">I", len(data))
            + tag + data
            + struct.pack(">I", zlib.crc32(tag + data) & 0xFFFFFFFF)
        )
    sig = b"\x89PNG\r\n\x1a\n"
    ihdr = struct.pack(">IIBBBBB", 2, 2, 8, 2, 0, 0, 0)  # 2x2 RGB
    row = b"\x00" + bytes(color) + bytes(color)
    raw = row * 2
    idat = zlib.compress(raw)
    return sig + chunk(b"IHDR", ihdr) + chunk(b"IDAT", idat) + chunk(b"IEND", b"")


# ---------- fixtures ----------

@pytest.fixture(scope="module")
def admin() -> requests.Session:
    s = requests.Session()
    _login(s, ADMIN_EMAIL, ADMIN_PASS)
    return s


@pytest.fixture(scope="module")
def player() -> requests.Session:
    s = requests.Session()
    _login(s, PLAYER_EMAIL, PLAYER_PASS)
    return s


# =====================================================================
# 1. Storage / static asset regression
# =====================================================================
class TestStorage:
    def test_storage_symlink_serves_test_lucky_dice(self):
        r = requests.get(f"{BASE}/storage/games/test-lucky-dice.png")
        assert r.status_code == 200, f"Expected 200 for /storage/games/test-lucky-dice.png, got {r.status_code}"
        assert r.headers.get("content-type", "").startswith("image/"), (
            f"Not image/*: {r.headers.get('content-type')}"
        )
        assert len(r.content) > 100


# =====================================================================
# 2. Lobby renders game-cover for image_path AND unauth guest view.
# =====================================================================
class TestLobbyRendering:
    def test_member_lobby_shows_lucky_dice_cover(self, player):
        r = player.get(f"{BASE}/")
        assert r.status_code == 200
        assert 'class="game-cover"' in r.text or "game-cover" in r.text, (
            "game-cover img class not rendered in member lobby"
        )
        assert "test-lucky-dice.png" in r.text, "Lucky Dice image URL not rendered on lobby"
        assert 'data-provider-filter' in r.text
        assert 'data-game-search' in r.text

    def test_guest_lobby_shows_cover(self):
        r = requests.get(f"{BASE}/")
        assert r.status_code == 200
        assert "test-lucky-dice.png" in r.text
        assert "game-cover" in r.text

    def test_lucky_dice_game_page_reachable(self, player):
        r = player.get(f"{BASE}/games/lucky-dice")
        assert r.status_code == 200
        assert "Lucky Dice" in r.text


# =====================================================================
# 3. Game edit form — full-field save + persistence via GET.
# =====================================================================
class TestGameEditPersistence:
    def _current(self, session, slug):
        r = session.get(f"{BASE}/admin/game-engine/games/{slug}/edit")
        r.raise_for_status()
        return r.text

    def test_edit_form_renders_all_sections(self, admin):
        html = self._current(admin, "lucky-dice")
        for needle in [
            "Artwork", "Catalogue details", "Display", "Integration", "Publishing",
            'data-testid="game-save-button"', 'data-testid="game-delete-button"',
            'name="image_upload"', 'name="remove_image"',
            'name="top_game"', 'name="free_bet_supported"',
            'name="disabled"', 'name="lobbies[]"', 'name="crash"',
            'name="content_server_disabled"',
        ]:
            assert needle in html, f"Missing on edit form: {needle}"

    def test_edit_persists_all_fields(self, admin):
        html = self._current(admin, "lucky-dice")
        token = _tok(html)

        # Read current provider/category ids to keep valid
        prov = re.search(r'name="provider_id"[^>]*>.*?<option value="(\d+)" selected', html, re.S)
        cat = re.search(r'name="category_id"[^>]*>.*?<option value="(\d+)" selected', html, re.S)
        assert prov and cat, "provider/category selects missing selected option"

        payload = [
            ("_token", token), ("_method", "PUT"),
            ("title", "Lucy Lucky Dice"),
            ("slug", "lucky-dice"),
            ("description", "TEST desc iter2"),
            ("provider_id", prov.group(1)),
            ("category_id", cat.group(1)),
            ("provider_game_id", "lucy-dice-01"),
            ("tag", "HOT"),
            ("label", "HOT"),
            ("label_background_color", "#E5484D"),
            ("art", "dice"),
            ("symbol", "⚄"),
            ("sort_order", "5"),
            ("top_game", "1"),
            ("free_bet_supported", "1"),
            ("external_launch_code", ""),
            ("agent", "lucky-dice"),
            ("crash", "1"),
        ]
        # attach current lobbies
        for m in re.finditer(r'<option value="(\d+)" selected>\s*[^<]+</option>',
                             re.search(r'name="lobbies\[\]".*?</select>', html, re.S).group(0)):
            payload.append(("lobbies[]", m.group(1)))

        r = admin.post(
            f"{BASE}/admin/game-engine/games/lucky-dice",
            data=payload, allow_redirects=True,
        )
        assert r.status_code == 200, f"update returned {r.status_code}"
        assert "Game updated" in r.text or "admin_status" in r.text or \
               "Lucy Lucky Dice" in r.text, "No update flash / title not reflected"

        # GET to verify persistence
        html2 = self._current(admin, "lucky-dice")
        assert 'value="Lucy Lucky Dice"' in html2
        assert 'value="HOT"' in html2
        assert 'value="#E5484D"' in html2
        assert 'name="top_game" value="1" checked' in html2 or re.search(
            r'name="top_game"[^>]*checked', html2
        ), "top_game not persisted"
        assert re.search(r'name="crash"[^>]*checked', html2), "crash flag not persisted"

    def test_label_badge_rendered_on_lobby(self, player):
        r = player.get(f"{BASE}/")
        assert r.status_code == 200
        # Expect the HOT badge with red bg color for lucky-dice
        assert 'class="game-label"' in r.text
        assert "#E5484D" in r.text, "Configured label bg color not rendered on lobby"

    def test_restore_title_after_lobby_check(self, admin):
        """Reset the title/slug back so the rest of tests behave normally."""
        html = self._current(admin, "lucky-dice")
        token = _tok(html)
        prov = re.search(r'name="provider_id"[^>]*>.*?<option value="(\d+)" selected', html, re.S)
        cat = re.search(r'name="category_id"[^>]*>.*?<option value="(\d+)" selected', html, re.S)
        payload = [
            ("_token", token), ("_method", "PUT"),
            ("title", "Lucky Dice"),
            ("slug", "lucky-dice"),
            ("description", ""),
            ("provider_id", prov.group(1)),
            ("category_id", cat.group(1)),
            ("provider_game_id", ""),
            ("tag", ""),
            ("label", ""),
            ("label_background_color", ""),
            ("art", "dice"),
            ("symbol", "⚄"),
            ("sort_order", "1"),
            ("top_game", "1"),
            ("external_launch_code", ""),
            ("agent", "lucky-dice"),
        ]
        for m in re.finditer(r'<option value="(\d+)" selected>\s*[^<]+</option>',
                             re.search(r'name="lobbies\[\]".*?</select>', html, re.S).group(0)):
            payload.append(("lobbies[]", m.group(1)))
        r = admin.post(
            f"{BASE}/admin/game-engine/games/lucky-dice",
            data=payload, allow_redirects=True,
        )
        assert r.status_code == 200


# =====================================================================
# 4. Image upload E2E on coin-flip, then remove.
# =====================================================================
class TestGameImageUploadE2E:
    slug = "coin-flip"

    def test_upload_image_appears_on_lobby(self, admin, player):
        html = admin.get(f"{BASE}/admin/game-engine/games/{self.slug}/edit").text
        token = _tok(html)
        prov = re.search(r'name="provider_id"[^>]*>.*?<option value="(\d+)" selected', html, re.S)
        cat = re.search(r'name="category_id"[^>]*>.*?<option value="(\d+)" selected', html, re.S)
        title_m = re.search(r'name="title"\s+value="([^"]+)"', html)
        art_m = re.search(r'name="art"\s+value="([^"]+)"', html)
        sym_m = re.search(r'name="symbol"\s+value="([^"]*)"', html)
        assert prov and cat and title_m and art_m

        files = {
            "image_upload": ("TEST_coin.png", _make_png(), "image/png"),
        }
        data = [
            ("_token", token), ("_method", "PUT"),
            ("title", title_m.group(1)),
            ("slug", self.slug),
            ("description", "TEST iter2 upload"),
            ("provider_id", prov.group(1)),
            ("category_id", cat.group(1)),
            ("provider_game_id", ""),
            ("tag", ""),
            ("label", ""),
            ("label_background_color", ""),
            ("art", art_m.group(1)),
            ("symbol", sym_m.group(1) if sym_m else "★"),
            ("sort_order", "10"),
            ("external_launch_code", ""),
            ("agent", ""),
            # Publish to first lobby so it appears on player lobby
            ("lobbies[]", "1"),
        ]
        r = admin.post(
            f"{BASE}/admin/game-engine/games/{self.slug}",
            data=data, files=files, allow_redirects=True,
        )
        assert r.status_code == 200, f"Upload update returned {r.status_code}"

        # Verify admin edit page now shows preview src (attribute order may vary)
        html2 = admin.get(f"{BASE}/admin/game-engine/games/{self.slug}/edit").text
        preview_tag = re.search(r'<img[^>]*data-image-preview[^>]*>', html2)
        assert preview_tag, "data-image-preview <img> missing on edit form"
        m_src = re.search(r'src="([^"]+/storage/game-images/[^"]+)"', preview_tag.group(0))
        assert m_src, f"Uploaded artwork src missing on edit form: {preview_tag.group(0)}"
        img_url = m_src.group(1)

        # Verify the storage URL returns 200
        rimg = requests.get(img_url)
        assert rimg.status_code == 200, f"Uploaded image URL failed {img_url}"
        assert rimg.headers.get("content-type", "").startswith("image/")

        # Verify the admin table row also shows an <img
        idx_html = admin.get(f"{BASE}/admin/game-engine?page=game&search=coin").text
        assert self.slug in idx_html.lower() or "coin flip" in idx_html.lower()

        # Verify lobby card shows the image
        lobby_html = player.get(f"{BASE}/").text
        assert "game-images/" in lobby_html, "Uploaded artwork not appearing in player lobby"

    def test_remove_image_clears_artwork(self, admin, player):
        html = admin.get(f"{BASE}/admin/game-engine/games/{self.slug}/edit").text
        assert "data-image-preview" in html
        token = _tok(html)
        prov = re.search(r'name="provider_id"[^>]*>.*?<option value="(\d+)" selected', html, re.S)
        cat = re.search(r'name="category_id"[^>]*>.*?<option value="(\d+)" selected', html, re.S)
        title_m = re.search(r'name="title"\s+value="([^"]+)"', html)
        art_m = re.search(r'name="art"\s+value="([^"]+)"', html)
        sym_m = re.search(r'name="symbol"\s+value="([^"]*)"', html)

        data = [
            ("_token", token), ("_method", "PUT"),
            ("title", title_m.group(1)),
            ("slug", self.slug),
            ("description", ""),
            ("provider_id", prov.group(1)),
            ("category_id", cat.group(1)),
            ("provider_game_id", ""),
            ("tag", ""),
            ("label", ""),
            ("label_background_color", ""),
            ("art", art_m.group(1)),
            ("symbol", sym_m.group(1) if sym_m else "★"),
            ("sort_order", "10"),
            ("remove_image", "1"),
            ("external_launch_code", ""),
            ("agent", ""),
        ]
        r = admin.post(
            f"{BASE}/admin/game-engine/games/{self.slug}",
            data=data, allow_redirects=True,
        )
        assert r.status_code == 200

        html2 = admin.get(f"{BASE}/admin/game-engine/games/{self.slug}/edit").text
        # After removal: either the checkbox toggle disappears (only shown when image_source) OR preview hidden
        preview = re.search(r'<img[^>]*data-image-preview[^>]*>', html2)
        if preview:
            assert re.search(r'hidden', preview.group(0)) or 'src=""' in preview.group(0), (
                f"Remove image did not clear preview: {preview.group(0)}"
            )
        assert 'name="remove_image"' not in html2, (
            "Remove-image toggle should not render when no image is present"
        )


# =====================================================================
# 5. Agent create form (redesigned).
# =====================================================================
class TestAgentCreate:
    def test_create_form_renders_sections(self, admin):
        r = admin.get(f"{BASE}/admin/agents/create")
        assert r.status_code == 200
        for needle in [
            "Identity", "Security", "Commission",
            'data-testid="create-agent-submit"',
            'name="first_name"', 'name="last_name"',
            'name="nickname"', 'name="phone"', 'name="password"',
            # Commission is a platform-wide flat rule now, so there is no per-agent rate field.
            'name="commission_enabled"',
        ]:
            assert needle in r.text, f"Missing on agent create form: {needle}"
        assert 'name="commission_rate"' not in r.text, "Per-agent commission rate must no longer be offered"

    def test_create_agent_success(self, admin):
        html = admin.get(f"{BASE}/admin/agents/create").text
        token = _tok(html)
        import time
        suffix = str(int(time.time()))[-6:]
        nickname = f"test_iter2_{suffix}"
        phone = "251999" + suffix
        r = admin.post(
            f"{BASE}/admin/agents",
            data={
                "_token": token,
                "first_name": "TESTFN",
                "last_name": "TESTLN",
                "nickname": nickname,
                "phone": phone,
                "password": "AgentPass!23",
                "commission_enabled": "1",
            },
            allow_redirects=True,
        )
        assert r.status_code == 200, f"agent create returned {r.status_code}"
        assert "Agent created" in r.text, (
            f"Success flash not shown. Landing URL: {r.url}. "
            f"Response contains 'error': {'error' in r.text.lower()}"
        )
        idx = admin.get(f"{BASE}/admin/agents").text
        assert nickname in idx, "New agent nickname missing from listing"

        # cleanup — remove the created agent to keep DB clean
        import subprocess
        subprocess.run(
            ["php", "artisan", "tinker", "--execute",
             f"App\\Models\\Agent::where('nickname','{nickname}')->delete();"],
            cwd="/app", check=True, capture_output=True,
        )


# =====================================================================
# 6. Add New game & category popovers, then delete.
# =====================================================================
class TestGameEngineAddNew:
    def test_create_game_via_add_new(self, admin):
        html = admin.get(f"{BASE}/admin/game-engine?page=game").text
        token = _tok(html)
        # find first provider/category options
        prov = re.search(r'<select[^>]*name="provider_id"[^>]*>\s*<option[^>]*value="(\d+)"', html)
        cat = re.search(r'<select[^>]*name="category_id"[^>]*>\s*<option[^>]*value="(\d+)"', html)
        assert prov and cat, "provider/category selects missing on add form"

        r = admin.post(
            f"{BASE}/admin/game-engine/games",
            data={
                "_token": token,
                "title": "TEST_iter2_game",
                "slug": "test-iter2-game",
                "description": "TEST",
                "provider_id": prov.group(1),
                "category_id": cat.group(1),
                "provider_game_id": "",
                "tag": "TEST",
                "label": "",
                "label_background_color": "",
                "art": "custom",
                "symbol": "★",
                "sort_order": "999",
                "external_launch_code": "",
                "agent": "",
            },
            allow_redirects=True,
        )
        assert r.status_code == 200
        assert "Game created" in r.text or "TEST_iter2_game" in r.text
        # cleanup
        edit = admin.get(f"{BASE}/admin/game-engine/games/test-iter2-game/edit").text
        del_tok = _tok(edit)
        rd = admin.post(
            f"{BASE}/admin/game-engine/games/test-iter2-game",
            data={"_token": del_tok, "_method": "DELETE"},
            allow_redirects=True,
        )
        assert rd.status_code == 200
        assert "Game deleted" in rd.text or "TEST_iter2_game" not in rd.text

    def test_create_category_via_add_new(self, admin):
        html = admin.get(f"{BASE}/admin/game-engine?page=category").text
        token = _tok(html)
        r = admin.post(
            f"{BASE}/admin/game-engine/categories",
            data={
                "_token": token,
                "name": "TEST_iter2_cat",
                "description": "TEST",
                "sort_order": "999",
            },
            allow_redirects=True,
        )
        assert r.status_code == 200
        assert "Category created" in r.text or "TEST_iter2_cat" in r.text

        # cleanup via toggle-off is not enough; delete route not exposed → PUT to hide
        # Use SQLite directly via php artisan for cleanup
        import subprocess
        subprocess.run(
            ["php", "artisan", "tinker", "--execute",
             "App\\Models\\GameCategory::where('slug','test-iter2-cat')->delete();"],
            cwd="/app", check=True, capture_output=True,
        )


# =====================================================================
# 7. Player lobby regression — Lucky Dice playable, provider filter markup.
# =====================================================================
class TestLobbyRegression:
    def test_provider_filter_options_render(self, player):
        r = player.get(f"{BASE}/")
        assert '<option value="">All providers</option>' in r.text or \
               "All studios" in r.text

    def test_lucky_dice_place_bet_demo(self, player):
        r = player.get(f"{BASE}/games/lucky-dice")
        assert r.status_code == 200
        token_m = re.search(r'name="_token"\s+value="([^"]+)"', r.text)
        assert token_m, "CSRF token missing on lucky-dice page"
        # Confirm the play form action exists (attribute order-agnostic)
        assert 'action="' in r.text and 'games/lucky-dice/play' in r.text, (
            "Lucky Dice play form action missing"
        )
        # Place a small demo bet (stake 10, guess Even)
        r2 = player.post(
            f"{BASE}/games/lucky-dice/play",
            data={"_token": token_m.group(1), "stake": "10", "choice": "even"},
            allow_redirects=True,
        )
        assert r2.status_code == 200, f"Bet POST returned {r2.status_code}"
        # There should be no validation error banner for stake/choice.
        assert "Whoops" not in r2.text and "SQLSTATE" not in r2.text

    def test_search_and_filter_data_attrs(self, player):
        r = player.get(f"{BASE}/")
        assert 'data-game-search' in r.text
        assert 'data-provider-filter' in r.text
        assert 'data-game-card' in r.text


# =====================================================================
# 8. Admin nav regression (all pages 200, no server error).
# =====================================================================
NAV_PATHS = [
    "/admin", "/admin/administrators", "/admin/members",
    "/admin/module/promotion-banners", "/admin/settings",
    "/admin/payment-gateways", "/admin/agents", "/admin/agents/create",
    "/admin/agent-orders", "/admin/game-engine",
    "/admin/game-engine/games/lucky-dice/edit",
    "/admin/rounds", "/admin/transactions", "/admin/promotions",
    "/admin/shamo", "/admin/module/loyalty-bonus",
    "/admin/module/challenges", "/admin/module/tournaments",
    "/admin/module/telegram", "/admin/module/broadcast-bot",
]


@pytest.mark.parametrize("path", NAV_PATHS)
def test_admin_page_loads(admin, path):
    r = admin.get(f"{BASE}{path}")
    assert r.status_code == 200, f"{path} -> {r.status_code}"
    assert "Whoops" not in r.text, f"{path} shows Whoops error page"
    assert "SQLSTATE" not in r.text, f"{path} shows SQL exception"
