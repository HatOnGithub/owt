"""Build Angular and copy its production output into the deployable Dolibarr module."""
import os
import argparse
from pathlib import Path
import shutil
import subprocess

root = Path(__file__).resolve().parents[1]
frontend = root / 'frontend'
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--skip-build', action='store_true', help='Deploy bundles already built with npm run build')
parser.add_argument('--skip-install', action='store_true', help='Use dependencies already installed from the current lockfile')
args = parser.parse_args()
if not args.skip_build:
    npm = 'npm.cmd' if os.name == 'nt' else 'npm'
    # A git pull can add decoder dependencies without updating node_modules. Install
    # the locked tree before compiling, including build tools on production hosts.
    # Leave the deployed bundles untouched if either installation or compilation fails.
    if not args.skip_install:
        print('Installing locked frontend dependencies (including build tools)...', flush=True)
        subprocess.run([npm, 'ci', '--include=dev'], cwd=frontend, check=True)
    subprocess.run([npm, 'run', 'build'], cwd=frontend, check=True)
source = frontend / 'dist/owt/browser'
if not (source / 'index.html').is_file():
    raise SystemExit('Angular output is missing. Run npm run build first.')
# This is generated deployment output, not service inventory. Clear obsolete top-level bundle
# names before copying the new index/chunks so packaging cannot retain an old entry point.
target = root / 'dolibarr/owt/spa'
target.mkdir(parents=True, exist_ok=True)
for old in target.iterdir():
    if old.is_file() and old.suffix in {'.js', '.css', '.html', '.txt', '.json', '.map'}:
        old.unlink()
shutil.copytree(source, target, dirs_exist_ok=True)
licenses = frontend / 'dist/owt/3rdpartylicenses.txt'
if licenses.is_file():
    shutil.copyfile(licenses, target / licenses.name)
print('Deployed Angular production bundles into dolibarr/owt/spa')
