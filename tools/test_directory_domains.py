"""Protect directory-edition translation changes without renaming persistent plugin IDs."""
import importlib.util
from pathlib import Path

path = Path(__file__).with_name('directory_domain.py')
spec = importlib.util.spec_from_file_location('wordpress_package', path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
source = '''<?php
/** Text Domain: legacy-domain */
$menu = 'legacy-domain';
define('PUBLIC_PLUGIN_ID', 'legacy-domain');
__('Label', 'legacy-domain');
_x('Label', 'context', 'legacy-domain');
load_plugin_textdomain('legacy-domain', false, 'languages');
add_menu_page('Title', 'Label', 'manage_options', 'legacy-domain', 'callback');
?>
<input placeholder="<?php esc_attr_e('Hint', 'legacy-domain'); ?>">
<script>const storedSetting = 'legacy-domain';</script>
'''
result = module.directory_domains(source, 'legacy-domain', 'lolisoft-directory-candidate')
for required in [
    'Text Domain: lolisoft-directory-candidate',
    "__('Label', 'lolisoft-directory-candidate')",
    "_x('Label', 'context', 'lolisoft-directory-candidate')",
    "load_plugin_textdomain('lolisoft-directory-candidate', false, 'languages')",
    "esc_attr_e('Hint', 'lolisoft-directory-candidate')",
    "$menu = 'legacy-domain'",
    "define('PUBLIC_PLUGIN_ID', 'legacy-domain')",
    "'manage_options', 'legacy-domain', 'callback'",
    "const storedSetting = 'legacy-domain'",
]:
    if required not in result:
        raise AssertionError('Domain remapping changed its contract: ' + required)
assert module.directory_domains(result, 'legacy-domain', 'lolisoft-directory-candidate') == result
print('Translation headers/calls/HTML attributes remapped; public IDs/menu/settings preserved; second pass stable.')
