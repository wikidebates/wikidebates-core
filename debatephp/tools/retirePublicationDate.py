"""Remove superseded editorial date logic from the immutable Lua-derived renderers."""

def optimize(source, argument):
	def replace(old, new=''):
		nonlocal source
		assert source.count(old) == 1, old
		source = source.replace(old, new)

	start = source.index('\tprotected function getPageCreation(')
	end = source.index('\tprotected function ', start + 1)
	source = source[:start] + source[end:]
	replace(r'''		if ((!(Lua::truth($args['creation-date'])) || (Lua::trim($args['creation-date']) === ''))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'creation_date_missing') . ']]'));
		}
''')
	if argument:
		replace(r'''			$today = $this->context->currentDate();
			$creationDate = Lua::trim(Lua::str((Lua::truth($v186 = $args['creation-date']) ? $v186 : '')));
			if (($creationDate === $today)) {''', r'''			// Keep the same-day initialization grace period, using the stored revision.
			$publicationDate = $this->context->publicationDate();
			if ($publicationDate === null || substr($publicationDate, 0, 10) === $this->context->currentDate()) {''')
		replace(r'''			$creationDate = $this->getPageCreation($pv, $args);
''')
		# The collective page credit was introduced after the Lua snapshot.
		replace("			$claimNode = (['@type' => 'Claim',", r'''			// Credit collective page authors without attributing the Claim to them.
			$credit = $this->seoL($lang, 'author');
			$pageNode['creditText'] = $credit;
			$pageNode['author'] = $this->jsonNode(['@type' => 'Organization', 'name' => $credit]);
			$pageNode['publisher'] = ['@id' => ($this->L($lang, 'urls', 'home') . '#organization')];
			$claimNode = (['@type' => 'Claim',''')
		replace(r'''			if (Lua::truth($creationDate)) {
				$claimNode['datePublished'] = $creationDate;
			}
''')
	else:
		replace(r'''			$pageCreation = $this->getPageCreation($pv, $args);
''')
		replace(r'''			if ((Lua::truth($pageCreation) && ($pageCreation !== ''))) {
				$qaNode['mainEntity']['datePublished'] = $pageCreation;
			}
''', r'''			// Credit the collective as an author of the page, not of its arguments.
			$credit = $this->seoL($lang, 'author');
			$qaNode['creditText'] = $credit;
			$qaNode['author'] = $this->jsonNode(['@type' => 'Organization', 'name' => $credit]);
''')
	assert 'creation-date' not in source
	assert 'creation_date_missing' not in source
	assert 'getPageCreation' not in source
	return source
