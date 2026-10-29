"""Public-site walkthrough against a LOCAL backend and disposable database.
Configure its cycle with nomination:setup to the confirmed dates. Synthetic
schedules simulate deadline boundaries and unavailable configuration only.
Requires the existing Python Playwright installation and a local axe bundle.

Usage: python public-site-walkthrough.py <out dir> <axe.min.js> <local base URL>
"""
import json
import pathlib
import sys
import xml.etree.ElementTree as ET
from urllib.parse import urlparse

from playwright.sync_api import expect, sync_playwright

expect.set_options(timeout=15_000)
sys.stdout.reconfigure(encoding="utf-8")
OUT, AXE = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]).read_text(encoding="utf-8")
OUT.mkdir(parents=True, exist_ok=True)
BASE = sys.argv[3].rstrip("/")
if urlparse(BASE).hostname not in ("127.0.0.1", "localhost", "::1"):
    raise ValueError("This walkthrough must only target a local preview")
ROOT = pathlib.Path(__file__).resolve().parents[2]
PAGES = ["/", "/about", "/journey", "/eligibility", "/eligibility/culture-and-arts", "/eligibility/heritage", "/eligibility/creativity"]
DOMAINS = {"الثقافة والفنون": ["الثقافة", "الفنون"], "التراث": ["التراث المادي", "التراث اللامادي"], "الإبداع": ["المسؤولية المجتمعية", "ريادة الأعمال", "الجودة"]}
RETIRED = ["الصورة: منظمة", "التصويت", "جائزة الجمهور", "الميداني", "الزيارات الميدانية", "العمل التطوعي", "المحاور الستة", "والتنمية المجتمعية", "وتمكينٌ"]
results = []


def check(name, ok, detail=""):
    results.append((name, bool(ok)))
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""), flush=True)


def overflow(page):
    return page.evaluate("document.documentElement.scrollWidth > window.innerWidth")


def ready(page, schedule_phase=None):
    expect(page.locator("main h1")).to_be_visible()
    page.evaluate("document.fonts.ready")
    if schedule_phase:
        expect(page.locator(".countdown")).to_have_attribute("data-phase", schedule_phase)


def axe(page, label):
    page.add_script_tag(content=AXE)
    found = page.evaluate("async () => (await axe.run(document, {runOnly: ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']})).violations.map(v => v.id + ':' + v.nodes.length)")
    check(f"axe {label}: no WCAG A/AA violations", not found, found)


def schedule_at(server_time, open_):
    return {"data": {"server_time": server_time, "cycle": {"name": "الدورة الأولى", "year": 2026, "timezone": "Asia/Damascus"},
                     "application": {"opens_at": None, "closes_at": "2026-11-15T21:00:00Z", "open": open_},
                     "judging": {"opens_at": "2026-11-15T21:00:00Z", "closes_at": "2026-11-25T21:00:00Z"}}}


with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(viewport={"width": 1440, "height": 900}, locale="ar")
    # External sites are not contacted: the new tab is answered locally.
    context.route("https://www.hand.ngo/**", lambda route: route.fulfill(status=200, body="<title>HAND</title>", content_type="text/html"))
    context.route("https://www.alrowadngo.sy/**", lambda route: route.fulfill(status=200, body="<title>Al-Rowad</title>", content_type="text/html"))
    page = context.new_page()

    # Direct visits and genuine 404s.
    for path in PAGES:
        check(f"direct visit {path}: 200", page.goto(BASE + path).status == 200)
    for path in ["/eligibility/quality", "/eligibility/heritage/x", "/about/team"]:
        check(f"unknown {path}: 404", page.goto(BASE + path).status == 404)

    # The real schedule from the configured cycle.
    page.goto(BASE + "/")
    real = page.evaluate("fetch('/api/public/schedule').then(r => r.json())")["data"]
    check("server schedule: closing 2026-11-16T00:00+03:00, judging until 2026-11-26T00:00+03:00",
          real["application"]["closes_at"] == "2026-11-15T21:00:00Z" and real["judging"] == {"opens_at": "2026-11-15T21:00:00Z", "closes_at": "2026-11-25T21:00:00Z"} and real["application"]["open"])
    hero = page.locator(".hero")
    expect(hero.locator(".countdown__unit")).to_have_count(4)
    hero_text = hero.inner_text()
    check("hero: official slogan and full name", "وفاءٌ للأثر" in hero_text and "تمكينٌ للمبادرة" in hero_text and "جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع" in hero_text)
    check("hero: the exact deadline and judging period", "يغلق باب الترشح بنهاية يوم 15 نوفمبر 2026 — بتوقيت دمشق" in hero_text and "فترة التحكيم: من 16 إلى 25 نوفمبر 2026" in hero_text)
    check("hero: labelled units", all(label in hero_text for label in ["يوماً", "ساعة", "دقيقة", "ثانية"]))
    first = page.locator(".countdown__value").last.inner_text()
    expect(page.locator(".countdown__value").last).not_to_have_text(first)
    check("countdown ticks from now", page.locator(".countdown__value").last.inner_text() != first)
    live = page.locator("[aria-live]").all_inner_texts()
    check("no per-second announcement", all(not any(ch.isdigit() for ch in text) for text in live), live)
    check("CTA while open: «قدّم ملف ترشحك» to /register", page.get_by_role("link", name="قدّم ملف ترشحك").first.get_attribute("href") == "/register")
    page.screenshot(path=str(OUT / "home-hero-1440.png"), animations="disabled")

    # Every page: content rules, layout and accessibility, desktop and mobile.
    for width in (1440, 390):
        page.set_viewport_size({"width": width, "height": 900})
        for path in PAGES:
            page.goto(BASE + path)
            ready(page, "open" if path == "/" else None)
            text = page.locator("body").inner_text()
            name = path.strip("/").replace("/", "-") or "home"
            check(f"{name} at {width}px: no horizontal page scroll", not overflow(page))
            if width == 1440:
                found = [term for term in RETIRED if term in text]
                check(f"{name}: no photo credit or retired content", not found, found)
                check(f"{name}: title and description", page.title() and page.locator('meta[name="description"]').get_attribute("content"), page.title())
                axe(page, name)
            if path == "/" and width == 390:
                page.locator(".hero").screenshot(path=str(OUT / "home-hero-390.png"), animations="disabled")
            if path == "/eligibility/creativity" and width == 1440:
                page.screenshot(path=str(OUT / "creativity-1440.png"), animations="disabled")

    # Three domains, seven subcategories, approved artwork.
    page.set_viewport_size({"width": 1440, "height": 900})
    page.goto(BASE + "/")
    cards = page.locator(".domain-card")
    expect(cards).to_have_count(3)
    seen = {cards.nth(i).locator(".domain-card__title").inner_text(): cards.nth(i).locator(".domain-card__subcategories li").all_inner_texts() for i in range(3)}
    check("home: three domains and seven subcategories", seen == DOMAINS, seen)
    # Vite can inline SVGs or hash their filenames: compare the rendered SVG
    # content with the original artwork, including all geometry and colours.
    rendered = cards.nth(2).locator("img").evaluate("async img => (await fetch(img.currentSrc || img.src)).text()")
    original = (ROOT / "frontend/src/assets/brand/domain-volunteer-work.svg").read_text(encoding="utf-8")
    check("creativity uses the Volunteer Work artwork", ET.canonicalize(rendered, strip_text=True) == ET.canonicalize(original, strip_text=True))
    for slug, label in [("culture-and-arts", "الثقافة والفنون"), ("heritage", "التراث"), ("creativity", "الإبداع")]:
        page.goto(BASE + f"/eligibility/{slug}")
        subs = page.locator(".info-card--subcategory .info-card__title").all_inner_texts()
        crumbs = page.locator(".breadcrumbs").inner_text()
        check(f"{slug}: its subcategories and breadcrumbs", subs == DOMAINS[label] and "شروط التقدم" in crumbs and label in crumbs, subs)
        check(f"{slug}: active section in the header", page.locator(".site-nav__link[aria-current]").inner_text() == "شروط التقدم")

    # Organisation links open their own sites in a new tab.
    page.goto(BASE + "/about")
    for name, url in [("مؤسسة الروّاد للتعاون والتنمية", "https://www.alrowadngo.sy/"), ("HAND", "https://www.hand.ngo/")]:
        link = page.locator("main .org-link", has_text=name).first
        ok = link.get_attribute("href") == url and link.get_attribute("target") == "_blank" and link.get_attribute("rel") == "noopener noreferrer"
        check(f"{name}: external link attributes", ok)
        check(f"{name}: new-tab indication", "يفتح في نافذة جديدة" in link.inner_text())
        with context.expect_page() as opened:
            link.click()
        tab = opened.value
        tab.wait_for_load_state()
        check(f"{name}: opens in a new tab", tab.url.startswith(url), tab.url)
        tab.close()
    footer = page.locator(".site-footer")
    check("footer: four pages, three domains, policies and the linked organisation",
          footer.locator("a", has_text="شروط التقدم").count() == 1 and footer.locator("a", has_text="الإبداع").count() == 1
          and footer.locator("a[href='/policies/privacy-policy']").count() == 1 and footer.locator("a[href='https://www.alrowadngo.sy/']").count() == 1)

    # Keyboard: skip link, navigation, active page, mobile menu.
    page.goto(BASE + "/journey")
    ready(page)
    page.keyboard.press("Tab")
    check("keyboard: the skip link comes first", page.evaluate("document.activeElement.className") == "skip-link")
    page.keyboard.press("Enter")
    check("keyboard: skip link moves focus to main", page.evaluate("document.activeElement.id") == "main")
    page.get_by_role("link", name="شروط التقدم").first.focus()
    page.keyboard.press("Enter")
    # pushState precedes React's commit, metadata effect and smooth scroll.
    # Wait for the observable outcome; route-only readiness races all three.
    page.wait_for_function("location.pathname === '/eligibility' && document.querySelector('main h1')?.textContent === 'شروط التقدم' && document.title.startsWith('شروط التقدم |') && document.activeElement.id === 'main'")
    # Cross the committed browser frame before accepting an initial zero:
    # an interrupted native hash scroll can otherwise move it afterward.
    page.evaluate("new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))")
    page.wait_for_function("window.scrollY === 0")
    check("keyboard navigation: focus on main after navigating, page at the top",
          page.evaluate("document.activeElement.id") == "main" and page.evaluate("window.scrollY") == 0)
    check("active page marked", page.locator(".site-nav__link[aria-current='page']").inner_text() == "شروط التقدم")
    check("keyboard navigation updates the title", page.title().startswith("شروط التقدم |"))
    page.go_back()
    expect(page.locator("main h1")).to_have_text("مسيرة الجائزة")
    page.wait_for_function("document.title.startsWith('مسيرة الجائزة |')")
    check("history back restores the journey page and title", page.url.endswith("/journey#main"))
    page.go_forward()
    expect(page.locator("main h1")).to_have_text("شروط التقدم")
    check("history forward restores eligibility", page.url.endswith("/eligibility"))

    page.set_viewport_size({"width": 320, "height": 700})
    page.goto(BASE + "/")
    ready(page, "open")
    toggle = page.get_by_role("button", name="القائمة")
    toggle.focus()
    page.keyboard.press("Enter")
    expect(page.locator("#site-nav")).to_be_visible()
    check("320px: menu opens with four pages, no horizontal scroll", page.locator("#site-nav .site-nav__link").count() == 4 and not overflow(page))
    page.screenshot(path=str(OUT / "menu-320.png"), animations="disabled")
    page.keyboard.press("Escape")
    check("320px: Escape closes the menu and returns focus", not page.locator("#site-nav").is_visible() and page.evaluate("document.activeElement.className").startswith("site-header__toggle"))
    page.locator(".site-footer").scroll_into_view_if_needed()
    check("320px: footer without horizontal scroll", not overflow(page))
    page.locator(".site-footer").screenshot(path=str(OUT / "footer-320.png"), animations="disabled")

    # Simulated deadline transition: the server says 3 seconds are left.
    page.set_viewport_size({"width": 1440, "height": 900})
    sim = browser.new_context(viewport={"width": 1440, "height": 900}, locale="ar", timezone_id="America/Los_Angeles")
    sim.route("**/api/public/schedule", lambda route: route.fulfill(status=200, content_type="application/json", body=json.dumps(schedule_at("2026-11-15T20:59:57Z", True))))
    tab = sim.new_page()
    tab.goto(BASE + "/")
    expect(tab.locator(".countdown__title")).to_have_text("يغلق باب الترشح بعد")
    values = tab.locator(".countdown__value").all_inner_texts()
    check("3 seconds before closing (device in Los Angeles): 00:00:00:02–03 left", values[:3] == ["00", "00", "00"] and values[3] in ("02", "03"), values)
    expect(tab.locator(".countdown__title")).to_have_text("أُغلق باب الترشح", timeout=8000)
    text = tab.locator(".countdown").inner_text()
    check("at closing: «أُغلق باب الترشح», no timer, judging period shown",
          tab.locator(".countdown__units").count() == 0 and "فترة التحكيم: من 16 إلى 25 نوفمبر 2026" in text and "أُغلق باب الترشح بنهاية يوم 15 نوفمبر 2026" in text)
    check("at closing: the CTA becomes «الدخول إلى المنصة»", tab.get_by_role("link", name="الدخول إلى المنصة").first.get_attribute("href") == "/login")
    check("at closing: one polite announcement", "أُغلق باب الترشح" in " ".join(tab.locator("[aria-live='polite']").all_inner_texts()))
    tab.screenshot(path=str(OUT / "transition-closed-1440.png"), animations="disabled")
    sim.close()

    after = browser.new_context(viewport={"width": 390, "height": 900}, locale="ar")
    after.route("**/api/public/schedule", lambda route: route.fulfill(status=200, content_type="application/json", body=json.dumps(schedule_at("2026-11-26T08:00:00Z", False))))
    tab = after.new_page()
    tab.goto(BASE + "/")
    expect(tab.locator(".countdown__title")).to_have_text("انتهت فترة التحكيم")
    text = tab.locator("main").inner_text()
    check("after judging: «انتهت فترة التحكيم», no claim of results", "النتائج" not in tab.locator(".countdown").inner_text() and "الفائز" not in tab.locator(".countdown").inner_text())
    with tab.expect_response("**/api/public/schedule") as loaded:
        tab.goto(BASE + "/journey")
    loaded.value.body()
    expect(tab.locator(".timeline__status", has_text="انتهت")).to_have_count(2)
    expect(tab.locator(".timeline__status", has_text="مخطط لها")).to_have_count(1)
    check("journey after judging: application and judging «انتهت», award week still planned", tab.locator(".timeline__status", has_text="انتهت").count() == 2 and tab.locator(".timeline__status", has_text="مخطط لها").count() == 1)
    tab.screenshot(path=str(OUT / "journey-judged-390.png"), full_page=True)
    after.close()

    unavailable = browser.new_context(viewport={"width": 390, "height": 900}, locale="ar")
    empty = {"data": {"server_time": "2026-11-01T09:00:00Z", "cycle": None, "application": None, "judging": None}}
    unavailable.route("**/api/public/schedule", lambda route: route.fulfill(status=200, content_type="application/json", body=json.dumps(empty)))
    tab = unavailable.new_page()
    with tab.expect_response("**/api/public/schedule") as loaded:
        tab.goto(BASE + "/")
    loaded.value.body()
    expect(tab.locator(".countdown")).to_have_attribute("data-phase", "unknown")
    check("unavailable or ambiguous schedule never advertises open applications", tab.locator(".countdown__units").count() == 0 and tab.get_by_role("link", name="قدّم ملف ترشحك").count() == 0)
    unavailable.close()
    browser.close()

failed = [name for name, ok in results if not ok]
print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
sys.exit(1 if failed else 0)
