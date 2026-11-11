"""Resize the owner's official assets; never pad, recolor, flatten logos or redraw trophy artwork.

Run with Python + Pillow. --check validates committed derivatives without writing.
The full-size originals are archived outside Vite's source/public directories.
Logos: transparent horizontal PNGs. Trophies: the owner's vertical JPEG artwork on white
(supplied 2026-11-11), resized in proportion with their white kept as supplied.
Partner logos (supplied 2026-11-11): transparent PNGs; only their fully transparent margin
is removed, then they are sized to a common height.
"""

import argparse
import hashlib
import io
import json
from pathlib import Path

from PIL import Image


REPO = Path(__file__).resolve().parents[2]
ARCHIVE = REPO / 'docs/design/brand-originals'
OUTPUT = REPO / 'frontend/src/assets/brand/official'
MANIFEST = ARCHIVE / 'manifest.json'

# The verified official PNGs are ~96 megapixels. Open one at a time.
Image.MAX_IMAGE_PIXELS = 110_000_000

# Derivative widths: horizontal logos, and vertical trophies shown up to ~22rem wide.
WIDTHS = {'logo': [400, 800], 'trophy': [400, 800]}
# Partner logos are shown at a common height (up to 80 CSS px), so they are sized by height.
PARTNER_HEIGHT = 160
# Transparent artwork: lossless, keeping exact RGBA pixels (logos and partner logos).
TRANSPARENT = {'logo', 'partner'}


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def sizes(source, image):
    if source['kind'] == 'partner':
        return [(round(image.width * PARTNER_HEIGHT / image.height), PARTNER_HEIGHT)]
    return [(width, round(image.height * width / image.width)) for width in WIDTHS[source['kind']]]


def prepare(check):
    manifest = json.loads(MANIFEST.read_text(encoding='utf-8'))
    derivatives = 0
    for source in manifest['sources']:
        original = (ARCHIVE / source['file']).read_bytes()
        assert sha256(original) == source['sha256'], source['file']
        with Image.open(io.BytesIO(original)) as image:
            transparent = source['kind'] in TRANSPARENT
            image = image.convert('RGBA' if transparent else 'RGB')
            if transparent:
                assert image.getchannel('A').getextrema() == (0, 255), source['file']
            source['width'], source['height'] = image.size
            source['transparent'] = transparent
            if source['kind'] == 'partner':
                # Only the fully transparent margin is removed; every visible pixel is kept.
                box = image.getchannel('A').getbbox()
                source['trimmed_to'] = list(box)
                image = image.crop(box)
            generated = []
            for width, height in sizes(source, image):
                resized = image.resize((width, height), Image.Resampling.LANCZOS)
                options = {'lossless': True, 'exact': True, 'method': 6} if transparent else {'quality': 88, 'method': 6}
                buffer = io.BytesIO()
                resized.save(buffer, format='WEBP', **options)
                data = buffer.getvalue()
                filename = f"{source['kind']}-{source['key']}-{width}.webp"
                path = OUTPUT / filename
                if check:
                    assert path.read_bytes() == data, filename
                else:
                    OUTPUT.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(data)
                with Image.open(io.BytesIO(data)) as encoded:
                    assert encoded.size == resized.size
                    if transparent:
                        assert encoded.convert('RGBA').tobytes() == resized.tobytes(), filename
                    else:
                        assert 'A' not in encoded.getbands(), filename
                generated.append({'file': path.relative_to(REPO).as_posix(), 'width': width, 'height': height, 'bytes': len(data), 'sha256': sha256(data)})
            derivatives += len(generated)
            if check:
                assert source['derivatives'] == generated, source['file']
            else:
                source['derivatives'] = generated
    if not check:
        MANIFEST.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(f"{'Verified' if check else 'Prepared'} {len(manifest['sources'])} originals and {derivatives} web derivatives; transparent artwork uses lossless WebP with exact RGBA pixels.")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    prepare(parser.parse_args().check)
