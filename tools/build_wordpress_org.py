"""Build the documented WordPress upload archive; never publish or change versions."""
from pathlib import Path
import hashlib
import json
import re
import subprocess
import zipfile
from directory_domain import directory_domains

ROOT = Path(__file__).resolve().parents[1]
CONFIG = json.loads((ROOT / 'tools/wordpress-org.json').read_text(encoding='utf-8'))
OUTPUT = ROOT / 'wordpress-org'
EXCLUDED = {'node_modules', 'tests', 'test', '.github', '.git', 'dist', 'wordpress-org', 'tools', 'scripts', '__pycache__', 'coverage', 'codegen', 'API-Docs', '.vscode'}
OMITTED = {'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'babel.config.json', 'vitest.config.mjs', 'webpack.config.js', 'Makefile', 'build.sh', 'AGENTS.md', 'phpunit.xml', 'phpunit.xml.dist', 'phpcs.xml', 'phpcs.xml.dist', 'spec.md'}

def digest(data):
    return hashlib.sha256(data).hexdigest()

def payload(plugin):
    source = ROOT / plugin['root']
    tracked = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).decode('utf-8').split('\0')
    candidates = {ROOT / name for name in tracked if name}
    # Compiled assets are often ignored by Git. Include only the explicitly
    # declared build directory, never a developer's dependency tree.
    for directory in plugin.get('compiled', []):
        folder = source / directory
        if not folder.is_dir():
            raise RuntimeError('Run the documented frontend build first: ' + str(folder))
        candidates.update(p for p in folder.rglob('*') if p.is_file())
    # A clean tracked file is read from Git's index, so checkout line-ending
    # settings cannot silently change a Windows ZIP compared with Linux CI.
    index = {}
    for record in subprocess.check_output(['git', 'ls-files', '--stage', '-z'], cwd=ROOT).decode('utf-8').split('\0'):
        if record:
            meta, name = record.split('\t', 1)
            index[name] = meta.split()[1]
    changed = set(subprocess.check_output(['git', 'diff', '--name-only', '-z'], cwd=ROOT).decode('utf-8').split('\0'))
    result = {}
    provenance = {}
    for path in sorted(candidates):
        if not path.is_file() or not path.is_relative_to(source):
            continue
        relative = path.relative_to(source)
        if EXCLUDED.intersection(relative.parts) or relative.parts[0] == 'vendor' or path.name in OMITTED or any(part.startswith('.') and part != '.htaccess' for part in relative.parts):
            continue
        if path.suffix.lower() in {'.zip', '.log', '.cache', '.bak', '.mhtml', '.ps1', '.sh', '.py', '.map'}:
            continue
        if len(relative.parts) == 1 and path.suffix.lower() == '.md' and path.name not in {'README.md', 'LICENSE.md', 'CHANGELOG.md', 'SECURITY.md', 'COPYRIGHT.md', 'BUILD.md'}:
            continue
        if plugin.get('include') and relative.parts[0] not in plugin['include']:
            continue
        if any(relative.as_posix() == p or relative.as_posix().startswith(p + '/') for p in plugin.get('exclude', [])):
            continue
        key = path.relative_to(ROOT).as_posix()
        if key in index and key not in changed:
            result[relative.as_posix()] = subprocess.check_output(['git', 'cat-file', 'blob', index[key]], cwd=ROOT)
            provenance[relative.as_posix()] = {'source': 'git-index', 'blob': index[key]}
        else:
            result[relative.as_posix()] = path.read_bytes()
            compiled = any(relative.is_relative_to(Path(directory)) for directory in plugin.get('compiled', []))
            provenance[relative.as_posix()] = {'source': 'compiled-build' if compiled else 'worktree-preview'}
    for filename, replacements in plugin.get('replacements', {}).items():
        text = result[filename].decode('utf-8-sig').replace('\r\n', '\n')
        for old, new in replacements:
            if text.count(old) != 1:
                raise RuntimeError('Directory edition transform must match exactly once: ' + filename)
            text = text.replace(old, new, 1)
        result[filename] = text.encode('utf-8')
    if plugin.get('domain_rewrite'):
        old = plugin['domain_rewrite']['from']
        new = plugin['domain_rewrite']['to']
        for filename, data in list(result.items()):
            path = Path(filename)
            if path.suffix == '.php':
                result[filename] = directory_domains(data.decode('utf-8-sig'), old, new).encode('utf-8')
            elif path.suffix == '.json':
                metadata = json.loads(data)
                if isinstance(metadata, dict) and metadata.get('textdomain') == old:
                    metadata['textdomain'] = new
                    result[filename] = (json.dumps(metadata, indent=2) + '\n').encode('utf-8')
            elif path.suffix == '.js':
                # Readable source and webpack's WP-i18n external call only. No
                # option, action, block-name or menu identifiers are changed.
                string = r"(?:'(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\")"
                call = r'(?:\b__|\(0,[A-Za-z0-9_$]+\.__\))'
                pattern = r'(' + call + r'\(\s*' + string + r'\s*,\s*)([\'\"])' + re.escape(old) + r'\2(\s*\))'
                text = re.sub(pattern, lambda match: match[1] + match[2] + new + match[2] + match[3], data.decode('utf-8'))
                result[filename] = text.encode('utf-8')
            if path.name == old + '.pot' or (path.name.startswith(old + '-') and path.suffix in {'.po', '.mo', '.json'}):
                renamed = (path.parent / (new + path.name[len(old):])).as_posix()
                translated = result[filename]
                if path.suffix in {'.pot', '.po'}:
                    translated = translated.replace(('X-Domain: ' + old).encode(), ('X-Domain: ' + new).encode())
                result[renamed] = translated
                provenance[renamed] = provenance[filename] | {'derived_catalog': True}
    required = {plugin['entry'], 'readme.txt', 'LICENSE', 'COPYRIGHT.md', 'BUILD.md'} | set(plugin.get('required', []))
    missing = required - result.keys()
    if missing:
        raise RuntimeError('Upload package missing required files: ' + ', '.join(sorted(missing)))
    entry = result[plugin['entry']].decode('utf-8-sig')
    readme = result['readme.txt'].decode('utf-8-sig')
    version = re.search(r'^\s*\*\s*Version:\s*(\S+)', entry, re.M).group(1)
    if not re.search(r'^Stable tag:\s*' + re.escape(version) + r'\s*$', readme, re.M):
        raise RuntimeError('Stable tag must match the plugin Version header')
    if not re.search(r'^\s*\*\s*Text Domain:\s*' + re.escape(plugin['slug']) + r'\s*$', entry, re.M):
        raise RuntimeError('Upload folder slug and text domain must match')
    # Keep development Markdown in Git, and ship standard plain-text notices.
    result['COPYRIGHT.txt'] = result.pop('COPYRIGHT.md')
    result['BUILD.txt'] = result.pop('BUILD.md')
    provenance['COPYRIGHT.txt'] = provenance.pop('COPYRIGHT.md')
    provenance['BUILD.txt'] = provenance.pop('BUILD.md')
    return result, version, provenance

def main():
    OUTPUT.mkdir(exist_ok=True)
    for name in CONFIG.get('stale_archives', []):
        stale = OUTPUT / name
        if stale.resolve().parent != OUTPUT.resolve() or stale.suffix != '.zip':
            raise RuntimeError('Refusing to remove an unexpected generated archive path')
        stale.unlink(missing_ok=True)
    manifests = []
    for plugin in CONFIG['plugins']:
        files, version, provenance = payload(plugin)
        path = OUTPUT / (plugin['slug'] + '.zip')
        with zipfile.ZipFile(path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for name, data in sorted(files.items()):
                info = zipfile.ZipInfo(plugin['slug'] + '/' + name, (1980, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                archive.writestr(info, data)
        with zipfile.ZipFile(path) as archive:
            if archive.testzip() is not None or len(archive.namelist()) != len(files):
                raise RuntimeError('ZIP validation failed')
        manifest = {'slug': plugin['slug'], 'entry': plugin['entry'], 'source_root': plugin['root'], 'version': version,
                    'archive': path.relative_to(ROOT).as_posix(), 'sha256': digest(path.read_bytes()),
                    'edition': plugin.get('edition', 'directory-candidate'),
                    'files': {name: digest(data) for name, data in sorted(files.items())}, 'input_provenance': provenance,
                    'source_revision': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT).decode().strip(),
                    'directory_transforms': {'domain': plugin.get('domain_rewrite'), 'excluded': plugin.get('exclude', []), 'replaced_files': list(plugin.get('replacements', {}))}}
        manifests.append(manifest)
        print(str(path) + ' SHA256 ' + manifest['sha256'], flush=True)
    (OUTPUT / 'manifest.json').write_text(json.dumps(manifests, indent=2) + '\n', encoding='utf-8')

if __name__ == '__main__':
    main()
