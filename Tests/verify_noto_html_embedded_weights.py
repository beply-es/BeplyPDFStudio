"""Actual PHP font mapping -> WeasyPrint -> embedded TTF weight contract.

Run with the exact pinned font fixture: BEPDF_CI_FONT_DIR=/... python3 Tests/verify_noto_html_embedded_weights.py
No tenant, database or provider access; output stays in memory.
"""
import hashlib
from io import BytesIO
import json
import os
from pathlib import Path
import re
import subprocess
import zlib

from fontTools.ttLib import TTFont
from weasyprint import HTML

STATIC_NOTO_SHA256 = {
    'NotoSans-Regular.ttf': 'b85c38ecea8a7cfb39c24e395a4007474fa5a4fc864f6ee33309eb4948d232d5',
    'NotoSans-Bold.ttf': 'c976e4b1b99edc88775377fcc21692ca4bfa46b6d6ca6522bfda505b28ff9d6a',
    'NotoSans-Italic.ttf': '36cff144df01309dab648bea71baff9bb074026914afe63aeacc8bc90b67a28b',
    'NotoSans-BoldItalic.ttf': '6edf4227ef0fa846aca70e86a307804ca4401741830f5b3af0f2554abe2b8466',
}


def require_static_noto(root):
    for name, expected in STATIC_NOTO_SHA256.items():
        raw = (root / name).read_bytes()
        if hashlib.sha256(raw).hexdigest() != expected:
            raise AssertionError('PINNED_STATIC_NOTO_REQUIRED: ' + name)
        with TTFont(BytesIO(raw)) as font:
            if ('fvar' in font or font['OS/2'].usWeightClass != (700 if 'Bold' in name else 400)
                    or bool(font['head'].macStyle & 2) != ('Italic' in name)):
                raise AssertionError('STATIC_NOTO_WEIGHT_AND_STYLE_REQUIRED: ' + name)


def embedded_weights(pdf):
    objects = {int(m[1]): m[2] for m in re.finditer(
        rb'(\d+) 0 obj\s*(.*?)endobj', pdf, re.S)}
    weights = []
    for obj in objects.values():
        if not re.search(rb'/Type\s*/FontDescriptor\b', obj):
            continue
        ref = re.search(rb'/FontFile2\s+(\d+) 0 R', obj)
        if ref is None:
            raise AssertionError('EMBEDDED_NOTO_TTF_REQUIRED')
        stream = objects[int(ref[1])]
        raw = stream.split(b'stream\n', 1)[1].rsplit(b'\nendstream', 1)[0]
        if b'/FlateDecode' in stream.split(b'stream', 1)[0]:
            raw = zlib.decompress(raw)
        with TTFont(BytesIO(raw)) as font:
            family = font['name'].getDebugName(1)
            if not family or not family.startswith('Noto Sans'):
                raise AssertionError('NOTO_FAMILY_REQUIRED')
            weights.append(font['OS/2'].usWeightClass)
    if set(weights) != {400, 700}:
        raise AssertionError('REAL_REGULAR_AND_BOLD_REQUIRED: ' + repr(weights))
    return sorted(weights)


def main():
    root = Path(os.environ['BEPDF_CI_FONT_DIR']).resolve()
    require_static_noto(root)
    html = subprocess.check_output([
        'php', str(Path(__file__).with_name('noto-html-weights.php')), str(root)], text=True)
    if html.count('@font-face') != 4 or html.count('font-weight: bold;') != 2:
        raise AssertionError('FOUR_STATIC_NOTO_FACES_REQUIRED')
    pdf = HTML(string=html).write_pdf(uncompressed_pdf=True)
    weights = embedded_weights(pdf)
    # A PDF can contain the word Bold while all glyphs remain Regular.
    mutant = HTML(string=html + '<style>strong {font-weight:400}</style>').write_pdf(
        uncompressed_pdf=True)
    try:
        embedded_weights(mutant)
    except AssertionError as error:
        if not str(error).startswith('REAL_REGULAR_AND_BOLD_REQUIRED:'):
            raise
    else:
        raise AssertionError('REGULAR_ONLY_MUTANT_SURVIVED')
    print(json.dumps({'embeddedWeights': weights, 'regularOnlyMutantRejected': True,
                      'pdfSha256': hashlib.sha256(pdf).hexdigest(), 'localOnly': True}))


if __name__ == '__main__':
    main()
