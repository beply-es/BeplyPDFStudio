"""Actual Core/applyFont/Cezpdf/BeplyPdfDraw: static weights and subsets.

Requires BEPDF_CI_CORE_DIR, BEPDF_CI_FONT_DIR and an owned BEPDF_CI_SCRATCH.
No database, Kernel, customer documents or network calls.
"""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

from verify_noto_html_embedded_weights import embedded_weights, require_static_noto


def require_small_static_pdf(pdf):
    if len(pdf) >= 100_000:
        raise AssertionError('NOTO_SUBSETS_REQUIRED: ' + str(len(pdf)))
    weights = embedded_weights(pdf)
    if weights != [400, 400, 700, 700]:
        raise AssertionError('FOUR_STATIC_NOTO_FACES_REQUIRED: ' + repr(weights))
    if pdf.count(b'AAAAAD+NotoSans-') < 4:
        raise AssertionError('CEZPDF_SUBSET_NAMES_REQUIRED')
    extracted = subprocess.run(
        ['pdftotext', '-enc', 'UTF-8', '-', '-'], input=pdf,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True, timeout=30)
    text = ' '.join(extracted.stdout.decode('utf-8').split())
    for expected in ['España', 'ñ', '1.234,56 €', 'condición',
                     'FACTURA Y TOTAL', 'DrawBold: cabecera']:
        if expected not in text:
            raise AssertionError('NOTO_TEXT_EXTRACTION_REQUIRED: ' + expected)
    return weights


def main():
    fonts = Path(os.environ['BEPDF_CI_FONT_DIR']).resolve()
    require_static_noto(fonts)
    with tempfile.TemporaryDirectory(dir=os.environ['BEPDF_CI_SCRATCH']) as scratch:
        command = ['php', str(Path(__file__).with_name('noto-cezpdf-fonts.php')),
                   os.environ['BEPDF_CI_CORE_DIR'], str(fonts), scratch]
        pdf = subprocess.check_output(command)
        weights = require_small_static_pdf(pdf)
        for mutant in ['regular-only', 'no-subset', 'bad-text']:
            rejected = False
            try:
                require_small_static_pdf(subprocess.check_output(command + [mutant]))
            except AssertionError:
                rejected = True
            if not rejected:
                raise AssertionError('NOTO_MUTANT_SURVIVED: ' + mutant)
    print(json.dumps({'weights': weights, 'bytes': len(pdf), 'mutantsRejected': 3,
                      'accentedTextExtracted': True,
                      'pdfSha256': hashlib.sha256(pdf).hexdigest(), 'localOnly': True}))


if __name__ == '__main__':
    main()
