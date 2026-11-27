<?php
namespace MediaWiki\Extension\DebatePHP;

/** Debate renderer. This PHP source is authoritative and maintained directly.
 * Legacy Table boundaries remain until their native migration is complete.
 * See docs/native-php-migration-baseline.md for the behavioural contract.
 */
class ReferenceRenderer {
	use WarningLabels;

	private ?DecodedReferences $decodedReferences = null;
	private ?RenderData $renderData = null;
	protected Context $context;
	protected $ITEM_SEP;
	protected $FIELD_SEP;
	protected array $ARGUMENT_WARNING_BANNER_KEYS;
	protected array $ARGUMENT_WARNING_LOOKUPS;
	protected array $PROGRESS_BANNER_KEYS;
	protected array $TITLE_WARNING_BANNER_KEYS;
	protected array $DEBATE_WARNING_BANNER_KEYS;
	protected $INTRO_SUB_SEP;
	protected $INTRO_FIELD_SEP;
	protected array $INTRO_SUB_WARNING_KEYS;
	protected array $REFERENCE_WARNING_BANNER_KEYS;
	protected $REF_SEP;
	protected $REF_FIELD_SEP;
	public function __construct(Context $context) {
		$this->context = $context;
		$this->ITEM_SEP = '⟭';
		$this->FIELD_SEP = '⟬';
		$this->ARGUMENT_WARNING_BANNER_KEYS = ['displayed_title_too_long', 'displayed_title_unclear', 'displayed_title_disadvantageous'];
		$this->ARGUMENT_WARNING_LOOKUPS = [];
		$this->PROGRESS_BANNER_KEYS = ['progress_under_construction', 'progress_draft'];
		$this->TITLE_WARNING_BANNER_KEYS = ['title_non_standard', 'title_to_explain', 'title_to_simplify'];
		$this->DEBATE_WARNING_BANNER_KEYS = ['debate_unbalanced', 'debate_redundant', 'debate_fanciful', 'debate_sensitive', 'outline_to_improve', 'debate_generated_by_ai'];
		$this->INTRO_SUB_SEP = '⟭⟭⟭';
		$this->INTRO_FIELD_SEP = '⟬⟬⟬';
		$this->INTRO_SUB_WARNING_KEYS = ['too_long', 'to_develop', 'unclear', 'biased', 'refs_missing', 'style_to_review'];
		$this->REFERENCE_WARNING_BANNER_KEYS = ['reference_quality_insufficient', 'reference_incomplete', 'dead_link'];
		$this->REF_SEP = '⟭';
		$this->REF_FIELD_SEP = '⟬';
	}

	protected function smwProp($lang = null, $key = null) {
		return $this->context->message('Debate.props', $lang, $key);
	}

	protected function isProgress($lang = null, $val = null, $key = null) {
		if ((!(Lua::truth($val)) || ($val === ''))) {
			return false;
		}
		$label = $this->context->message('Debate.progress', $lang, $key);
		return ($val === $label);
	}

	protected function anchorId($s = null) {
		return NativeText::anchorId($s);
	}

	protected function nativeReferenceValues(string $format, ?string $raw): array {
		return ($this->decodedReferences ??= new DecodedReferences)->values($format, $raw);
	}

	/** Native one-based JSON lists; JsonData preserves the historical serialization contract. */
	protected function wkBuildNodesFromNativeItems(array $items, callable $builder): array {
		$out = [];
		foreach ($items as $item) {
			$node = $builder($item);
			if ($node === null) {
				continue;
			}
			$node = SchemaData::reference($node, $this->context);
			if (trim((string)($node['name'] ?? '')) !== '') {
				$out[count($out) + 1] = $node;
			}
		}
		return $out;
	}

	protected function wkRenderNativeReferenceList(?string $raw, string $format, callable $builder): string {
		$out = [];
		foreach ($this->nativeReferenceValues($format, $raw) as $item) {
			$li = $builder($item);
			if ($li !== null && $li !== false && $li !== '') { $out[] = $li; }
		}
		return implode("\n", $out);
	}

	protected function csvValues(?string $text, ?string $separator = null): array {
		return ($this->renderData ??= new RenderData)->csvValues($text, $separator);
	}


	protected function splitCSV($s = null, $sep = null) {
		return ($this->renderData ??= new RenderData)->csv($s, $sep);
	}

	/** @return list<string> */
	protected function wkParseWikipediaMarkers($raw = null): array {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (!str_contains($raw, $this->ITEM_SEP)) {
			return [];
		}
		$pages = [];
		foreach (explode($this->ITEM_SEP, $raw) as $part) {
			$part = trim($part, NativeText::ASCII_WHITESPACE);
			if ($part !== '') {
				$pages[] = $part;
			}
		}
		return $pages;
	}


	protected function seoL($lang = null, $key = null, ...$rest) {
		return $this->context->message('Debate.seo', $lang, $key, ...$rest);
	}


	protected function buildSeoKeywords($args = null, $lang = null) {
		return NativeText::seoKeywords(
			$this->csvValues($args['keywords'], ','),
			$this->csvValues($args['sections'], ',')
		);
	}

	protected function renderFullSEO($args = null, $pv = null, $lang = null) {
		$pageTitle = NativeText::field($pv['rawTitle']);
		$topic = trim(NativeText::field($args['expanded-topic']), NativeText::ASCII_WHITESPACE);
		if (($topic === '')) {
			$topic = $pageTitle;
		}
		$kw = $this->buildSeoKeywords($args, $lang);
		$this->wkSeoSet(new Table(['title' => ($pageTitle . ' ' . $this->L($lang, 'text', 'shortdesc_plain') . ' – ' . $this->L($lang, 'seo', 'site_name')), 'title_mode' => ($pageTitle . ' ' . $this->L($lang, 'text', 'shortdesc_plain')), 'title_separator' => sprintf($this->seoL($lang, 'title_separator'), $pageTitle), 'description' => sprintf($this->seoL($lang, 'description'), $topic), 'keywords' => $kw, 'image' => $this->seoL($lang, 'image'), 'image_alt' => $this->seoL($lang, 'image_alt'), 'section' => $this->seoL($lang, 'section'), 'site_name' => $this->seoL($lang, 'site_name'), 'twitter_site' => $this->seoL($lang, 'twitter_site'), 'locale' => $this->seoL($lang, 'locale'), 'author' => $this->seoL($lang, 'author'), 'robots' => 'index,follow', 'googlebot' => 'index,follow']));
	}

	protected function subMsg($lang = null, $key = null, ...$rest) {
		return $this->context->message('Debate.subsections', $lang, $key, ...$rest);
	}

	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, ?array $opts = null, ?array &$cats = null) {
		$key = NativeText::field($key);
		if (($key === '')) {
			return '';
		}
		$isReorg = (($opts['reorg'] ?? false) === true);
		$linkHtml = '';
		$title = '';
		$text = '';
		if ($isReorg) {
			$formName = NativeText::field($opts['form'] ?? null);
			$tooltip = NativeText::field($opts['tooltip'] ?? null);
			$linkLabel = NativeText::field($opts['link_label'] ?? null);
			if (($linkLabel === '')) {
				$linkLabel = $this->L($lang, 'text', 'reorganize_link_label');
			}
			if (($formName !== '')) {
				$linkHtml = $this->addDataLink($formName, $pv['rawTitle'], $linkLabel, $tooltip);
			}
		}
		if (($isReorg && ($linkHtml !== ''))) {
			$title = $this->subMsg($lang, ($key . '_title'));
			$title = trim(NativeText::field($title), NativeText::ASCII_WHITESPACE);
			if (($title !== '')) {
				$title = ($title . '&nbsp;' . $linkHtml);
			}
			else {
				$title = $linkHtml;
			}
		}
		else {
			$title = $this->subMsg($lang, ($key . '_title'), $pv['rawTitle']);
		}
		$text = $this->subMsg($lang, ($key . '_text'), $pv['rawTitle']);
		if ((($title === '') && ($text === ''))) {
			return '';
		}
		$html = ('<div class="bandeau-avertissement wk-icon-before wk-icon-sensible">' . $title . '<span class="smw-highlighter smwttinline wk-icon-before wk-icon-help mw-no-invert" data-state="inline">' . '<div class="smwttcontent">' . $text . '</div>' . '</span>' . '</div>');
		$cat = $this->subMsg($lang, ($key . '_category'));
		if ($cats !== null && $cat !== '') {
			$cats[] = ('[[Category:' . $cat . ']]');
		}
		return $html;
	}


	protected function pf_escape($v = null) {
		if (!(Lua::truth($v))) {
			return '';
		}
		$v = Lua::str($v);
		$v = Lua::gsub(Lua::gsub($v, '|', '&#124;'), '=', '&#61;');
		return $v;
	}

	protected function wkEncodeAddDataSegment($s = null) {
		$s = NativeText::field($s);
		$s = Lua::gsub($s, '%%', '%%25');
		$s = Lua::gsub($s, '%?', '%%3F');
		$s = Lua::gsub($s, '#', '%%23');
		$s = Lua::gsub($s, '/', '%%2F');
		return $s;
	}

	protected function wkWikipediaUrl($page = null, $lang = null) {
		if (($page === null || $page === false || $page === '')) {
			return '';
		}
		$base = $this->L($lang, 'urls', 'wikipedia');
		if (($base === '')) {
			return '';
		}
		return ($base . $this->context->uriEncode($page, 'WIKI'));
	}

	protected function wkAddDataPath($formName = null, $pageTitle = null) {
		$form = $this->wkEncodeAddDataSegment($this->wkDbKey($formName));
		$page = $this->wkEncodeAddDataSegment($this->wkDbKey($pageTitle));
		return ('Special:AddData/' . $form . '/' . $page);
	}

	protected function addDataLink($formName = null, $pageTitle = null, $linktext = null, $tooltip = null) {
		$formName = NativeText::field($formName);
		$pageTitle = NativeText::field($pageTitle);
		if ((($formName === '') || ($pageTitle === ''))) {
			return '';
		}
		$target = $this->wkAddDataPath($formName, $pageTitle);
		$lt = '&nbsp;';
		$tt = NativeText::field($tooltip);
		if (($tt !== '')) {
			return sprintf('<span class="wk-adddata-link bouton-modifier navigation-not-searchable noprint" data-wk-tooltip="%s">[[%s|%s]]</span>', $this->escapeAttr($tt), $target, $lt);
		}
		return sprintf('[[%s|%s]]', $target, $lt);
	}

	protected function wkRunQueryPath($formName = null) {
		$form = $this->wkEncodeAddDataSegment($this->wkDbKey($formName));
		return ('Special:RunQuery/' . $form);
	}

	protected function normalizeTooltipText($s = null) {
		return NativeText::tooltip($s);
	}

	protected function wkRunQueryHtmlTagButton($formName = null, $label = null, $tooltip = null, ?array $query = null) {
		$formName = NativeText::field($formName);
		$label = NativeText::field($label);
		$tooltip = NativeText::field($tooltip);
		$tooltip = $this->normalizeTooltipText($tooltip);
		if (($formName === '')) {
			return '';
		}
		$title = $this->wkRunQueryPath($formName);
		$href = Lua::str($this->context->localUrl($title, new Table($query ?? [])));
		$content = ('＋ ' . $label);
		$attributes = new Table(['href' => 'javascript:void(0)', 'data-href' => $this->escapeAttr($href), 'class' => 'wk-btn__a wk-js-nav']);
		if ($tooltip !== '') { $attributes['title'] = $this->escapeAttr($tooltip); }
		return $this->context->htmlTag('a', $content, $attributes, true);
	}

	protected function wkAddDataGetButton($formName = null, $pageTitle = null, $label = null, $tooltip = null, ?array $query = null) {
		$formName = NativeText::field($formName);
		$pageTitle = NativeText::field($pageTitle);
		$label = NativeText::field($label);
		$tooltip = NativeText::field($tooltip);
		if ((($formName === '') || ($pageTitle === ''))) {
			return '';
		}
		$title = $this->wkAddDataPath($formName, $pageTitle);
		$url = Lua::str($this->context->fullUrl($title, new Table($query ?? [])));
		$linkInner = ('<span class="wk-btn__icon" aria-hidden="true">➜</span>' . '<span class="wk-btn__label">' . $label . '</span>');
		$link = ('[' . $url . ' ' . $linkInner . ']');
		return ('<div class="wk-btn wk-auto-id mw-ui-button navigation-not-searchable"' . (Lua::truth($v51 = (Lua::truth($v52 = ($tooltip !== '')) ? (' title="' . $this->escapeAttr($tooltip) . '"') : $v52)) ? $v51 : '') . '>' . $link . '</div>');
	}

	protected function runQueryLink($formName = null, $fieldLabel = null, $fieldName = null, $value = null, $linktext = null, $tooltip = null, ?array $extraParams = null) {
		$formName = NativeText::field($formName);
		$fieldLabel = NativeText::field($fieldLabel);
		$fieldName = NativeText::field($fieldName);
		$value = NativeText::field($value);
		if ((((($formName === '') || ($fieldLabel === '')) || ($fieldName === '')) || ($value === ''))) {
			return '';
		}
		$title = $this->wkRunQueryPath($formName);
		$q = [];
		$q[($fieldLabel . '[' . $fieldName . ']')] = $value;
		if ($extraParams !== null) {
			foreach ($extraParams as $k => $v) {
				if (((($k !== '') && ($v !== null)) && (Lua::str($v) !== ''))) {
					$q[$k] = Lua::str($v);
				}
			}
		}
		$q['_run'] = '';
		$url = Lua::str($this->context->fullUrl($title, new Table($q)));
		$label = $linktext === null || $linktext === false ? $value : $linktext;
		$tt = NativeText::field($tooltip);
		$link = ('[' . $url . ' ' . $label . ']');
		if (($tt !== '')) {
			return sprintf('<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>', $this->escapeAttr($tt), $link);
		}
		return sprintf('<span class="wk-adddata-link">%s</span>', $link);
	}

	/** Join dense, zero-based rendered Wikipedia links. */
	protected function joinArticles(array $items, $lang = null): string {
		if (count($items) < 2) {
			return $items[0] ?? '';
		}
		$conjunction = $this->context->message('Common.text', $lang, 'list_conjunction_text');
		$last = array_pop($items);
		return implode(', ', $items) . $conjunction . $last;
	}

	protected function listBreaker() {
		return '

<!--__WD_LIST_BREAK__-->';
	}

	protected function getArgumentWarningLookup($lang = null): array {
		$lang = NativeText::field($lang);
		if (!isset($this->ARGUMENT_WARNING_LOOKUPS[$lang])) {
			$this->ARGUMENT_WARNING_LOOKUPS[$lang] = $this->buildSummaryLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
		}
		return $this->ARGUMENT_WARNING_LOOKUPS[$lang];
	}

	protected function renderArgumentItemBanners($itemWarnings = null, $pv = null, $lang = null, ?array &$cats = null): array {
		$warnsArg = trim(NativeText::field($itemWarnings), NativeText::ASCII_WHITESPACE);
		if (($warnsArg === '')) {
			return [];
		}
		$lookup = $this->getArgumentWarningLookup($lang);
		$hasBanner = false;
		$out = [];
		foreach ($this->csvValues($warnsArg, ',') as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->ARGUMENT_WARNING_BANNER_KEYS);
			if (($key !== '')) {
				$fragment = $this->renderSubsectionBanner($pv, $lang, $key);
				if ($fragment !== null) { $out[] = $fragment; }
				$hasBanner = true;
			}
		}
		if (($hasBanner && ($cats !== null))) {
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'displayed_titles_to_review') . ']]');
		}
		return $out;
	}

	protected function wkSplit2($s = null, $sep = null) {
		return NativeText::splitOnce($s, $sep);
	}

	protected function wkExtractArgumentItemsFromData($raw = null) {
		return SerializedData::argumentItems($raw, false);
	}

	/** @param list<array{page:string,title:string,warnings:string}> $items */
	protected function wkBuildArgumentData(array $items = [], $lang = null, $kind = null): array {
		$idPrefix = $kind === 'pro' ? 'Pro_argument_' : 'Con_argument_';
		$listLines = [];
		$argsVarParts = [];
		$mapLines = [];
		$hoverLines = [];
		foreach ($items as $index => $item) {
			$page = $item['page'];
			$title = $item['title'];
			$listLines[] = '* ' . $title;
			$argsVarParts[] = $page === $title ? $page : $page . $this->FIELD_SEP . $title;
			$mapLines[] = '<div  id="' . $idPrefix . ($index + 1) . '_map" class="argument-title--map wk-icon-before wk-icon-argument">' . $title . '</div>';
			$hoverLines[] = '<div class="argument-title--map wk-icon-before wk-icon-argument">[[' . $page . '|' . $title . ']]</div>';
		}
		return [
			'items' => $items,
			'list' => implode("\n", $listLines),
			'argsVar' => implode(' -;- ', $argsVarParts),
			'map' => implode("\n", $mapLines),
			'mapHover' => implode("\n", $hoverLines)
		];
	}

	protected function computeArgumentMapsFromArgs($args = null, $lang = null): array {
		$rawPro = NativeText::field($args['pro-arguments']);
		$rawCon = NativeText::field($args['con-arguments']);
		if (($rawPro !== '')) {
			$rawPro = $this->context->expandField($rawPro, 'debate.arguments.pro');
		}
		if (($rawCon !== '')) {
			$rawCon = $this->context->expandField($rawCon, 'debate.arguments.con');
		}
		return [
			'pro' => $this->wkBuildArgumentData($this->wkExtractArgumentItemsFromData($rawPro), $lang, 'pro'),
			'con' => $this->wkBuildArgumentData($this->wkExtractArgumentItemsFromData($rawCon), $lang, 'con')
		];
	}

	protected function wkRenderArgumentLi($pv = null, $lang = null, $it = null, $kind = null, $idx = null, ?array &$cats = null) {
		$isPro = ($kind === 'pro');
		$idPrefix = ($isPro ? 'Pro_argument_' : 'Con_argument_');
		$page = NativeText::field($it['page']);
		$titleShown = NativeText::field($it['title']);
		if (($titleShown === '')) {
			$titleShown = $page;
		}
		$aId = $this->anchorId($titleShown);
		$warnings = NativeText::field($it['warnings']);
		$banners = $this->renderArgumentItemBanners($warnings, $pv, $lang, $cats);
		return ('<li id="' . $idPrefix . Lua::str($idx) . '" class="argument level-1">' . '<div id="' . $this->escapeAttr($aId) . '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">' . '[[' . $page . '|' . $titleShown . ']]' . implode('', $banners) . '</div>' . '</li>');
	}

	protected function renderMetaWarningHtml(array $params = []) {
		$color = NativeText::field($params['color'] ?? null);
		$icon = NativeText::field($params['icon'] ?? null);
		$title = NativeText::field($params['title'] ?? null);
		$text = NativeText::field($params['text'] ?? null);
		return ('<div class="bandeau bandeau-' . $color . ' wk-icon-before ' . $icon . ' navigation-not-searchable">' . '<div class="bandeau-titre">' . $title . '</div>' . '<div class="bandeau-texte">' . $text . '</div>' . '</div>');
	}

	protected function renderMainBanner($frame = null, $pv = null, $lang = null) {
		$titre = sprintf($this->L($lang, 'text', 'main_banner_title'), $this->context->nowiki($pv['rawTitle']));
		return $this->renderMetaWarningHtml(['color' => $this->L($lang, 'text', 'main_banner_color'), 'title' => $titre, 'icon' => 'wk-icon-wikidebates', 'text' => ('<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert">' . $this->L($lang, 'text', 'learn_more_label') . $this->L($lang, 'text', 'founding_principles_link') . '</div>')]);
	}

	protected function bannerMsg($lang = null, $key = null, ...$rest) {
		return $this->context->message('Debate.banners', $lang, $key, ...$rest);
	}

	protected function renderBannerByKey($pv = null, $lang = null, $key = null, ?array &$cats = null) {
		$key = NativeText::field($key);
		if (($key === '')) {
			return '';
		}
		$color = $this->bannerMsg($lang, ($key . '_color'));
		$icon = $this->bannerMsg($lang, ($key . '_icon'));
		$title = $this->bannerMsg($lang, ($key . '_title'), $pv['rawTitle']);
		$text = $this->bannerMsg($lang, ($key . '_text'), $pv['rawTitle']);
		if ((((($color === '') && ($icon === '')) && ($title === '')) && ($text === ''))) {
			return '';
		}
		$html = $this->renderMetaWarningHtml(['color' => $color, 'icon' => $icon, 'title' => $title, 'text' => $text]);
		$cat = $this->bannerMsg($lang, ($key . '_category'));
		if (((($cats !== null) && Lua::truth($cat)) && ($cat !== ''))) {
			$cats[] = ('[[Category:' . $cat . ']]');
		}
		return $html;
	}

	protected function renderWarningBanners($args = null, $pv = null, ?array &$cats = null, $lang = null) {
		$av = $args['progress'];
		$out = [];
		$titleLookup = $this->buildBannerLabelLookup($lang, $this->TITLE_WARNING_BANNER_KEYS);
		$debateLookup = $this->buildBannerLabelLookup($lang, $this->DEBATE_WARNING_BANNER_KEYS);
		$progLookup = $this->buildBannerLabelLookup($lang, $this->PROGRESS_BANNER_KEYS);
		$renderBannersFromList = function($list = null, $lookup = null) use (&$pv, &$cats, &$lang) {
		$html = [];
		foreach ($this->csvValues($list, ',') as $_ => $x) {
			$key = $this->resolveBannerKeyFromLookup($x, $lookup);
			if (($key !== '')) {
				$html[] = $this->renderBannerByKey($pv, $lang, $key, $cats);
			}
		}
		return implode('', $html);
		};
		if (Lua::truth($args['title-warnings'])) {
			$out[] = $renderBannersFromList($args['title-warnings'], $titleLookup);
		}
		if ((Lua::truth($av) && (trim($av, NativeText::ASCII_WHITESPACE) !== ''))) {
			$key = $this->resolveBannerKeyFromLookup($av, $progLookup);
			if (($key !== '')) {
				$out[] = $this->renderBannerByKey($pv, $lang, $key, $cats);
			}
		}
		if (Lua::truth($this->isProgress($lang, $av, 'constructed'))) {
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'constructed') . ']]');
		}
		else {
			if (((!(Lua::truth($this->isProgress($lang, $av, 'draft'))) && !(Lua::truth($this->isProgress($lang, $av, 'building')))) && !(Lua::truth($this->isProgress($lang, $av, 'constructed'))))) {
				$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'progress_missing') . ']]');
			}
		}
		if (Lua::truth($args['debate-warnings'])) {
			$out[] = $renderBannersFromList($args['debate-warnings'], $debateLookup);
		}
		return implode('', $out);
	}

	protected function renderArgumentMapTop($lang = null, $maps = null) {
		$pro = $maps['pro']['map'] ?? '';
		$con = $maps['con']['map'] ?? '';
		$labelPour = $this->L($lang, 'text', 'arg_map_pro_label');
		$labelContre = $this->L($lang, 'text', 'arg_map_con_label');
		$msgPour = $this->L($lang, 'text', 'none_pro_args_msg_short');
		$msgContre = $this->L($lang, 'text', 'none_con_args_msg_short');
		$root = ((((HtmlBuilder::create('div'))->attr('id', 'Argument_map'))->addClass('wk-arg-map'))->addClass('wk-arg-map--top'))->addClass('navigation-not-searchable');
		$colP = ((HtmlBuilder::create('div'))->addClass('wk-arg-map__col'))->addClass('is-pro');
		$colC = ((HtmlBuilder::create('div'))->addClass('wk-arg-map__col'))->addClass('is-con');
		($colP)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__th'))->wikitext($labelPour));
		($colP)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__td'))->wikitext(($pro !== '' ? $pro : '<div class="carte-vide navigation-not-searchable">' . $msgPour . '</div>')));
		($colC)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__th'))->wikitext($labelContre));
		($colC)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__td'))->wikitext(($con !== '' ? $con : '<div class="carte-vide navigation-not-searchable">' . $msgContre . '</div>')));
		(($root)->node($colP))->node($colC);
		return (string)$root;
	}

	protected function wkParseIntroSubsections($raw = null) {
		return SerializedData::records($raw, '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], true);
	}

	protected function buildIntroSubWarningLookup($lang = null): array {
		$lookup = [];
		foreach ($this->INTRO_SUB_WARNING_KEYS as $k) {
			$lab = trim($this->subMsg($lang, ('intro_subbanner_' . $k . '_label')), NativeText::ASCII_WHITESPACE);
			if (($lab !== '')) {
				$lookup[$lab] = ('intro_subbanner_' . $k);
			}
		}
		return $lookup;
	}

	protected function wkRenderIntroSubsection($pv = null, $lang = null, $it = null, ?array &$cats = null, $lookup = null) {
		$titre = trim(NativeText::field($it['title']), NativeText::ASCII_WHITESPACE);
		$contenu = trim(NativeText::field($it['content']), NativeText::ASCII_WHITESPACE);
		$avert = trim(NativeText::field($it['warnings']), NativeText::ASCII_WHITESPACE);
		if (($titre === '')) {
			$titre = $this->L($lang, 'text', 'default_subsection_title');
		}
		$out = [];
		$out[] = '<div class="fr-collapsible fr-collapsed">';
		$out[] = ('<h3 class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-question">' . $titre . '</h3>');
		$out[] = '<div class="fr-collapsible-content"><div class="encadre-section-deroulante">';
		if (($avert !== '')) {
			$banners = [];
			foreach (explode(',', $avert) as $lab) {
				$lab = trim($lab, NativeText::ASCII_WHITESPACE);
				if (($lab !== '')) {
					$k = ($lookup[$lab] ?? '');
					if (($k !== '')) {
						$h = $this->renderSubsectionBanner($pv, $lang, $k, [], $cats);
						if ((Lua::truth($h) && ($h !== ''))) {
							$banners[] = $h;
						}
					}
				}
			}
			if ((count($banners) > 0)) {
				$out[] = ('<div class="minibandeaux">' . implode('', $banners) . '</div>');
			}
		}
		if (($contenu !== '')) {
			$out[] = $contenu;
		}
		else {
			$editForm = $this->L($lang, 'forms', 'intro_edit_title');
			$editTT = $this->L($lang, 'text', 'intro_edit_tt');
			$linkLabel = $this->L($lang, 'text', 'intro_empty_link_label');
			$emptyIntro = $this->L($lang, 'text', 'intro_empty_msg');
			$emptyCat = $this->L($lang, 'text', 'intro_empty_category');
			$link = $this->addDataLink($editForm, $pv['rawTitle'], $linkLabel, $editTT);
			$out[] = ('<i>' . $emptyIntro . '</i>');
			if (($emptyCat !== '')) {
				$cats[] = ('[[Category:' . $emptyCat . ']]');
			}
		}
		$out[] = '</div></div></div>';
		return implode('', $out);
	}

	protected function wkRenderIntroSubsectionsBlock($pv = null, $lang = null, $raw = null, ?array &$cats = null) {
		$items = $this->wkParseIntroSubsections($raw);
		if ((Lua::len($items) === 0)) {
			return '';
		}
		$lookup = $this->buildIntroSubWarningLookup($lang);
		$out = [];
		foreach (($items)->sequence() as $_ => $it) {
			$out[] = $this->wkRenderIntroSubsection($pv, $lang, $it, $cats, $lookup);
		}
		return implode('', $out);
	}

	protected function renderIntroduction($args = null, $pv = null, $lang = null, ?array &$cats = null) {
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-question">' . $this->L($lang, 'text', 'understand_debate_title') . $this->addDataLink($this->L($lang, 'forms', 'intro_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'intro_edit_tt')) . '</h2>');
		$raw = $args['introduction'];
		$raw = (Lua::truth($v105 = (Lua::truth($v106 = (is_string($raw))) ? trim($raw, NativeText::ASCII_WHITESPACE) : $v106)) ? $v105 : '');
		if (($raw !== '')) {
			$expanded = $this->context->expandField($raw, 'debate.introduction');
			if ($cats === null) {
				$ignoredCategories = [];
				$subs = $this->wkRenderIntroSubsectionsBlock($pv, $lang, $expanded, $ignoredCategories);
			} else {
				$subs = $this->wkRenderIntroSubsectionsBlock($pv, $lang, $expanded, $cats);
			}
			if (($subs !== '')) {
				$s = Lua::gsub(($h2 . $subs), '%s*$', '');
				return $s;
			}
		}
		$cat = ('[[Category:' . $this->L($lang, 'categories', 'intro_missing') . ']]');
		$body = ('<div class="aucun-contenu navigation-not-searchable" style="margin-bottom: 1em;">' . $this->L($lang, 'text', 'none_intro_msg') . '</div>' . $cat);
		$s = Lua::gsub(($h2 . $body), '%s*$', '');
		return $s;
	}

	protected function renderWikipediaLinks($args = null, $pv = null, $lang = null) {
		$raw = $args['wikipedia-articles'];
		$raw = is_string($raw) ? trim($raw, NativeText::ASCII_WHITESPACE) : '';
		$wrap = (HtmlBuilder::create('div'))->addClass('bandeau-section wk-icon-before wk-icon-wikipedia hover-top navigation-not-searchable');
		$hasPages = false;
		if (($raw !== '')) {
			$expanded = $this->context->expandField($raw, 'debate.wikipedia');
			$pages = $this->wkParseWikipediaMarkers($expanded);
			if ($pages !== []) {
				$hasPages = true;
				$items = [];
				foreach ($pages as $page) {
					$url = $this->wkWikipediaUrl($page, $lang);
					$items[] = ('<span class="hover-wikipedia">[' . $url . ' ' . $page . ']</span>');
				}
				($wrap)->wikitext(sprintf($this->L($lang, 'text', 'wikipedia_seealso_msg'), $this->joinArticles($items, $lang)));
			}
			else {
				(($wrap)->addClass('aucun-contenu'))->wikitext($this->L($lang, 'text', 'none_wikipedia_msg'));
			}
		}
		else {
			(($wrap)->addClass('aucun-contenu'))->wikitext($this->L($lang, 'text', 'none_wikipedia_msg'));
		}
		($wrap)->wikitext($this->addDataLink($this->L($lang, 'forms', 'wiki_seealso_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'wikipedia_edit_tt')));
		if ((($raw === '') || !($hasPages))) {
			return ((string)$wrap . '[[Category:' . $this->L($lang, 'categories', 'wikipedia_missing') . ']]');
		}
		return (string)$wrap;
	}

	protected function renderQuestionsFAQ($sujetComplet = null, $sens = null, $lang = null) {
		$topic = (Lua::truth($v110 = (Lua::truth($v111 = (Lua::truth($v112 = $sujetComplet) ? ($sujetComplet !== '') : $v112)) ? $sujetComplet : $v111)) ? $v110 : '');
		$isCon = ($sens === 'con');
		$q = null;
		if ($isCon) {
			$q = sprintf($this->L($lang, 'text', 'faq_q_con'), $topic);
		}
		else {
			$q = sprintf($this->L($lang, 'text', 'faq_q_pro'), $topic);
		}
		return ('<div class="question-FAQ">' . $q . '</div>');
	}

	protected function renderArgsList($args = null, $pv = null, $lang = null, $sens = null, $maps = null, ?array &$cats = null) {
		$isCon = ($sens === 'con');
		$hIconCon = 'is-con';
		$hIconPro = 'is-pro';
		$title = (Lua::truth($v113 = (Lua::truth($v114 = $isCon) ? $this->L($lang, 'text', 'args_con_title') : $v114)) ? $v113 : $this->L($lang, 'text', 'args_pro_title'));
		$btnForm = $this->addDataLink((Lua::truth($v115 = (Lua::truth($v116 = $isCon) ? $this->L($lang, 'forms', 'args_con_edit_title') : $v116)) ? $v115 : $this->L($lang, 'forms', 'args_pro_edit_title')), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), (Lua::truth($v117 = (Lua::truth($v118 = $isCon) ? $this->L($lang, 'text', 'args_con_edit_tt') : $v118)) ? $v117 : $this->L($lang, 'text', 'args_pro_edit_tt')));
		$out = [];
		$out[] = $this->listBreaker();
		$out[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-argument ' . (Lua::truth($v119 = (Lua::truth($v120 = $isCon) ? $hIconCon : $v120)) ? $v119 : $hIconPro) . '">' . $title . $btnForm . '</h2>');
		$out[] = $this->renderQuestionsFAQ($args['expanded-topic'], $sens, $lang);
		$items = $maps[$isCon ? 'con' : 'pro']['items'] ?? [];
		if ($items !== []) {
			$ul = ((HtmlBuilder::create('ul'))->addClass('argument-list'))->addClass(($isCon ? 'is-con' : 'is-pro'));
			$lis = [];
			$anyTitleWarnings = false;
			foreach ($items as $i => $it) {
				if ($it['warnings'] !== '') {
					$anyTitleWarnings = true;
				}
				$lis[] = $this->wkRenderArgumentLi($pv, $lang, $it, ($isCon ? 'con' : 'pro'), $i + 1, $cats);
			}
			($ul)->wikitext(implode('
', $lis));
			if ($anyTitleWarnings) {
				$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'refs_to_review') . ']]');
			}
			$out[] = Lua::str($ul);
		}
		else {
			$cat = (Lua::truth($v136 = (Lua::truth($v137 = $isCon) ? ('[[Category:' . $this->L($lang, 'categories', 'con_missing') . ']]') : $v137)) ? $v136 : ('[[Category:' . $this->L($lang, 'categories', 'pro_missing') . ']]'));
			$msg = (Lua::truth($v138 = (Lua::truth($v139 = $isCon) ? $this->L($lang, 'text', 'none_con_args_msg') : $v139)) ? $v138 : $this->L($lang, 'text', 'none_pro_args_msg'));
			$out[] = ('<div class="aucun-argument navigation-not-searchable">' . $msg . '</div>' . $cat);
		}
		$sideLabel = (Lua::truth($v140 = (Lua::truth($v141 = $isCon) ? $this->L($lang, 'text', 'side_con_word') : $v141)) ? $v140 : $this->L($lang, 'text', 'side_pro_word'));
		$label = sprintf($this->L($lang, 'text', 'args_add_label'), $sideLabel);
		$tooltip = sprintf($this->L($lang, 'text', 'arg_add_tt'), $sideLabel, $pv['rawTitle']);
		$baseField = $this->L($lang, 'forms', 'new_arg_title');
		$rqTypeValue = (Lua::truth($v142 = (Lua::truth($v143 = $isCon) ? $this->L($lang, 'forms', 'new_arg_con_type_label') : $v143)) ? $v142 : $this->L($lang, 'forms', 'new_arg_pro_type_label'));
		$rqQuery = [];
		$rqQuery[($baseField . '[type]')] = $rqTypeValue;
		if ($pv['pageId'] !== null) {
			$rqQuery[$baseField . '[ID]'] = $pv['pageId'];
		}
		$rqQuery['_run'] = '1';
		$out[] = ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($baseField, $label, $tooltip, $rqQuery) . '</div>');
		return implode('', $out);
	}

	protected function renderReferenceItemBanners($itemWarnings = null, $pv = null, $lang = null, ?array &$cats = null, &$out = null) {
		$warnsRef = trim(NativeText::field($itemWarnings), NativeText::ASCII_WHITESPACE);
		if (($warnsRef === '')) {
			return null;
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->REFERENCE_WARNING_BANNER_KEYS);
		foreach ($this->csvValues($warnsRef, ',') as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->REFERENCE_WARNING_BANNER_KEYS);
			if (($key !== '')) {
				$fragment = $this->renderSubsectionBanner($pv, $lang, $key, null, $cats);
				if ($out instanceof Table) { Lua::insert($out, $fragment); }
				elseif ($fragment !== null) { $out[] = $fragment; }
			}
		}
	}

	protected function wkRenderLiListFromArg($raw = null, $parseFn = null, $renderOneFn = null) {
		$items = $parseFn($raw);
		if ((Lua::len($items) === 0)) {
			return '';
		}
		$lis = [];
		foreach (($items)->sequence() as $_ => $item) {
			$li = $renderOneFn($item);
			if ((Lua::truth($li) && ($li !== ''))) {
				$lis[] = $li;
			}
		}
		return implode("\n", $lis);
	}

	protected function wkParseBiblioMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('bibliography', $raw);
	}

	protected function wkRenderOneBiblioLi($pv = null, $item = null, $lang = null, ?array &$categories = null, array &$smwPending = []) {
		$item = $item instanceof Table ? $item->values : $item;
		$out = [];
		$authors = $item['authors'];
		if (($authors === '')) {
			$authors = $this->L($lang, 'text', 'refs_to_review');
			$categories[] = ('[[Category:' . $this->L($lang, 'categories', 'refs_to_review') . ']]');
		}
		$out[] = $authors;
		$article = $item['article'];
		$work = $item['work'];
		$link = $item['link'];
		if (($article !== '')) {
			SemanticProperties::add($smwPending, $this->smwProp($lang, 'article_name'), $article, true);
		}
		if (($work !== '')) {
			SemanticProperties::add($smwPending, $this->smwProp($lang, 'work_name'), $work, true);
		}
		if (($article !== '')) {
			if (($link !== '')) {
				$out[] = (', « [' . $link . ' ' . $article . '] »');
			}
			else {
				$out[] = (', « ' . $article . ' »');
			}
		}
		if (($work !== '')) {
			if (($article !== '')) {
				$out[] = (', \'\'' . $work . '\'\'');
			}
			else {
				if (($link !== '')) {
					$out[] = (', \'\'[' . $link . ' ' . $work . ']\'\'');
				}
				else {
					$out[] = (', \'\'' . $work . '\'\'');
				}
			}
		}
		if (($work !== '')) {
			$volume = $item['volume'];
			if (($volume !== '')) {
				$out[] = (', ' . $volume);
			}
			$number = $item['number'];
			if (($number !== '')) {
				$out[] = (', n°' . $number);
			}
		}
		$location = $item['location'];
		if (($location !== '')) {
			$out[] = (', ' . $location);
		}
		$page = $item['page'];
		if (($page !== '')) {
			$out[] = (', p.' . $page);
		}
		$publisher = $item['publisher'];
		if (($publisher !== '')) {
			SemanticProperties::add($smwPending, $this->smwProp($lang, 'publishing_house'), $publisher, true);
			$out[] = (', ' . $publisher);
		}
		$place = $item['place'];
		if (($place !== '')) {
			SemanticProperties::add($smwPending, $this->smwProp($lang, 'place_of_publication'), $place, true);
			$out[] = (', ' . $place);
		}
		$date = $item['date'];
		if (($date !== '')) {
			$out[] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderBiblioListFromArg($raw = null, $pv = null, $lang = null, ?array &$categories = null, array &$smwPending = []) {
		return $this->wkRenderNativeReferenceList($raw, 'bibliography', function($item = null) use (&$pv, &$lang, &$categories, &$smwPending) {
		return $this->wkRenderOneBiblioLi($pv, $item, $lang, $categories, $smwPending);
		});
	}

	protected function wkParseWeblioMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('webliography', $raw);
	}

	protected function wkRenderOneWeblioLi($pv = null, $item = null, $lang = null, ?array &$categories = null, array &$smwPending = [], $side = null) {
		$item = $item instanceof Table ? $item->values : $item;
		$out = [];
		$page = $item['page'];
		$site = $item['site'];
		$link = $item['link'];
		$authors = $item['authors'];
		$date = $item['date'];
		if (($page !== '')) {
			SemanticProperties::add($smwPending, $this->smwProp($lang, 'article_name'), $page, true);
		}
		if (($site !== '')) {
			SemanticProperties::add($smwPending, $this->smwProp($lang, 'website_name'), $site, true);
		}
		if (($authors !== '')) {
			$out[] = $authors;
		}
		{
			$label = '';
			if (($page !== '')) {
				$label = $page;
			}
			else {
				if (($site !== '')) {
					$label = $site;
				}
			}
			if (($label === '')) {
				return '';
			}
			$renderedLabel = null;
			if (($link !== '')) {
				$renderedLabel = ('[' . $link . ' ' . $label . ']');
			}
			else {
				$renderedLabel = $label;
			}
			$out[] = (((count($out) > 0) ? ', ' : '') . $renderedLabel);
		}
		if ((($site !== '') && ($page !== ''))) {
			$out[] = (', \'\'' . $site . '\'\'');
		}
		if (($date !== '')) {
			$out[] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		if (($authors !== '')) {
			$propKey = 'author';
			if (($side === 'pro')) {
				$propKey = 'author_pro';
			}
			else {
				if (($side === 'con')) {
					$propKey = 'author_con';
				}
			}
			foreach (explode(',', $authors) as $a) {
				$a = trim($a, NativeText::ASCII_WHITESPACE);
				if (($a !== '')) {
					SemanticProperties::add($smwPending, $this->smwProp($lang, $propKey), $a, true);
				}
			}
		}
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderWeblioListFromArg($raw = null, $pv = null, $lang = null, ?array &$categories = null, array &$smwPending = [], $side = null) {
		return $this->wkRenderNativeReferenceList($raw, 'webliography', function($item = null) use (&$pv, &$lang, &$categories, &$smwPending, &$side) {
		return $this->wkRenderOneWeblioLi($pv, $item, $lang, $categories, $smwPending, $side);
		});
	}

	protected function wkParseVideoMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('videography', $raw);
	}

	protected function wkRenderOneVideoLi($pv = null, $item = null, $lang = null, ?array &$categories = null, array &$smwPending = []) {
		$item = $item instanceof Table ? $item->values : $item;
		$title = $item['title'];
		if (($title === '')) {
			return '';
		}
		$link = $item['link'];
		$authors = $item['authors'];
		$out = [];
		if (($authors !== '')) {
			$out[] = $authors;
		}
		$renderedTitle = null;
		if (($link !== '')) {
			$renderedTitle = ('[' . $link . ' ' . $title . ']');
		}
		else {
			$renderedTitle = $title;
		}
		$out[] = (((count($out) > 0) ? ', ' : '') . $renderedTitle);
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderVideoListFromArg($raw = null, $pv = null, $lang = null, ?array &$categories = null, array &$smwPending = []) {
		return $this->wkRenderNativeReferenceList($raw, 'videography', function($item = null) use (&$pv, &$lang, &$categories, &$smwPending) {
		return $this->wkRenderOneVideoLi($pv, $item, $lang, $categories, $smwPending);
		});
	}

	protected function renderRefBlock($pageVars = null, $lang = null, $iconClass = null, $titleText = null, $formKey = null, $value = null) {
		$out = [];
		$out[] = ('<h4 class="titre-references wk-icon-before ' . $iconClass . ' navigation-not-searchable">' . $titleText . $this->addDataLink($this->L($lang, 'forms', $formKey), $pageVars['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'section_edit_tt')) . '</h4>');
		if (($value !== null && $value !== false && trim($value, NativeText::ASCII_WHITESPACE) !== '')) {
			$out[] = ('<ul>' . $value . '</ul>');
		}
		else {
			$out[] = ('<div class="aucune-reference navigation-not-searchable">' . $this->L($lang, 'text', 'none_references_msg') . '</div>');
		}
		return implode('', $out);
	}

	/**
	 * Render each reference section and retain its expanded input for JSON-LD.
	 * Expansion and rendering order must remain bibliography/web/video, pro/con/neutral.
	 * @return array{string,array<string,array<string,string>>}
	 */
	protected function renderReferences($args = null, $pageVars = null, $lang = null, ?array &$categories = null, array &$smwPending = []): array {
		$wrap = ['<h2 class="wk-icon-before wk-icon-further">' . $this->L($lang, 'text', 'further_reading_title') . '</h2>'];
		$refRaw = [];
		$formats = [
			'bibliography' => ['Bibliography', 'biblio', 'wkRenderBiblioListFromArg'],
			'webliography' => ['Webliography', 'webliography', 'wkRenderWeblioListFromArg'],
			'videography' => ['Videography', 'videography', 'wkRenderVideoListFromArg']
		];
		$sides = [
			'pro' => ['wk-icon-argument is-pro', 'side_pro_title'],
			'con' => ['wk-icon-argument is-con', 'side_con_title'],
			'neutral' => ['wk-icon-neutral mw-no-invert', 'side_neutral_title']
		];
		foreach ($formats as $format => [$id, $formPrefix, $builder]) {
			$h3 = '<h3 id="' . $id . '" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-' . $format . '">' .
				$this->L($lang, 'text', $format . '_title') . '</h3>';
			$rendered = [];
			foreach ($sides as $side => $_) {
				$field = $side === 'neutral' ? $format : $side . '-' . $format;
				$raw = (string)($args[$field] ?? '');
				$refRaw[$format][$side] = $raw === '' ? '' : $this->context->expandField($raw, 'debate.' . $format . '.' . $side);
				if ($raw === '') {
					$rendered[$side] = '';
				} elseif ($format === 'webliography') {
					$rendered[$side] = $this->$builder($refRaw[$format][$side], $pageVars, $lang, $categories, $smwPending, $side);
				} else {
					$rendered[$side] = $this->$builder($refRaw[$format][$side], $pageVars, $lang, $categories, $smwPending);
				}
			}
			$content = [];
			foreach ($sides as $side => [$icon, $titleKey]) {
				$content[] = $this->renderRefBlock($pageVars, $lang, $icon, $this->L($lang, 'text', $titleKey),
					$formPrefix . '_' . $side . '_title', $rendered[$side]);
			}
			$wrap[] = '<div class="fr-collapsible fr-collapsed">' . $h3 .
				'<div class="fr-collapsible-content contenu-references">' . implode('', $content) . '</div></div>';
		}
		return [implode('', $wrap), $refRaw];
	}

	protected function wkBuildWikiUrl($lang = null, $prefixKey = null, $labelKey = null) {
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $this->L($lang, 'urls', $labelKey)), 'WIKI'));
	}

	protected function wkBuildWikiUrlFromText($lang = null, $prefixKey = null, $text = null) {
		$text = trim(NativeText::field($text), NativeText::ASCII_WHITESPACE);
		if (($text === '')) {
			return '';
		}
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $text), 'WIKI'));
	}

	protected function wkFullUrl($titleText = null) {
		$titleText = trim(NativeText::field($titleText), NativeText::ASCII_WHITESPACE);
		if (($titleText === '')) {
			return '';
		}
		return Lua::str($this->context->fullUrl($titleText));
	}

	protected function wkBuildWebsiteNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return ['@type' => 'WebSite', '@id' => ($homeUrl . '#website'), 'url' => $homeUrl, 'name' => $siteName];
	}

	protected function wkBuildOrganizationNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return ['@type' => 'Organization', '@id' => ($homeUrl . '#organization'), 'name' => $siteName, 'url' => $homeUrl];
	}

	protected function wkBuildBreadcrumbNode($pv = null, $lang = null) {
		$homeLabel = $this->L($lang, 'urls', 'home_label');
		$homeUrl = $this->L($lang, 'urls', 'home');
		$debatesLabel = $this->L($lang, 'urls', 'debates_label');
		$debatesUrl = $this->wkBuildWikiUrl($lang, 'portal_prefix', 'debates_label');
		return ['@type' => 'BreadcrumbList', '@id' => ($pv['pageUrl'] . '#breadcrumb'), 'itemListElement' => [1 => ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => $homeUrl, 'name' => $homeLabel]], 2 => ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => $debatesUrl, 'name' => $debatesLabel]], 3 => ['@type' => 'ListItem', 'position' => 3, 'item' => ['@id' => $pv['pageUrl'], 'name' => $pv['rawTitle']]]]];
	}

	protected function wkBuildIsPartOf($lang = null) {
		$out = [];
		$out[(count($out) + 1)] = ['@id' => $this->wkBuildWikiUrl($lang, 'category_prefix', 'debates_label')];
		$out[(count($out) + 1)] = ['@id' => ($this->L($lang, 'urls', 'home') . '#website')];
		return $out;
	}

	protected function wkBuildRelatedLinks($args = null) {
		$out = [];
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

	protected function wkBuildKeywords($args = null, $lang = null) {
		$out = [];
		$sections = $this->csvValues(($args['sections'] ?? ''), ',');
		$termSetId = $this->wkBuildWikiUrl($lang, 'category_prefix', 'sections_label');
		$stop3 = count($sections);
		for ($i = 1; $i <= $stop3; $i++) {
			if (($i > 4)) {
				break;
			}
			$section = trim(NativeText::field($sections[$i]), NativeText::ASCII_WHITESPACE);
			if (($section !== '')) {
				$out[count($out) + 1] = ['@type' => 'DefinedTerm', '@id' => $this->wkBuildWikiUrlFromText($lang, 'category_prefix', $section), 'name' => $section, 'inDefinedTermSet' => ['@id' => $termSetId]];
			}
		}
		return $out;
	}

	protected function wkBuildAboutAndMentions($args = null) {
		$about = [];
		$mentions = [];
		$keywords = $this->csvValues(($args['keywords'] ?? ''), ',');
		$stop3 = count($keywords);
		for ($i = 1; $i <= $stop3; $i++) {
			$kw = trim(NativeText::field($keywords[$i]), NativeText::ASCII_WHITESPACE);
			if (($kw !== '')) {
				$node = ['@type' => 'Thing', 'name' => $kw];
				if (($i <= 2)) {
					$about[count($about) + 1] = $node;
				}
				else {
					$mentions[count($mentions) + 1] = $node;
				}
			}
		}
		return [$about, $mentions];
	}

	protected function wkBuildDiscussionUrl($pv = null, $lang = null) {
		$prefix = $this->L($lang, 'urls', 'discussion_prefix');
		return $this->wkBuildWikiUrlFromText($lang, 'discussion_prefix', $pv['rawTitle']);
	}



	protected function wkBuildQuoteAuthorValue($authors = null) {
		$authors = trim(NativeText::field($authors), NativeText::ASCII_WHITESPACE);
		if (($authors === '')) {
			return null;
		}
		$out = [];
		foreach (explode(',', $authors) as $a) {
			$a = trim(NativeText::field($a), NativeText::ASCII_WHITESPACE);
			if (($a !== '')) {
				$out[count($out) + 1] = ['@type' => 'Person', 'name' => $a];
			}
		}
		if ((count($out) === 0)) {
			return null;
		}
		if ((count($out) === 1)) {
			return $out[1];
		}
		return $out;
	}

	protected function wkBuildClaimCitationsFromBibliography($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return [];
		}
		$items = $this->nativeReferenceValues('bibliography', $raw);
		return $this->wkBuildNodesFromNativeItems($items, function($item = null) {
		$authors = $item['authors'];
		$article = $item['article'];
		$work = $item['work'];
		$volume = $item['volume'];
		$number = $item['number'];
		$publisher = $item['publisher'];
		$date = $item['date'];
		$link = $item['link'];
		$node = null;
		if (((($number !== '') && ($article !== '')) && ($work !== ''))) {
			$node = ['@type' => 'Article', 'name' => $article, 'isPartOf' => ['@type' => 'Periodical', 'name' => $work]];
			if (($volume !== '')) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if ((($publisher !== '') && ($work !== ''))) {
				$node = ['@type' => 'Book', 'name' => $work];
				if (($article !== '')) {
					$node['description'] = $article;
				}
				if (($volume !== '')) {
					$node['volumeNumber'] = $volume;
				}
				if (($number !== '')) {
					$node['issueNumber'] = $number;
				}
			}
			else {
				if ((($link !== '') && ($article !== ''))) {
					$node = ['@type' => 'WebPage', 'name' => $article];
					if (($work !== '')) {
						$node['isPartOf'] = ['@type' => 'WebSite', 'name' => $work];
					}
				}
			}
		}
		if ($node === null) {
			if ($article === '' && $work === '') {
				return null;
			}
			$node = ['@type' => 'CreativeWork', 'name' => $article !== '' ? $article : $work];
			if ($article !== '' && $work !== '') {
				$node['isPartOf'] = ['@type' => 'CreativeWork', 'name' => $work];
			}
			// Preserve ambiguous volume/number as literal identifiers.
			$identifiers = [];
			foreach (['volume' => $volume, 'number' => $number] as $key => $value) {
				if ($value !== '') {
					$identifiers[count($identifiers) + 1] = [
						'@type' => 'PropertyValue', 'propertyID' => $key, 'value' => $value
					];
				}
			}
			if ($identifiers !== []) {
				$node['identifier'] = $identifiers;
			}
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if ($authorValue !== null) {
			$node['author'] = $authorValue;
		}
		if (($publisher !== '')) {
			$node['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
		}
		if (($date !== '')) {
			$node['datePublished'] = $date;
		}
		if (($link !== '')) {
			$node['url'] = $link;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromWebliography($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return [];
		}
		$items = $this->nativeReferenceValues('webliography', $raw);
		return $this->wkBuildNodesFromNativeItems($items, function($item = null) {
		$page = $item['page'];
		$site = $item['site'];
		$link = $item['link'];
		$date = $item['date'];
		if ($page === '' && $site === '') {
			return null;
		}
		$node = ['@type' => 'WebPage', 'name' => $page !== '' ? $page : $site];
		if ($link !== '') {
			$node['url'] = $link;
		}
		if ($page !== '' && $site !== '') {
			$node['isPartOf'] = ['@type' => 'WebSite', 'name' => $site];
		}
		if (($date !== '')) {
			$node['datePublished'] = $date;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromVideography($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return [];
		}
		$items = $this->nativeReferenceValues('videography', $raw);
		return $this->wkBuildNodesFromNativeItems($items, function($item = null) {
		$title = $item['title'];
		$link = $item['link'];
		$authors = $item['authors'];
		if ($title === '') {
			return null;
		}
		$node = ['@type' => 'VideoObject', 'name' => $title];
		if ($link !== '') {
			$node['url'] = $link;
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if ($authorValue !== null) {
			$node['author'] = $authorValue;
		}
		return $node;
		});
	}

	protected function renderDebateSchemaJSONLD($pv = null, $lang = null, $args = null, $maps = null, array $refRaw = []) {
		$websiteNode = $this->wkBuildWebsiteNode($lang);
		$breadcrumbNode = $this->wkBuildBreadcrumbNode($pv, $lang);
		$organizationNode = $this->wkBuildOrganizationNode($lang);
		[$about, $mentions] = $this->wkBuildAboutAndMentions($args);
		$keywords = $this->wkBuildKeywords($args, $lang);
		$isPartOf = $this->wkBuildIsPartOf($lang);
		$relatedLinks = $this->wkBuildRelatedLinks($args);
		$questionText = null;
		if (($args['expanded-topic'] !== null && $args['expanded-topic'] !== false && (trim($args['expanded-topic'], NativeText::ASCII_WHITESPACE) !== ''))) {
			$questionText = sprintf($this->L($lang, 'text', 'faq_q'), trim($args['expanded-topic'], NativeText::ASCII_WHITESPACE));
		}
		else {
			$questionText = $pv['rawTitle'];
		}
		$citations = [];
		// Reuse the lists decoded for the three visible sections, rather
		// than concatenating their serialized records and decoding again.
		foreach ([
			'bibliography' => 'wkBuildClaimCitationsFromBibliography',
			'webliography' => 'wkBuildClaimCitationsFromWebliography',
			'videography' => 'wkBuildClaimCitationsFromVideography'
		] as $format => $builder) {
			foreach (['pro', 'con', 'neutral'] as $side) {
				foreach ($this->$builder((string)($refRaw[$format][$side] ?? '')) as $node) {
					$citations[count($citations) + 1] = $node;
				}
			}
		}
		$pageNode = [
			'@type' => [1 => 'WebPage', 2 => 'LearningResource'],
			// Keep the existing graph identifier: related pages already reference it.
			'@id' => $pv['pageUrl'] . '#qa',
			'url' => $pv['pageUrl'],
			'name' => $pv['rawTitle'],
			'inLanguage' => $lang,
			'isPartOf' => $isPartOf,
			'breadcrumb' => ['@id' => $pv['pageUrl'] . '#breadcrumb'],
			'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang),
			'publisher' => ['@id' => $this->L($lang, 'urls', 'home') . '#organization'],
			'mainEntity' => [
				'@type' => 'Question',
				'@id' => $pv['pageUrl'] . '#question',
				'name' => $pv['rawTitle'],
				'text' => NativeText::seoPlainText($questionText, $this->context) ?: $pv['rawTitle']
			]
		] + SchemaData::education($lang, 'debate');
		$parts = SchemaData::sections($maps, ['pro' => 'pro', 'con' => 'con'],
			$pv['pageUrl'], $lang, fn(string $title): string => $this->wkFullUrl($title), $this->context);
		if ($parts !== []) {
			$pageNode['hasPart'] = $parts;
		}
		// Credit the collective as an author of the page, not of its arguments.
		$credit = $this->seoL($lang, 'author');
		$pageNode['creditText'] = $credit;
		$pageNode['author'] = ['@type' => 'Organization', 'name' => $credit];
		if ((count($relatedLinks) > 0)) {
			$pageNode['relatedLink'] = $relatedLinks;
		}
		if ((count($keywords) > 0)) {
			$pageNode['keywords'] = $keywords;
		}
		if ((count($about) > 0)) {
			$pageNode['about'] = $about;
		}
		if ((count($mentions) > 0)) {
			$pageNode['mentions'] = $mentions;
		}
		if ((count($citations) > 0)) {
			$pageNode['citation'] = $citations;
		}
		$data = (['@context' => 'https://schema.org', '@graph' => ([1 => $websiteNode, 2 => $organizationNode, 3 => $breadcrumbNode, 4 => $pageNode])]);
		if ($this->context instanceof NativeJsonContext) { return $this->context->jsonLdData($data); }
		$json = $this->context->jsonEncode(Table::wrap($data));
		return $this->context->jsonLd($json);
	}

	protected function renderKeywords($args = null, $pv = null, ?array &$cats = null, $lang = null) {
		$out = [];
		$out[count($out) + 1] = ('<div style="font-size: 95%; margin-top: 1em;">' . $this->L($lang, 'text', 'keywords_label_text'));
		if ((Lua::truth($args['keywords']) && (trim($args['keywords'], NativeText::ASCII_WHITESPACE) !== ''))) {
			$rendered = [];
			$formName = $this->L($lang, 'forms', 'search_by_keywords_title');
			$fieldLabel = $formName;
			$fieldName = $this->L($lang, 'params', 'keywords_field');
			$tt = $this->L($lang, 'text', 'search_by_keyword_tt');
			$typeField = $this->L($lang, 'params', 'search_type_field');
			$typeValue = $this->L($lang, 'params', 'search_type_value');
			$extra = [($fieldLabel . '[' . $typeField . ']') => $typeValue];
			foreach ($this->csvValues($args['keywords'], ',') as $_ => $k) {
				$k = trim(NativeText::field($k), NativeText::ASCII_WHITESPACE);
				if (($k !== '')) {
					$chip = $this->runQueryLink($formName, $fieldLabel, $fieldName, $k, $k, $tt, $extra);
					if ((!(Lua::truth($chip)) || ($chip === ''))) {
						$chip = $k;
					}
					if (($chip !== '')) {
						$rendered[count($rendered) + 1] = $chip;
					}
				}
			}
			if ((count($rendered) > 0)) {
				$out[count($out) + 1] = implode(', ', $rendered);
			}
			else {
				$out[count($out) + 1] = ('<strong>' . $this->L($lang, 'text', 'none_label') . '</strong>');
				$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'keywords_missing') . ']]');
			}
		}
		else {
			$out[count($out) + 1] = ('<strong>' . $this->L($lang, 'text', 'none_label') . '</strong>');
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'keywords_missing') . ']]');
		}
		$out[count($out) + 1] = ($this->addDataLink($this->L($lang, 'forms', 'keywords_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'keywords_edit_tt')) . '<span style="display: none;">.</span></div>');
		return implode('', $out);
	}

	protected function renderConnexes($args = null, $pv = null, ?array &$cats = null, $lang = null) {
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-parent">' . $this->L($lang, 'forms', 'related_edit_title') . $this->addDataLink($this->L($lang, 'forms', 'related_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'related_edit_tt')) . '</h2>');
		$raw = $args['related-debates'];
		if ((Lua::truth($raw) && (trim($raw, NativeText::ASCII_WHITESPACE) !== ''))) {
			$items = [];
			$n = 0;
			foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
				$part = trim(NativeText::field($part), NativeText::ASCII_WHITESPACE);
				if (($part !== '')) {
					$n = ($n + 1);
					$items[$n] = ('<li style="position: relative;"><span class="hover-map">[[' . $part . ']]</span></li>');
				}
			}
			if (($n > 0)) {
				return ($h2 . '<ul class="hover-top searchaux">' . implode('', $items) . '</ul>');
			}
		}
		$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'related_missing') . ']]');
		return ($h2 . '<div class="aucun-contenu navigation-not-searchable">' . $this->L($lang, 'text', 'none_related_msg') . '</div>');
	}

	protected function renderRubriques($args = null, ?array &$cats = null, $lang = null) {
		if ((Lua::truth($args['sections']) && (trim($args['sections'], NativeText::ASCII_WHITESPACE) !== ''))) {
			$out = [];
			foreach ($this->csvValues($args['sections'], ',') as $_ => $r) {
				$out[count($out) + 1] = ('[[Category:' . $r . ']]');
			}
			return implode('', $out);
		}
		return ('[[Category:' . $this->L($lang, 'categories', 'sections_missing') . ']]');
	}

	protected function setSemanticData($args = null, $pv = null, $lang = null, $maps = null, array $smwPending = []) {
		$pro = $maps['pro'] ?? [];
		$con = $maps['con'] ?? [];
		$pourPages = [];
		$contrePages = [];
		foreach ($pro['items'] ?? [] as $it) {
			if ($it['page'] !== '') {
				$pourPages[] = $it['page'];
			}
		}
		foreach ($con['items'] ?? [] as $it) {
			if ($it['page'] !== '') {
				$contrePages[] = $it['page'];
			}
		}
		$props = [];
		$pourSurvol = trim($pro['mapHover'] ?? '', NativeText::ASCII_WHITESPACE);
		$contreSurvol = trim($con['mapHover'] ?? '', NativeText::ASCII_WHITESPACE);
		if (($pourSurvol === '')) {
			$pourSurvol = ('<div class="carte-vide">' . $this->L($lang, 'text', 'none_pro_args_msg_short') . '</div>');
		}
		if (($contreSurvol === '')) {
			$contreSurvol = ('<div class="carte-vide">' . $this->L($lang, 'text', 'none_con_args_msg_short') . '</div>');
		}
		$listePour = trim($pro['argsVar'] ?? '', NativeText::ASCII_WHITESPACE);
		$listeContre = trim($con['argsVar'] ?? '', NativeText::ASCII_WHITESPACE);
		$props[$this->smwProp($lang, 'debate_name')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'debate_number')] = $pv['pageId'];
		$props[$this->smwProp($lang, 'breadcrumb')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'parent_debate')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'pro_list')] = $listePour;
		$props[$this->smwProp($lang, 'con_list')] = $listeContre;
		$props[$this->smwProp($lang, 'arg_map')] = ('<div class="wk-arg-map navigation-not-searchable">' . '<div class="wk-arg-map__col is-pro">' . '<div class="wk-arg-map__th">' . $this->L($lang, 'text', 'arg_map_pro_label') . '</div>' . '<div class="wk-arg-map__td">' . $pourSurvol . '</div>' . '</div>' . '<div class="wk-arg-map__col is-con">' . '<div class="wk-arg-map__th">' . $this->L($lang, 'text', 'arg_map_con_label') . '</div>' . '<div class="wk-arg-map__td">' . $contreSurvol . '</div>' . '</div>' . '</div>');
		if ((count($pourPages) > 0)) {
			$props[$this->smwProp($lang, 'arg_pro')] = $pourPages;
		}
		if ((count($contrePages) > 0)) {
			$props[$this->smwProp($lang, 'arg_con')] = $contrePages;
		}
		SemanticProperties::merge($props, $smwPending);
		$this->smwSetSafe($props);
	}

	protected function escapeAttr($s = null) {
		return NativeText::attribute($s);
	}

	public function render($frame = null) {
		$this->decodedReferences = null;
		$this->renderData = null;
		$args = $this->getArgs($frame);
		$this->renderData = new RenderData;
		$this->renderData->expectReuse([$args['keywords'], $args['sections']]);
		$lang = $this->detectLang($args);
		$pv = $this->computePageVars();
		[$pieces, $cats] = [[], []];
		$smwPending = [];
		$keywords = is_string($args['keywords']) ? array_values($this->csvValues($args['keywords'], ',')) : [];
		if ($keywords !== []) {
			SemanticProperties::add($smwPending, $this->smwProp($lang, 'keyword'), $keywords, true);
		}
		$maps = $this->computeArgumentMapsFromArgs($args, $lang);
		$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'debates') . ']]');
		if ((Lua::truth($args['topic']) && (trim($args['topic'], NativeText::ASCII_WHITESPACE) !== ''))) {
			SemanticProperties::add($smwPending, $this->smwProp($lang, 'debate_topic'), $args['topic'], false);
		}
		else {
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'topic_missing') . ']]');
		}
		if ((Lua::truth($args['expanded-topic']) && (trim($args['expanded-topic'], NativeText::ASCII_WHITESPACE) !== ''))) {
			$pieces[] = $this->context->shortDescription(sprintf($this->L($lang, 'text', 'shortdesc_topic'), $args['expanded-topic']));
		}
		else {
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'topic_complete_missing') . ']]');
			$pieces[] = $this->context->shortDescription($this->L($lang, 'text', 'shortdesc_plain'));
		}
		$pieces[] = $this->renderArgumentMapTop($lang, $maps);
		$pieces[] = $this->renderKeywords($args, $pv, $cats, $lang);
		$btnForm = $this->addDataLink($this->L($lang, 'forms', 'banners_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'debate_banners_edit_tt'));
		$pieces[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-flag">' . $this->L($lang, 'text', 'warnings_title') . $btnForm . '</h2>');
		$pieces[] = $this->renderMainBanner($this->context, $pv, $lang);
		$pieces[] = $this->renderWarningBanners($args, $pv, $cats, $lang);
		$pieces[] = $this->renderIntroduction($args, $pv, $lang, $cats);
		$pieces[] = $this->renderWikipediaLinks($args, $pv, $lang);
		$pieces[] = $this->renderArgsList($args, $pv, $lang, 'pro', $maps, $cats);
		$pieces[] = $this->renderArgsList($args, $pv, $lang, 'con', $maps, $cats);
		if (!(Lua::truth($this->isProgress($lang, $args['progress'], 'draft')))) {
			$target = ($this->L($lang, 'text', 'self_eval_ns_label') . ':' . $pv['rawTitle'] . ' (ID)');
			$ns = $this->L($lang, 'text', 'self_eval_ns_label');
			$f_debat = $this->L($lang, 'text', 'self_eval_debate_field');
			$box = [];
			$box[] = ('<h2 class="navigation-not-searchable noprint wk-icon-before wk-icon-selfeval">' . $this->L($lang, 'text', 'self_eval_title') . '</h2>');
			$startBtn = $this->wkAddDataGetButton($this->L($lang, 'forms', 'self_eval_home_title'), $target, $this->L($lang, 'text', 'self_eval_start_label'), $this->L($lang, 'text', 'self_eval_start_tt'), [($ns . '[' . $f_debat . ']') => $pv['rawTitle']]);
			$box[] = ('<div class="boite-info navigation-not-searchable noprint">' . $this->L($lang, 'text', 'self_eval_blurb') . $startBtn . '</div>');
			$pieces[] = implode('', $box);
		}
		[$references, $refRaw] = $this->renderReferences($args, $pv, $lang, $cats, $smwPending);
		$pieces[] = $references;
		$pieces[] = $this->renderConnexes($args, $pv, $cats, $lang);
		$pieces[] = ('<h2 class="latest-changes-button navigation-not-searchable noprint wk-icon-before wk-icon-refresh">' . $this->L($lang, 'text', 'latest_changes_title') . '</h2>' . '<div class="latest-changes navigation-not-searchable noprint">' . '<div class="latest-changes-button wk-btn mw-ui-button"' . ' title="' . $this->L($lang, 'text', 'latest_changes_show_tt') . '"' . ' data-page="' . $this->context->nowiki($pv['rawTitle']) . '">' . $this->L($lang, 'text', 'latest_changes_show_label') . '</div>' . '<div class="latest-changes-wrapper"></div>' . '</div>');
		$pieces[] = $this->renderRubriques($args, $cats, $lang);
		$this->setSemanticData($args, $pv, $lang, $maps, $smwPending);
		$this->renderFullSEO($args, $pv, $lang);
		$pieces[] = implode('', $cats);
		$renameLink = sprintf($this->L($lang, 'text', 'rename_special_page_text'), $pv['encoded']);
		$pieces[] = ('<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[[' . $renameLink . '|' . $this->L($lang, 'text', 'rename_link_label') . ']]</span>');
		$pieces[] = ('<span id="bouton-modifier-sujet" style="display: none;">' . $this->addDataLink($this->L($lang, 'forms', 'topic_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'topic_edit_tt')) . '</span>');
		$pieces[] = ('<span id="bouton-modifier-categories" style="display: none;">' . $this->addDataLink($this->L($lang, 'forms', 'sections_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'sections_edit_tt')) . '</span>');
		if ((Lua::truth($args['interlanguage']) && (trim(NativeText::field($args['interlanguage']), NativeText::ASCII_WHITESPACE) !== ''))) {
			$pieces[] = $args['interlanguage'];
			$pieces[] = ('<span id="bouton-modifier-interlangue" class="bouton-modifier navigation-not-searchable noprint" style="display: none;"' . ' data-wk-tooltip="' . $this->escapeAttr($this->L($lang, 'text', 'interlanguage_edit_tt')) . '">' . $this->addDataLink($this->L($lang, 'forms', 'interlanguage_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label')) . '</span>');
		}
		$pieces[] = $this->renderDebateSchemaJSONLD($pv, $lang, $args, $maps, $refRaw);
		if ($this->context instanceof PreparationProfilingContext) {
			$this->context->profilePreparation($this->renderData->statistics());
		}
		return implode('', $pieces);
	}

	protected function getArgs($frame = null) { return $this->context->arguments(); }
	protected function detectLang($args = null) { return $this->context->language($args); }
	protected function L($lang = null, $section = null, $key = null, ...$rest) { return $this->context->message('Debate.' . $section, $lang, $key, ...$rest); }
	protected function computePageVars() { return $this->context->pageVars(); }
	protected function wkDbKey($page = null) { return $this->context->dbKey($page); }
	protected function wkSeoSet($data = null) { $this->context->seoSet($data); }
	protected function smwSetSafe(array $data): void { $this->context->smwSet(SemanticProperties::toLegacyTable($data)); }
}
