#!/usr/bin/env python3
"""Check canonical messages, technical identifiers, parameters and browser references."""
import json
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]

def unique(pairs):
	result = {}
	for key, value in pairs:
		assert key not in result, 'Duplicate JSON key: ' + key
		result[key] = value
	return result

def read(path):
	return json.loads((root / path).read_text(), object_pairs_hook=unique)

catalogs = {language: read('i18n/' + language + '.json') for language in ['en', 'fr', 'qqq']}
keys = set(catalogs['en']) - {'@metadata'}
for language in ['fr', 'qqq']:
	assert set(catalogs[language]) - {'@metadata'} == keys, 'Message key mismatch: ' + language
for key in keys:
	for language in ['en', 'fr']:
		assert isinstance(catalogs[language][key], str), (language, key)
	assert set(re.findall(r'\$[1-9][0-9]*', catalogs['en'][key])) == set(re.findall(r'\$[1-9][0-9]*', catalogs['fr'][key])), 'Parameter mismatch: ' + key

mapping = read('resources/i18n-map.json')
names = read('resources/wiki-names.json')
entries = {f'{component}.{section}.{key}': entry
	for component, sections in mapping['entries'].items()
	for section, items in sections.items() for key, entry in items.items()}
unresolved = {}
for path, entry in entries.items():
	assert ('message' in entry) != ('wiki' in entry), path
	if 'message' in entry:
		assert entry['message'] in keys, path
	else:
		assert entry['wiki'] in names['rendering'], path
	if 'legacy_alias' in entry and entry['legacy_alias'][1:] not in entries:
		unresolved[path] = entry['legacy_alias']
# Preserve this upstream value in the frozen Lua oracle; reject any additional broken alias.
assert unresolved == {'Argument.seo.breadcrumb_home_label': '@Debate.seo.breadcrumb_home_label'}, unresolved
assert set(names['site']['en']) == set(names['site']['fr'])
assert not keys.intersection(names['site']['en']), 'Technical names duplicated in the editable UI catalogue'
for language in ['en', 'fr', 'qqq']:
	compat = read('i18n/compat/' + language + '.json')
	assert not keys.intersection(set(compat) - {'@metadata'}), 'Duplicate message source'
for alias, canonical in mapping['browser_aliases'].items():
	assert canonical in keys and alias not in keys
	for language in ['en', 'fr']:
		assert read('i18n/compat/' + language + '.json')[alias] == catalogs[language][canonical]

def browser_keys(text):
	return set(re.findall(r'(?:wkMsgD?|wkParam(?:Fast)?|wkTpl(?:Fast)?|wkForm(?:Fast)?|msgD|formatSectionText|formatFieldSummary)\(\s*[\'\"](wk-[^\'\"]+)', text)) | \
		set(re.findall(r'\w*Key:\s*[\'\"](wk-[^\'\"]+)', text)) | \
		set(re.findall(r'[\'\"](wk-summary-[^\'\"]+)', text))

used = set()
for path in (root / 'modules').rglob('*.js'):
	used.update(browser_keys(path.read_text()))
assert not used - keys - names['site']['en'].keys() - mapping['browser_aliases'].keys(), 'Undefined browser message'
manifest = read('extension.json')
expected = sorted([key for key in keys if key.startswith('wk-')] + list(mapping['browser_aliases']))
modules = manifest['ResourceModules']
assert modules['ext.wikidebates.common.i18n']['messages'] == [key for key in expected if not key.startswith('wk-summary-')]
assert modules['ext.wikidebates.features.editForm']['messages'] == [key for key in expected if key.startswith('wk-summary-')]
for name in ['ext.wikidebates.common.i18n', 'ext.wikidebates.features.editForm']:
	assert modules[name]['class'] == 'MediaWiki\\Extension\\Wikidebates\\I18nModule', name
for language in ['en', 'fr']:
	assert modules['ext.wikidebates.i18n.' + language]['messages'] == expected, 'Legacy module must remain complete'

def available_messages(name, seen=None):
	seen = set() if seen is None else seen
	if name in seen or name not in modules:
		return set()
	seen.add(name)
	module = modules[name]
	result = set(module.get('messages', []))
	for dependency in module.get('dependencies', []):
		result.update(available_messages(dependency, seen))
	return result

for name, module in modules.items():
	for file in module.get('scripts', []):
		references = browser_keys((root / manifest['ResourceFileModulePaths']['localBasePath'] / file).read_text())
		missing = references - names['site']['en'].keys() - available_messages(name)
		assert not missing, (name, file, 'Messages unavailable when the script executes', sorted(missing))
print(f'OK: {len(keys)} documented messages, {len(entries)} legacy entries, {len(used)} browser references, per-module availability, key/parameter parity, aliases and unique JSON keys')
