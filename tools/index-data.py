#!/usr/bin/env python3
"""Write _files.json into each data root: the list of files the app can load from it.

Usage:  tools/index-data.py [ROOT ...]

With no arguments, indexes every local root named in config.json (or
config.example.json). Rerun after adding, removing or moving data files.
Roots that don't exist on this machine are skipped.
"""
import json
import os
import sys

APP_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LISTING = '_files.json'


def configured_roots():
    for name in ('config.json', 'config.example.json'):
        path = os.path.join(APP_DIR, name)
        if os.path.exists(path):
            with open(path) as f:
                roots = json.load(f).get('roots', [])
            break
    else:
        roots = ['data/samples/']
    out = []
    for r in roots:
        if isinstance(r, dict):
            if r.get('indexed') is False:
                continue                       # unindexed by definition
            r = r['url']
        if '://' not in r:                     # remote roots are indexed where they're served
            out.append(os.path.join(APP_DIR, r))
    return out


def index(root):
    files = []
    for dirpath, dirnames, filenames in os.walk(root, followlinks=True):
        dirnames[:] = sorted(d for d in dirnames if not d.startswith('.'))
        for fn in filenames:
            if fn.startswith('.') or fn == LISTING:
                continue
            rel = os.path.relpath(os.path.join(dirpath, fn), root)
            files.append(rel.replace(os.sep, '/'))
    files.sort()
    with open(os.path.join(root, LISTING), 'w') as f:
        json.dump(files, f, indent=0)
        f.write('\n')
    return files


def main():
    roots = sys.argv[1:] or configured_roots()
    for root in roots:
        if not os.path.isdir(root):
            print(f'skip   {root}  (not on this machine)')
            continue
        files = index(root)
        print(f'index  {root}  {len(files)} files')


if __name__ == '__main__':
    main()
