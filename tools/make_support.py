"""Portable Make helpers. Cleanup refuses source files and paths outside this checkout."""
from __future__ import annotations
import argparse
import glob
from pathlib import Path
import re
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def checked(value: str, *, directory: bool = False, git: str = "git") -> Path:
    lexical = Path(value)
    if not lexical.is_absolute():
        lexical = ROOT / lexical
    resolved = lexical.resolve()
    if resolved == ROOT or not resolved.is_relative_to(ROOT):
        raise ValueError("output must be a strict descendant of the repository")
    relative = resolved.relative_to(ROOT)
    protected = {".git", ".github", "config", "data", "storage", "uploads", "wp-content", "database", "databases", "secrets", "credentials", "backups"}
    if any(part.lower() in protected or part.lower().startswith(".env") for part in relative.parts):
        raise ValueError("repository metadata, configuration and application data are protected")
    tracked = subprocess.check_output(
        [git, "-C", str(ROOT), "ls-files", "-z"], text=True
    ).split("\0")
    for name in filter(None, tracked):
        source = (ROOT / name).resolve()
        if source == resolved or source.is_relative_to(resolved):
            raise ValueError("refusing to overwrite or remove tracked source: " + name)
    if directory and resolved.exists() and not resolved.is_dir():
        raise ValueError("expected an output directory")
    # Validate every existing descendant before any deletion, including symlink targets.
    if resolved.is_dir():
        for child in resolved.rglob("*"):
            if not child.resolve().is_relative_to(ROOT):
                raise ValueError("output contains a symlink escaping the repository")
    return lexical


def main() -> int:
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("command", choices=["help", "guard", "mkdir", "clean", "source-package", "version", "bump"])
    parser.add_argument("paths", nargs="*")
    parser.add_argument("--git", default="git")
    parser.add_argument("--readme")
    args = parser.parse_args()
    if args.command == "help":
        makefile = Path(args.paths[0]) if args.paths else ROOT / "Makefile"
        print("Usage: make <target> [TOOL=/path/to/executable]\n")
        seen = set()
        for line in makefile.read_text(encoding="utf-8-sig").splitlines():
            match = re.match(r"^([A-Za-z][A-Za-z0-9_.-]*):(?!=)(.*)", line)
            if match and match[1] not in seen:
                name, body = match.groups()
                seen.add(name)
                description = body.split("##", 1)[1].strip() if "##" in body else "Run " + name.replace("-", " ")
                print(f"  {name:26} {description}")
        return 0
    if args.command in {"guard", "mkdir", "clean", "source-package"}:
        names = args.paths
        if args.command == "source-package" and len(names) != 1:
            parser.error("source-package needs one archive path")
        paths = []
        for name in names:
            matches = glob.glob(str(ROOT / name), recursive=True) if glob.has_magic(name) else [name]
            paths.extend(checked(match, directory=args.command == "mkdir", git=args.git) for match in matches)
        # All arguments are checked before modifying any path.
        if args.command == "mkdir":
            for path in paths:
                path.mkdir(parents=True, exist_ok=True)
        elif args.command == "clean":
            for path in paths:
                if path.is_symlink() or path.is_file():
                    path.unlink()
                elif path.exists():
                    shutil.rmtree(path)
                print("Removed output:", path.relative_to(ROOT))
        elif args.command == "source-package":
            paths[0].parent.mkdir(parents=True, exist_ok=True)
            subprocess.run([args.git, "-C", str(ROOT), "archive", "--format=zip", "HEAD", "-o", str(paths[0])], check=True)
            print("Committed source snapshot:", paths[0])
        return 0
    if len(args.paths) != 1:
        parser.error("version/bump needs one main PHP file")
    path = ROOT / args.paths[0]
    content = path.read_text(encoding="utf-8")
    match = re.search(r"(?m)^(?:[ \t/*#@]*)Version:[ \t]*(\d+\.\d+\.\d+)[ \t]*$", content)
    if not match:
        raise ValueError("no semantic Version header: " + args.paths[0])
    old = match[1]
    if args.command == "version":
        print(old)
    else:
        parts = old.split(".")
        parts[2] = str(int(parts[2]) + 1)
        new = ".".join(parts)
        content = content[:match.start(1)] + new + content[match.end(1):]
        content = re.sub(r"(define\(\s*['\"][A-Z_]*VERSION['\"],\s*['\"])" + re.escape(old) + r"(['\"]\s*\))", lambda m: m[1] + new + m[2], content)
        if args.readme:
            readme = ROOT / args.readme
            readme_content = readme.read_text(encoding="utf-8")
            stable = re.compile(r"(?m)^(Stable tag:[ \t]*)" + re.escape(old) + r"([ \t]*)$")
            if not stable.search(readme_content):
                raise ValueError("readme stable tag does not match the current version")
            readme.write_text(stable.sub(lambda m: m[1] + new + m[2], readme_content), encoding="utf-8", newline="\n")
        path.write_text(content, encoding="utf-8", newline="\n")
        print(old + " -> " + new)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print("make helper:", error, file=sys.stderr)
        raise SystemExit(1)
