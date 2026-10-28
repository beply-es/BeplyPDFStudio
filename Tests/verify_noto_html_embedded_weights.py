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
    if hashlib.sha256((root / 'NotoSans-Regular.ttf').read_bytes()).hexdigest() != (
        'bfb7bb691513f12e734dc346c03a03f784912432d7e3fa8e56efcf906fe86b3d'):
        raise AssertionError('PINNED_NOTO_REQUIRED')
    html = subprocess.check_output([
        'php', str(Path(__file__).with_name('noto-html-weights.php')), str(root)], text=True)
    if re.search(r'@font-face\s*\{[^}]*\bfont-weight\s*:', html):
        raise AssertionError('VARIABLE_NOTO_WEIGHT_DESCRIPTOR_MUST_BE_OMITTED')
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
