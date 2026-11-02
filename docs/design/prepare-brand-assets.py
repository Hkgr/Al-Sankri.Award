"""Resize the owner's official assets; never crop, recolor, flatten logos or remove trophy backgrounds.

Run with Python + Pillow. --check validates committed derivatives without writing.
The full-size originals are archived outside Vite's source/public directories.
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


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def prepare(check):
    manifest = json.loads(MANIFEST.read_text(encoding='utf-8'))
    for source in manifest['sources']:
        original = (ARCHIVE / source['file']).read_bytes()
        assert sha256(original) == source['sha256'], source['file']
        with Image.open(io.BytesIO(original)) as image:
            logo = source['kind'] == 'logo'
            image = image.convert('RGBA' if logo else 'RGB')
            alpha = image.getchannel('A').getextrema() if logo else None
            if logo:
                assert alpha == (0, 255), source['file']
            source['width'], source['height'] = image.size
            source['transparent'] = logo
            generated = []
            for width in ([400, 800] if logo else [480, 960]):
                height = round(image.height * width / image.width)
                resized = image.resize((width, height), Image.Resampling.LANCZOS)
                options = {'lossless': True, 'exact': True, 'method': 6} if logo else {'quality': 88, 'method': 6}
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
                    if logo:
                        assert encoded.convert('RGBA').tobytes() == resized.tobytes(), filename
                    else:
                        assert 'A' not in encoded.getbands(), filename
                generated.append({'file': path.relative_to(REPO).as_posix(), 'width': width, 'height': height, 'bytes': len(data), 'sha256': sha256(data)})
            if check:
                assert source['derivatives'] == generated, source['file']
            else:
                source['derivatives'] = generated
    if not check:
        MANIFEST.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(f"{'Verified' if check else 'Prepared'} seven originals and fourteen web derivatives; logos use lossless WebP with exact RGBA pixels.")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    prepare(parser.parse_args().check)
