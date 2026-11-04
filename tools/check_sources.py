"""Portable syntax checks for version-controlled application sources."""
from pathlib import Path
import ast
import json
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
files = subprocess.check_output(["git", "ls-files", "-z"], cwd=root).decode().split("\0")
failed = 0
checked = 0
for name in files:
    path = root / name
    if not path.is_file() or name.startswith((".github/skills/", "dist/", "vendor/", "node_modules/")):
        continue
    try:
        if path.suffix == ".php":
            result = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
            if result.returncode:
                raise ValueError(result.stdout + result.stderr)
        elif path.suffix == ".py":
            ast.parse(path.read_text(encoding="utf-8-sig"), filename=name)
        elif path.suffix == ".json" and not path.name.startswith("tsconfig") and ".vscode/" not in name:
            json.loads(path.read_text(encoding="utf-8-sig"))
        else:
            continue
        checked += 1
    except Exception as exc:
        print(f"{name}: {exc}", file=sys.stderr)
        failed += 1
print(f"Syntax checked {checked} tracked PHP/Python/JSON files; {failed} errors. This is not an integration test.")
sys.exit(bool(failed))
