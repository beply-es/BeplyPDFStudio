#!/usr/bin/env python3
"""Noto Sans used by runtime and the framed-report CI must share immutable sources."""
import json
import hashlib
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / 'scripts/install-fonts.sh'
WORKFLOW = ROOT / '.github/workflows/tests.yml'
MANIFEST = ROOT / 'Assets/Fonts/fonts.json'
NOTO_REGULAR_SHA256 = 'bfb7bb691513f12e734dc346c03a03f784912432d7e3fa8e56efcf906fe86b3d'
NOTO_ITALIC_SHA256 = '58e6e0ebd1931b29a365aa2d3e2ee9a9e831a3af7cf3ad1462d4e72154f0b291'


class NotoFontSourceContract(unittest.TestCase):
    def test_runtime_font_files_use_the_same_immutable_revision_as_ci(self):
        script = INSTALLER.read_text()
        ci = WORKFLOW.read_text()
        paths = re.findall(r'^https://raw\.githubusercontent\.com/google/fonts/([^/]+)/ofl/notosans/([^|]+)\|(NotoSans-[^|]+)\|ofl/notosans$', script, re.M)
        self.assertEqual({p[2] for p in paths}, {'NotoSans-Regular.ttf', 'NotoSans-Italic.ttf'})
        revisions = {p[0] for p in paths}
        self.assertEqual(len(revisions), 1)
        revision = revisions.pop()
        self.assertRegex(revision, r'^[a-f0-9]{40}$')
        self.assertIn('google/fonts/' + revision + '/ofl/notosans/NotoSans%5Bwdth%2Cwght%5D.ttf', ci)
        self.assertIn('RAW_NOTO="https://raw.githubusercontent.com/google/fonts/' + revision + '"', script)
        self.assertIn(NOTO_REGULAR_SHA256, script)
        self.assertIn(NOTO_ITALIC_SHA256, script)

    def test_manifest_keeps_regular_and_italic_filenames(self):
        entries = [e for e in json.loads(MANIFEST.read_text()) if e['family'] == 'Noto Sans']
        self.assertEqual(len(entries), 1)
        files = entries[0]['files']
        self.assertEqual(Path(files['regular']).name, 'NotoSans-Regular.ttf')
        self.assertEqual(Path(files['italic']).name, 'NotoSans-Italic.ttf')
        self.assertEqual(files['bold'], files['regular'])
        self.assertEqual(files['bolditalic'], files['italic'])

    def test_installer_fetches_fonts_and_license_from_pinned_source(self):
        # Exercise the installer with an isolated destination and deterministic network.
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'scripts').mkdir()
            (root / 'bin').mkdir()
            font_dir = root / 'fonts'
            log = root / 'requests'
            script = INSTALLER.read_text().replace(
                'FONT_DIR="/usr/local/share/fonts/beplypdf"', f'FONT_DIR="{font_dir}"')
            fixture_hash = hashlib.sha256(b'x' * 2048).hexdigest()
            script = script.replace(NOTO_REGULAR_SHA256, fixture_hash).replace(NOTO_ITALIC_SHA256, fixture_hash)
            before, table = script.split("done <<'FONTDATA'\n", 1)
            rows, after = table.split('\nFONTDATA', 1)
            rows = '\n'.join(row for row in rows.splitlines()
                             if '|ofl/notosans' in row or '|Roboto-Regular.ttf|' in row)
            (root / 'scripts/install-fonts.sh').write_text(before + "done <<'FONTDATA'\n" + rows + '\nFONTDATA' + after)
            curl = root / 'bin/curl'
            curl.write_text('''#!/usr/bin/env python3
import os, sys
from pathlib import Path
args = sys.argv[1:]
url = args[-1]
out = Path(args[args.index('-o') + 1])
with open(os.environ['REQUEST_LOG'], 'a') as log:
    log.write(url + '\\n')
out.write_bytes(b'license' if url.endswith('OFL.txt') else (b'y' if os.environ.get('CORRUPT_NOTO') and '/ofl/notosans/' in url else b'x') * 2048)
''')
            curl.chmod(0o755)
            cache = root / 'bin/fc-cache'
            cache.write_text('#!/bin/sh\nexit 0\n')
            cache.chmod(0o755)
            env = dict(os.environ, PATH=str(root / 'bin') + os.pathsep + os.environ['PATH'], REQUEST_LOG=str(log))
            subprocess.run(['bash', str(root / 'scripts/install-fonts.sh')], env=env, check=True, capture_output=True, text=True)
            urls = log.read_text().splitlines()
            noto_urls = [url for url in urls if '/ofl/notosans/' in url]
            self.assertEqual(len(noto_urls), 3)
            self.assertTrue(all('/2984c575fdce412ee02b2baaba67672b9a9434d8/' in url for url in noto_urls))
            self.assertTrue(any(url.endswith('/ofl/notosans/OFL.txt') for url in noto_urls))
            self.assertTrue(any('/main/ofl/roboto/OFL.txt' in url for url in urls))
            self.assertEqual((font_dir / 'NotoSans-Regular.ttf').stat().st_size, 2048)
            self.assertEqual((font_dir / 'NotoSans-Italic.ttf').stat().st_size, 2048)
            self.assertEqual((font_dir / 'licenses/notosans/OFL.txt').read_bytes(), b'license')
            # A corrupt replacement must not overwrite a previously installed font.
            env['CORRUPT_NOTO'] = '1'
            result = subprocess.run(['bash', str(root / 'scripts/install-fonts.sh')], env=env, check=True, capture_output=True, text=True)
            self.assertIn('WARNING: no se pudo descargar NotoSans-Regular.ttf', result.stdout)
            self.assertIn('WARNING: no se pudo descargar NotoSans-Italic.ttf', result.stdout)
            self.assertEqual((font_dir / 'NotoSans-Regular.ttf').read_bytes(), b'x' * 2048)
            self.assertEqual((font_dir / 'NotoSans-Italic.ttf').read_bytes(), b'x' * 2048)
            self.assertFalse((font_dir / 'NotoSans-Regular.ttf.part').exists())
            self.assertFalse((font_dir / 'NotoSans-Italic.ttf.part').exists())

if __name__ == '__main__':
    unittest.main()
