"""Rewrite translation domains with PHP's tokenizer, preserving stored identifiers."""
import json
import re
import subprocess

def directory_domains(text, old, new):
    """Change literal translation domains; preserve menu, option, and hook identifiers."""
    functions = {'__', '_e', '_x', '_n', '_nx', '_ex', '_n_noop', '_nx_noop', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', 'esc_html_x', 'esc_attr_x', 'load_plugin_textdomain', 'wp_set_script_translations'}
    # PHP's tokenizer understands PHP embedded inside quoted HTML attributes.
    # A generic text/regex lexer would miss those literal domains.
    process = subprocess.run(['php', '-r', 'echo json_encode(token_get_all(stream_get_contents(STDIN)), JSON_THROW_ON_ERROR);'], input=text.encode('utf-8'), capture_output=True, check=True)
    tokens = json.loads(process.stdout)
    stack, previous, result = [], '', []
    for item in tokens:
        token = item[1] if isinstance(item, list) else item
        if token == '(':
            stack.append(previous)
        elif token == ')' and stack:
            stack.pop()
        if token in {"'" + old + "'", '"' + old + '"'} and stack and stack[-1] in functions:
            token = token[0] + new + token[-1]
        elif token.startswith('/*'):
            token = re.sub(r'(Text Domain:\s*)' + re.escape(old) + r'\b', lambda match: match[1] + new, token)
        result.append(token)
        if not token.isspace() and not token.startswith(('//', '/*')):
            previous = token
    return ''.join(result)
