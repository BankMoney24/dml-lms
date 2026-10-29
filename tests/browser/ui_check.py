"""Browser checks for the DML Learning Platform (Playwright for Python).

Run against a development server that has the sample school loaded, for example:

    php bin/console sample:seed dml          # once, on a development database
    php -S 127.0.0.1:8080 -t public          # in another terminal (with a router that maps the school's host)
    python3 tests/browser/ui_check.py --base http://dml.localhost:8080 \
        --admin ada@example.org:"ada long passphrase" --learner amaka@example.org:"amaka long passphrase"

Optional: --axe path/to/axe.min.js adds an automated accessibility scan (axe-core, WCAG 2.2 A/AA rules).
Each check prints PASS or FAIL; the exit status is the number of failures (0 = all passed).
Never point this at a live school: it signs in, sends forms and changes nothing else, but it is a test tool.
"""
import argparse
import sys

from playwright.sync_api import sync_playwright

WIDTHS = [360, 390, 430, 768, 820, 1024, 1280, 1440]
PUBLIC_PAGES = ["/", "/login", "/join", "/join/parent", "/password/forgot", "/courses", "/courses?track=everyday", "/verify", "/developers", "/accessibility", "/programmes", "/blog", "/blog/how-to-spot-a-payment-scam"]
SIGNED_IN_PAGES = ["/", "/courses", "/classes", "/meetings", "/notifications", "/account", "/help", "/playground", "/search?q=net"]
ADMIN_PAGES = ["/school", "/people", "/school/reports", "/school/payments", "/school/payments?q=amaka&status=failed", "/school/payments/codes", "/school/api", "/school/support?q=a", "/teach/applications?q=a", "/activity?kind=learning", "/school/website", "/school/website/ready", "/school/blog", "/school/blog/new", "/school/website/faq", "/school/classroom", "/teach/labs/network-build", "/teach/labs/network-repair", "/teach/labs/network-vlan", "/teach/labs/firewall-rules", "/teach/labs/dns-lookup", "/teach/labs/pendulum", "/teach/labs/osmosis", "/teach", "/groups", "/meetings/new"]

failures = []


def check(name, ok, detail=""):
    print(("PASS " if ok else "FAIL ") + name + (" :: " + detail if detail and not ok else ""), flush=True)
    if not ok:
        failures.append(name)


def overflow(page):
    return page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")


def sign_in(page, base, cred):
    email, password = cred.split(":", 1)
    page.goto(base + "/login")
    page.fill("#email", email)
    page.fill("#password", password)
    page.click("main button[type=submit]")
    page.wait_for_load_state()
    return "/login" not in page.url


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://dml.localhost:8080")
    ap.add_argument("--admin", default="ada@example.org:ada long passphrase")
    ap.add_argument("--learner", default="amaka@example.org:amaka long passphrase")
    ap.add_argument("--axe", default=None)
    ap.add_argument("--resolve", default="MAP *.localhost 127.0.0.1", help="Chromium --host-resolver-rules ('' for none)")
    a = ap.parse_args()
    axe = open(a.axe).read() if a.axe else None
    args = ["--host-resolver-rules=" + a.resolve] if a.resolve else []

    with sync_playwright() as p:
        browser = p.chromium.launch(args=args)

        # 1. No sideways scrolling at any supported width, signed out and signed in.
        for role, cred, pages in (("visitor", None, PUBLIC_PAGES), ("learner", a.learner, SIGNED_IN_PAGES), ("admin", a.admin, ADMIN_PAGES)):
            ctx = browser.new_context(viewport={"width": 1280, "height": 900})
            page = ctx.new_page()
            errors = []
            page.on("pageerror", lambda e: errors.append(str(e)))
            if cred:
                check(f"{role} can sign in", sign_in(page, a.base, cred))
            for path in pages:
                worst = {}
                for w in WIDTHS:
                    page.set_viewport_size({"width": w, "height": 844 if w < 500 else 900})
                    page.goto(a.base + path)
                    o = overflow(page)
                    if o > 1:
                        worst[w] = o
                check(f"{role} {path}: no sideways scroll at {WIDTHS[0]}-{WIDTHS[-1]}px", not worst, str(worst))
                if axe:
                    page.set_viewport_size({"width": 390, "height": 844})
                    page.goto(a.base + path)
                    page.evaluate(axe + ";\ntrue")
                    found = page.evaluate("""async () => (await axe.run(document, {runOnly: {type: 'tag', values: ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}))
                        .violations.map(v => v.id + ' x' + v.nodes.length)""")
                    check(f"{role} {path}: axe WCAG 2.2 AA", not found, ", ".join(found))
            check(f"{role}: no script errors", not errors, "; ".join(errors[:3]))
            ctx.close()

        # 2. Sign-in form: Show/Hide password, and one tap sends the form only once.
        ctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
        page = ctx.new_page()
        page.goto(a.base + "/login")
        page.fill("#password", "secret words")
        page.click("[data-reveal=password]")
        check("password Show reveals the text", page.get_attribute("#password", "type") == "text")
        check("the button then reads Hide password", page.inner_text("[data-reveal=password]").replace("\n", " ").strip() == "Hide password")
        page.click("[data-reveal=password]")
        check("Hide masks it again", page.get_attribute("#password", "type") == "password")
        posts = []
        page.on("request", lambda r: posts.append(r.url) if r.method == "POST" else None)
        page.route("**/login", lambda route: (page.wait_for_timeout(1200), route.continue_()) if route.request.method == "POST" else route.continue_())
        page.fill("#email", "nobody@example.org")
        # Three quick taps, as an impatient person on a slow connection would make.
        state = page.evaluate("""() => { const b = document.querySelector('main button[type=submit]');
            b.click(); b.click(); b.click();
            return [b.getAttribute('aria-disabled'), b.classList.contains('is-busy')]; }""")
        check("busy button is marked while sending", state == ["true", True], str(state))
        page.wait_for_url("**/login", wait_until="load")
        page.wait_for_timeout(1500)
        check("three taps sent the sign-in form once", len(posts) == 1, str(len(posts)))
        ctx.close()

        # 3. People list: filter by role, page links, and sortable report headings.
        ctx = browser.new_context(viewport={"width": 1280, "height": 900})
        page = ctx.new_page()
        sign_in(page, a.base, a.admin)
        page.goto(a.base + "/people")
        with page.expect_navigation():
            page.select_option("#people-role", "educator")
        check("choosing a role filters the people list", "role=educator" in page.url and page.locator("tr[id^=person-]").count() >= 1)
        page.goto(a.base + "/school/reports")
        heading = page.locator("table[data-sortable] th .sort-button").first
        if heading.count():
            heading.click()
            check("a report heading sorts the table", page.locator("table[data-sortable] th[aria-sort=ascending]").count() == 1)
        else:
            check("reports have sortable headings", False, "no sortable table found")
        # 4. Leaving a page with edited writing asks first; sending it does not.
        page.goto(a.base + "/school/website/about")
        dialogs = []
        page.on("dialog", lambda d: (dialogs.append(d.type), d.dismiss()))
        area = page.locator("main form textarea").first
        area.click()
        area.press("End")
        area.type(" (draft)")
        try:
            page.goto(a.base + "/school", timeout=3000)
        except Exception:
            pass
        check("leaving with unsaved writing asks first", dialogs == ["beforeunload"], str(dialogs))
        ctx.close()
        browser.close()

    print(f"\n{len(failures)} failed" if failures else "\nAll browser checks passed")
    return len(failures)


if __name__ == "__main__":
    sys.exit(main())
