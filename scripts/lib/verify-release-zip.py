#!/usr/bin/env python3
"""Verify mp-commerce-promotions production release ZIP contents."""
from __future__ import annotations

import sys
import zipfile

FORBIDDEN_SEGMENTS = frozenset(
    {
        ".git",
        "vendor",
        "node_modules",
        "scripts",
        "tests",
        "docs",
        ".github",
        "build",
        ".phpcs-cache",
        ".phpunit.result.cache",
    }
)

FORBIDDEN_ROOT_FILES = frozenset(
    {
        "composer.json",
        "composer.lock",
        "composer.phar",
        "phpcs.xml.dist",
        "phpunit.xml.dist",
        "README.md",
        ".gitignore",
        ".editorconfig",
        ".cursorignore",
        ".write-test",
    }
)

REQUIRED_ROOT_FILES = frozenset(
    {
        "readme.txt",
        "CHANGELOG.md",
        "LICENSE",
        "uninstall.php",
    }
)

REQUIRED_DIRS = frozenset({"src", "assets", "languages"})


def segment_violations(path: str) -> list[str]:
    parts = [p for p in path.split("/") if p]
    hits = []
    for part in parts:
        if part in FORBIDDEN_SEGMENTS:
            hits.append(part)
    if parts and parts[-1] in FORBIDDEN_ROOT_FILES:
        hits.append(parts[-1])
    if parts:
        leaf = parts[-1]
        if leaf.startswith(".env"):
            hits.append(leaf)
        for suffix in (".log", ".sql", ".sql.gz", ".dump", ".sqlite"):
            if leaf.endswith(suffix):
                hits.append(leaf)
    return hits


def verify(zip_path: str, plugin_slug: str) -> int:
    root_prefix = f"{plugin_slug}/"
    main_file = f"{root_prefix}{plugin_slug}.php"

    with zipfile.ZipFile(zip_path) as zf:
        names = [n for n in zf.namelist() if n]

        if not names:
            print("ERROR: zip is empty", file=sys.stderr)
            return 1

        non_root = [n for n in names if not n.startswith(root_prefix)]
        if non_root:
            print(
                "ERROR: entries must live under",
                root_prefix,
                "examples:",
                non_root[:5],
                file=sys.stderr,
            )
            return 1

        if main_file not in names:
            print(f"ERROR: missing {main_file}", file=sys.stderr)
            return 1

        forbidden_hits: list[str] = []
        for name in names:
            rel = name[len(root_prefix) :] if name.startswith(root_prefix) else name
            if not rel:
                continue
            for hit in segment_violations(rel):
                forbidden_hits.append(f"{name} ({hit})")

        if forbidden_hits:
            print("ERROR: zip contains forbidden paths:", file=sys.stderr)
            for line in forbidden_hits[:20]:
                print(f"  - {line}", file=sys.stderr)
            if len(forbidden_hits) > 20:
                print(f"  ... and {len(forbidden_hits) - 20} more", file=sys.stderr)
            return 1

        present_roots = {
            rel.split("/")[0]
            for name in names
            if name.startswith(root_prefix)
            for rel in [name[len(root_prefix) :]]
            if rel and "/" not in rel.rstrip("/")
        }
        # Also collect first path segment for nested entries.
        dir_segments = set()
        for name in names:
            rel = name[len(root_prefix) :]
            if not rel:
                continue
            dir_segments.add(rel.split("/")[0])

        missing_dirs = REQUIRED_DIRS - dir_segments
        if missing_dirs:
            print(
                "ERROR: zip missing required directories:",
                ", ".join(sorted(missing_dirs)),
                file=sys.stderr,
            )
            return 1

        missing_files = []
        for req in REQUIRED_ROOT_FILES:
            if f"{root_prefix}{req}" not in names:
                missing_files.append(req)
        if missing_files:
            print(
                "ERROR: zip missing required files:",
                ", ".join(sorted(missing_files)),
                file=sys.stderr,
            )
            return 1

        src_entries = sum(1 for n in names if n.startswith(f"{root_prefix}src/"))
        if src_entries < 1:
            print("ERROR: zip has no files under src/", file=sys.stderr)
            return 1

        print(f"OK: {len(names)} entries under {root_prefix}")
        print(f"    main: {main_file}")
        print(f"    src/: {src_entries} paths")
        print(
            "    forbidden segments absent:",
            ", ".join(sorted(FORBIDDEN_SEGMENTS)),
        )
        return 0


def main() -> int:
    if len(sys.argv) != 3:
        print(f"usage: {sys.argv[0]} ZIP_PATH PLUGIN_SLUG", file=sys.stderr)
        return 2
    return verify(sys.argv[1], sys.argv[2])


if __name__ == "__main__":
    sys.exit(main())
