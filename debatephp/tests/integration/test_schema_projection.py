"""Positive and mutation tests for the explicit Lua/native graph projection."""
import copy
import unittest
from schema_projection import compare_documents, education

def fixture():
	u = 'https://example.test/wiki/Debate'
	claim = 'https://example.test/wiki/Argument#claim'
	old = {'@context': 'https://schema.org', '@graph': [
		{'@type': 'Organization', '@id': 'https://example.test/#organization', 'name': 'Wiki'},
		{'@type': 'QAPage', '@id': u + '#qa', 'url': u, 'name': 'Debate?', 'inLanguage': 'en',
			'citation': [{'@type': 'Book', 'name': 'Reference'}], 'mainEntity': {
				'@type': 'Question', '@id': u + '#question', 'name': 'Debate?', 'text': 'Debate?',
				'answerCount': 1, 'suggestedAnswer': [{'@type': 'Answer', 'about': {'@id': claim}}]}}
	]}
	new = copy.deepcopy(old)
	p = new['@graph'][1]
	p['@type'] = ['WebPage', 'LearningResource']
	p.update(education('en', True))
	p['author'] = {'@type': 'Organization', 'name': 'Wikidebates contributors'}
	p['creditText'] = 'Wikidebates contributors'
	del p['mainEntity']['answerCount'], p['mainEntity']['suggestedAnswer']
	p['hasPart'] = [{'@type': 'WebPageElement', '@id': u + '#schema-pro-section', 'name': 'Arguments for',
		'mainEntity': {'@type': 'ItemList', '@id': u + '#schema-pro-list', 'name': 'Arguments for',
			'numberOfItems': 1, 'itemListElement': [{'@type': 'ListItem', 'position': 1, 'name': 'Argument',
				'item': {'@type': 'Claim', '@id': claim}}]}}]
	return old, new

class ProjectionTests(unittest.TestCase):
	def test_allowed(self):
		old, new = fixture()
		compare_documents(old, new)
		old['@graph'][1]['isRelatedTo'] = [{'@id': 'https://example.test/wiki/Related#qa'}]
		new['@graph'][1]['relatedLink'] = ['https://example.test/wiki/Related', 'https://example.test/wiki/Another']
		compare_documents(old, new)

	def test_mutations_rejected(self):
		mutations = [
			lambda p: p.update(name='Wrong page'),
			lambda p: p.update(extra='Unknown field'),
			lambda p: p.update(citation=[]),
			lambda p: p.update(educationalLevel=['Wrong level']),
			lambda p: p.update(hasPart=[]),
			lambda p: p['mainEntity'].update(text='Different question'),
			lambda p: p['hasPart'][0]['mainEntity'].update(numberOfItems=2),
			lambda p: p['hasPart'][0]['mainEntity']['itemListElement'][0].update(position=2),
			lambda p: p['hasPart'][0]['mainEntity']['itemListElement'][0]['item'].update({'@id': 'https://example.test/wiki/Other#claim'})
		]
		for i, mutate in enumerate(mutations):
			with self.subTest(mutation=i):
				old, new = fixture()
				mutate(new['@graph'][1])
				with self.assertRaises(ValueError):
					compare_documents(old, new)

if __name__ == '__main__':
	unittest.main()
