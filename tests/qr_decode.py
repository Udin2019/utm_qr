"""Independent decode of application PNG and SVG rasterized by Inkscape."""
import json
import subprocess
import sys
from pathlib import Path
from xml.etree import ElementTree as ET

import zxingcpp
from PIL import Image

root = Path(sys.argv[1] if len(sys.argv) > 1 else '/artifacts')
manifest = json.loads((root / 'manifest.json').read_text())
count = 0
for profile in manifest:
    stem = profile['stem']
    svg = root / f'{stem}.svg'
    tree = ET.parse(svg)
    outer = tree.getroot()
    extent = float(outer.attrib['viewBox'].split()[2])
    logo = next(child for child in outer if child.tag.split('}')[-1] == 'svg')
    for axis, length in [('x', 'width'), ('y', 'height')]:
        assert abs(float(logo.attrib[axis]) + float(logo.attrib[length]) / 2 - extent / 2) < 1e-9, 'Logo must be exactly centered'
    plate = next(child for child in outer if child.attrib.get('data-role') == 'logo-plate')
    assert plate.attrib['fill'] == '#fff', 'White logo surround required'
    for axis, length in [('x', 'width'), ('y', 'height')]:
        padding = float(logo.attrib[length]) * 0.1
        assert abs(float(logo.attrib[axis]) - float(plate.attrib[axis]) - padding) < 1e-9, 'Surround must be 10% per side'
        assert abs(float(plate.attrib[length]) - float(logo.attrib[length]) - padding * 2) < 1e-9, 'Incorrect plate size'
    for element in tree.iter():
        assert element.tag.split('}')[-1] in {'svg', 'rect', 'path', 'g'}, 'Unexpected SVG element'
        assert all(not k.lower().startswith('on') and 'href' not in k.lower() for k in element.attrib), 'Unsafe attribute'
    for size in (1024, 2048, 4096):
        im = Image.open(root / f'{stem}-{size}.png').convert('RGB')
        assert im.size == (size, size), 'Wrong PNG dimensions'
        result = zxingcpp.read_barcode(im)
        assert result and result.text == profile['url'], f'PNG decode failed: {stem} {size}'
        modules = profile['modules']
        scale = (size // modules) // 2 * 2
        pad = (size - scale * modules) // 2
        assert pad * 2 + scale * modules == size, 'PNG padding must be symmetric'
        assert pad + extent / 2 * scale == size / 2, 'PNG logo must be centered'
        border = pad + 4 * scale
        assert im.crop((0, 0, size, border)).getextrema() == ((255, 255),) * 3, 'Top quiet zone'
        assert im.crop((0, size-border, size, size)).getextrema() == ((255, 255),) * 3, 'Bottom quiet zone'
        assert im.crop((0, 0, border, size)).getextrema() == ((255, 255),) * 3, 'Left quiet zone'
        assert im.crop((size-border, 0, size, size)).getextrema() == ((255, 255),) * 3, 'Right quiet zone'
        for x, y, dark in profile['protected']:
            pixel = im.getpixel((pad+(x+4)*scale+scale//2, pad+(y+4)*scale+scale//2))
            assert (max(pixel) < 80) == dark, f'Function module damaged: {x},{y}'
        count += 1
    raster = root / f'{stem}-inkscape.png'
    subprocess.run(['inkscape', str(svg), '--export-type=png', f'--export-filename={raster}', '--export-width=2048'], check=True, capture_output=True, timeout=30)
    result = zxingcpp.read_barcode(Image.open(raster))
    assert result and result.text == profile['url'], 'Independent SVG decode failed'
    count += 1
print(f'PASS: {count} QR decoding checks, quiet zones, centered logos and protected function modules')
