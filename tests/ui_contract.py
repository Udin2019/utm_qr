"""Static HTML/JS integration checks; does not replace visual browser QA."""
import json
import re
from collections import Counter
from html.parser import HTMLParser
from pathlib import Path
from xml.etree import ElementTree as ET

root = Path(__file__).resolve().parents[1]
assets = root / 'plugin/cargo-utm/assets/ui'
html = (root / 'plugin/cargo-utm/views/workspace.php').read_text()
html = re.sub(r'<\?php.*?\?>', 'server-asset-url', html, flags=re.S)


class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = []
        self.carriers = set()
        self.tags = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        self.tags.append((tag, attrs))
        if 'id' in attrs:
            self.ids.append(attrs['id'])
        if 'data-carrier' in attrs:
            self.carriers.add(attrs['data-carrier'])


page = Page()
page.feed(html)
assert not [key for key, count in Counter(page.ids).items() if count > 1], 'Duplicate HTML ID'
campaign_code = next(attrs for tag, attrs in page.tags if tag == 'input' and attrs.get('name') == 'campaign_code')
assert 'required' not in campaign_code, 'Custom campaign code must be optional'
assert "f.campaign_code.value =" not in (assets / 'app.js').read_text(), 'Auto-code must not overwrite user input'
navigation = re.search(r'<nav class="navigation".*?</nav>', html, re.S).group(0)
assert 'data-view="generator"' in navigation, 'Public generator must remain in navigation'
assert 'data-view="register"' in navigation and 'data-view="decode"' in navigation
assert any(tag == 'a' and attrs.get('id') == 'generator-home' for tag, attrs in page.tags), 'Home link missing'
assert page.carriers == {'exhibition', 'conference', 'outdoor', 'transport', 'offline_point', 'print_media'}
js = (assets / 'app.js').read_text()
static_ids = set(page.ids) | {'cargo-app', 'cargo-config'}
dynamic_ids = {'roistat-hint', 'export-hits'}
for used in re.findall(r"\$\('([^']+)'\)", js):
    assert used in static_ids | dynamic_ids or used.startswith('field-'), f'Missing HTML target: {used}'
assert 'localStorage' not in js and 'buildQr' not in js and 'qrcodegen' not in js
assert "api('issue'" in js and "api('preview'" in js and "api('registry'" in js
assert 'idempotency_key: state.key' in js
assert 'innerHTML' not in js, 'Record data must be inserted as text'
assert 'Демонстрационные данные' not in html and '<small>демо</small>' not in html
for name in ['cargonomica.svg', 'brand-mark.svg']:
    tree = ET.parse(assets / name)
    assert all(el.tag.split('}')[-1] in {'svg', 'path'} for el in tree.iter())
asset = json.loads((assets / 'brand-profile.json').read_text())
assert len(asset['viewBox']) == 4 and asset['paths']
css = (assets / 'app.css').read_text()
for font in ['inter-cyrillic.woff2', 'inter-latin.woff2', 'plex-mono.woff2']:
    assert (assets / 'fonts' / font).is_file()
    assert font in css
assert ':root {' not in css and '\nbody {' not in css
assert '.cargo-app .generator-grid' in css and '.cargo-app .sidebar' in css
print(f'PASS: UI HTML/JS contract ({len(static_ids)} static IDs), assets and CSS scope')
