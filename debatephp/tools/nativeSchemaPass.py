"""Reproducible semantic SEO changes, after the native publication-date pass."""

def optimize(source, argument):
	def replace(old, new):
		nonlocal source
		assert source.count(old) == 1, old
		source = source.replace(old, new)

	def remove(name):
		nonlocal source
		start = source.index('\tprotected function ' + name + '(')
		end = source.index('\n\tprotected function ', start + 1)
		source = source[:start] + source[end + 1:]

	if argument:
		remove("wkTakeFirst")
		remove("wkBuildHasPart")
		replace("\t\t\t$hasPart = $this->wkBuildHasPart($args, $maps, $lang);", "\t\t\t$parts = SchemaData::argumentParts($args, $maps, $pv['pageUrl'], $lang,\n\t\t\t\tfn(string $title): string => $this->wkFullUrl($this->canonicalTitle($title)), $this->context);")
		replace("\t\t\t$pageNode = (['@type' => 'WebPage', '@id' => $pv['pageUrl'], 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'isPartOf' => (['@id' => ($this->L($lang, 'urls', 'home') . '#website')]), 'breadcrumb' => (['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'mainEntity' => (['@id' => ($pv['pageUrl'] . '#claim')])]);", "\t\t\t$pageNode = [\n\t\t\t\t'@type' => [1 => 'WebPage', 2 => 'LearningResource'],\n\t\t\t\t'@id' => $pv['pageUrl'],\n\t\t\t\t'url' => $pv['pageUrl'],\n\t\t\t\t'name' => $pv['rawTitle'],\n\t\t\t\t'inLanguage' => $lang,\n\t\t\t\t'isPartOf' => ['@id' => $this->L($lang, 'urls', 'home') . '#website'],\n\t\t\t\t'breadcrumb' => ['@id' => $pv['pageUrl'] . '#breadcrumb'],\n\t\t\t\t'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang),\n\t\t\t\t'mainEntity' => ['@id' => $pv['pageUrl'] . '#claim']\n\t\t\t] + SchemaData::education($lang, 'argument');\n\t\t\tif ($parts !== []) {\n\t\t\t\t$pageNode['hasPart'] = $parts;\n\t\t\t}")
		replace("\t\t\t$claimNode = (['@type' => 'Claim', '@id' => ($pv['pageUrl'] . '#claim'), 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'learningResourceType' => 'argument', 'educationalUse' => 'analysis', 'educationalLevel' => 'higher education', 'audience' => (['@type' => 'EducationalAudience', 'educationalRole' => 'student']), 'isPartOf' => $isPartOf, 'breadcrumb' => (['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang), 'publisher' => (['@id' => ($this->L($lang, 'urls', 'home') . '#organization')])]);", "\t\t\t$claimNode = [\n\t\t\t\t'@type' => 'Claim',\n\t\t\t\t'@id' => $pv['pageUrl'] . '#claim',\n\t\t\t\t'url' => $pv['pageUrl'],\n\t\t\t\t'name' => $pv['rawTitle'],\n\t\t\t\t'inLanguage' => $lang,\n\t\t\t\t'isPartOf' => $isPartOf,\n\t\t\t\t'appearance' => ['@id' => $pv['pageUrl']]\n\t\t\t];")
		replace("\t\t\t\t$summary = Lua::trim(Lua::str((Lua::truth($v362 = (Lua::truth($v363 = $args) ? $args['summary'] : $v363)) ? $v362 : '')));", "\t\t\t\t$summary = NativeText::seoPlainText((string)($args['summary'] ?? ''), $this->context);")
		replace("\t\t\tif ((Lua::len($hasPart) > 0)) {\n\t\t\t\t$claimNode['hasPart'] = $hasPart;\n\t\t\t}\n", "")
	else:
		remove("wkTakeFirst")
		remove("wkSchemaPageLabel")
		remove("wkBuildAnswerNode")
		remove("wkBuildSuggestedAnswers")
		replace("\t\t\t$suggestedAnswer = $this->wkBuildSuggestedAnswers($maps, $lang);\n", "")
		replace("\t\t\t$qaNode = (['@type' => 'QAPage', '@id' => ($pv['pageUrl'] . '#qa'), 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'educationalUse' => ([1 => 'debate', 2 => 'critical thinking']), 'learningResourceType' => 'debate', 'audience' => (['@type' => 'EducationalAudience', 'educationalLevel' => ([1 => 'general public', 2 => 'secondary education'])]), 'isPartOf' => $isPartOf, 'breadcrumb' => (['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang), 'publisher' => (['@id' => ($this->L($lang, 'urls', 'home') . '#organization')]), 'mainEntity' => (['@type' => 'Question', '@id' => ($pv['pageUrl'] . '#question'), 'name' => $pv['rawTitle'], 'text' => $questionText, 'answerCount' => Lua::len($suggestedAnswer), 'suggestedAnswer' => $suggestedAnswer])]);", "\t\t\t$pageNode = [\n\t\t\t\t'@type' => [1 => 'WebPage', 2 => 'LearningResource'],\n\t\t\t\t// Keep the existing graph identifier: related pages already reference it.\n\t\t\t\t'@id' => $pv['pageUrl'] . '#qa',\n\t\t\t\t'url' => $pv['pageUrl'],\n\t\t\t\t'name' => $pv['rawTitle'],\n\t\t\t\t'inLanguage' => $lang,\n\t\t\t\t'isPartOf' => $isPartOf,\n\t\t\t\t'breadcrumb' => ['@id' => $pv['pageUrl'] . '#breadcrumb'],\n\t\t\t\t'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang),\n\t\t\t\t'publisher' => ['@id' => $this->L($lang, 'urls', 'home') . '#organization'],\n\t\t\t\t'mainEntity' => [\n\t\t\t\t\t'@type' => 'Question',\n\t\t\t\t\t'@id' => $pv['pageUrl'] . '#question',\n\t\t\t\t\t'name' => $pv['rawTitle'],\n\t\t\t\t\t'text' => NativeText::seoPlainText($questionText, $this->context) ?: $pv['rawTitle']\n\t\t\t\t]\n\t\t\t] + SchemaData::education($lang, 'debate');\n\t\t\t$parts = SchemaData::sections($maps, ['pro' => 'pro', 'con' => 'con'],\n\t\t\t\t$pv['pageUrl'], $lang, fn(string $title): string => $this->wkFullUrl($title), $this->context);\n\t\t\tif ($parts !== []) {\n\t\t\t\t$pageNode['hasPart'] = $parts;\n\t\t\t}")
		# isRelatedTo is only for products/services; relatedLink takes URLs.
		start = source.index('\tprotected function wkBuildIsRelatedTo(')
		end = source.index('\n\tprotected function ', start + 1)
		source = source[:start] + r'''	protected function wkBuildRelatedLinks($args = null) {
		$out = new Table([]);
		$raw = trim((string)($args['related-debates'] ?? ''));
		foreach (explode($this->ITEM_SEP, $raw) as $part) {
			// Related debates are rendered as wiki links, possibly with a label.
			$title = trim(NativeText::splitOnce($part, '|')[0]);
			if ($title === '') {
				continue;
			}
			$url = $this->wkFullUrl($title);
			if ($url !== '') {
				$out[count($out) + 1] = $url;
			}
		}
		return $out;
	}
''' + source[end:]
		source = source.replace('wkBuildIsRelatedTo', 'wkBuildRelatedLinks')
		source = source.replace('$isRelatedTo', '$relatedLinks').replace("'isRelatedTo'", "'relatedLink'")
		source = source.replace('$qaNode', '$pageNode')
	replace("if (Lua::truth($node)) { return new Table([1 => $node]); }", "if (Lua::truth($node)) { return new Table([1 => SchemaData::reference($node, $this->context)]); }")
	return source
