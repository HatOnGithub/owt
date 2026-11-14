"""Explicit one-time import into a fresh service entity. Does not write Dolibarr stock."""
import argparse
import json
import os
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('state', type=Path, help='A backed-up schema-2 state.json')
parser.add_argument('--entity', type=int, required=True)
parser.add_argument('--user', type=int, required=True, help='Dolibarr administrator ID for audit')
args = parser.parse_args()
if args.entity < 1 or args.user < 1:
    parser.error('Entity and user must be positive')
key = os.environ.get('OWT_SERVICE_API_KEY', '')
base = os.environ.get('OWT_SERVICE_URL', '').rstrip('/')
if len(key) < 32 or not base.startswith(('http://', 'https://')):
    parser.error('Set OWT_SERVICE_URL and OWT_SERVICE_API_KEY in the environment')
state = json.loads(args.state.read_text(encoding='utf-8-sig'))
headers = {'Content-Type': 'application/json', 'X-Api-Key': key, 'X-Owt-Entity': str(args.entity),
           'X-Owt-User': str(args.user), 'X-Owt-Read': '1', 'X-Owt-Manage': '1', 'X-Owt-Admin': '1', 'X-Owt-Stock': '1'}
request = Request(base + '/api/inventory/import', data=json.dumps({'state': state}).encode(), headers=headers)
try:
    with urlopen(request, timeout=30) as response:
        json.load(response)
except HTTPError as error:
    raise SystemExit(f'Import rejected ({error.code}): {error.read().decode()}') from None
print(f'Imported schema-2 inventory into entity {args.entity}. Native stock unchanged.')
