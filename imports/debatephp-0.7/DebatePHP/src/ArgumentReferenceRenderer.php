<?php
namespace MediaWiki\Extension\DebatePHP;

/** Native PHP port of the supplied Module:Argument. See docs/argument.md.
 * No Lua interpreter is used. Table preserves reference and 1-based semantics.
 * Generated mechanically, then checked against the reference source.
 */
class ArgumentReferenceRenderer {
	protected ArgumentContext $context;
	protected $ITEM_SEP;
	protected $FIELD_SEP;
	protected $QUOTE_ITEM_SEP;
	protected $QUOTE_FIELD_SEP;
	protected $I18N_DOMAIN;
	protected $I18N_MSG;
	protected $EXPAND_CACHE;
	protected $TITLE_CACHE;
	protected $JUSTIFICATION_WARNING_BANNER_KEYS;
	protected $OBJECTION_WARNING_BANNER_KEYS;
	protected $QUOTE_WARNING_BANNER_KEYS;
	protected $REFERENCE_WARNING_BANNER_KEYS;
	protected $SUMMARY_WARNING_BANNER_KEYS;
	protected $SUMMARY_WARNING_ALIASES;
	protected $DISPLAYED_TITLE_WARNING_BANNER_KEYS;
	protected $SMW_ASK_CACHE;
	protected $SMW_TITLES_CACHE;
	protected $URLENC_CACHE;
	protected $CARD_CACHE;
	protected $TITLE_WARNING_BANNER_KEYS;
	protected $ARGUMENT_WARNING_BANNER_KEYS;
	protected $SEO_CACHE;
	public function __construct(ArgumentContext $context) {
		$this->context = $context;
		$this->ITEM_SEP = '⟭';
		$this->FIELD_SEP = '⟬';
		$this->QUOTE_ITEM_SEP = '⟭⟭⟭';
		$this->QUOTE_FIELD_SEP = '⟬⟬⟬';
		$this->I18N_DOMAIN = new Table([]);
		$this->I18N_MSG = new Table([]);
		$this->EXPAND_CACHE = $this->cacheMake(250);
		$this->TITLE_CACHE = $this->cacheMake(600);
		$this->JUSTIFICATION_WARNING_BANNER_KEYS = new Table([1 => 'justifications_to_reorganize']);
		$this->OBJECTION_WARNING_BANNER_KEYS = new Table([1 => 'objections_to_reorganize']);
		$this->QUOTE_WARNING_BANNER_KEYS = new Table([1 => 'quote_too_short', 2 => 'quote_too_long', 3 => 'quote_ref_incomplete', 4 => 'quote_translated']);
		$this->REFERENCE_WARNING_BANNER_KEYS = new Table([1 => 'reference_quality_insufficient', 2 => 'reference_incomplete', 3 => 'dead_link']);
		$this->SUMMARY_WARNING_BANNER_KEYS = new Table([1 => 'summary_disadvantageous', 2 => 'summary_to_be_written', 3 => 'summary_unclear', 4 => 'summary_too_long', 5 => 'summary_to_separate', 6 => 'summary_style_to_review']);
		$this->SUMMARY_WARNING_ALIASES = null;
		$this->DISPLAYED_TITLE_WARNING_BANNER_KEYS = new Table([1 => 'displayed_title_too_long', 2 => 'displayed_title_unclear', 3 => 'displayed_title_disadvantageous']);
		$this->SMW_ASK_CACHE = $this->cacheMake(250);
		$this->SMW_TITLES_CACHE = $this->cacheMake(250);
		$this->URLENC_CACHE = $this->cacheMake(2000);
		$this->CARD_CACHE = $this->cacheMake(600);
		$this->TITLE_WARNING_BANNER_KEYS = new Table([1 => 'title_incomplete', 2 => 'title_disadvantageous', 3 => 'title_unclear', 4 => 'title_too_long']);
		$this->ARGUMENT_WARNING_BANNER_KEYS = new Table([1 => 'argument_sensitive', 2 => 'argument_fanciful', 3 => 'argument_potentially_illegal', 4 => 'argument_generated_by_ai']);
		$this->SEO_CACHE = $this->cacheMake(60);
	}

	protected function anchorId($s = null) {
		$s = Lua::str((Lua::truth($v1 = $s) ? $v1 : ''));
		$s = Lua::gsub($s, '&#039;', '\'');
		$s = Lua::gsub($s, '&#39;', '\'');
		$s = Lua::gsub($s, '&apos;', '\'');
		$s = Lua::gsub($s, '%s+', ' ');
		$s = Lua::trim($s);
		$s = Lua::gsub($s, ' ', '_');
		$s = Lua::gsub($s, '[%[%]{}|#<>"]', '');
		return $s;
	}

	protected function cacheMake($max = null) {
		return new Table(['data' => new Table([]), 'order' => new Table([]), 'max' => (Lua::truth($v2 = $max) ? $v2 : 200)]);
	}

	protected function cacheGet($C = null, $k = null) {
		return $C['data'][$k];
	}

	protected function cachePut($C = null, $k = null, $v = null) {
		if (Lua::truth(($C['data'][$k] !== null))) {
			$C['data'][$k] = $v;
			return null;
		}
		$C['data'][$k] = $v;
		Lua::insert($C['order'], $k);
		if (Lua::truth((Lua::len($C['order']) > (Lua::truth($v3 = $C['max']) ? $v3 : 200)))) {
			$old = Lua::remove($C['order'], 1);
			if (Lua::truth(($old !== null))) {
				$C['data'][$old] = null;
			}
		}
	}

	protected function normalizeTooltipText($s = null) {
		$s = Lua::str((Lua::truth($v4 = $s) ? $v4 : ''));
		if (Lua::truth(($s === ''))) {
			return '';
		}
		$s = Lua::gsub($s, '\'', '’');
		$s = Lua::gsub($s, '"', '“');
		return $s;
	}

	protected function fastParamsKey($params = null) {
		if (Lua::truth((Lua::type($params) !== 'table'))) {
			return '';
		}
		$n = 0;
		foreach (Lua::pairs($params) as $_ => $_value) {
			$n = ($n + 1);
			if (Lua::truth(($n > 2))) {
				return null;
			}
		}
		if (Lua::truth((Lua::truth($v5 = ($n === 1)) ? ($params[1] !== null) : $v5))) {
			return ('1=' . Lua::str($params[1]));
		}
		if (Lua::truth(($n === 2))) {
			[$k1, $k2] = [null, null];
			foreach (Lua::pairs($params) as $k => $_value) {
				if (Lua::truth(!Lua::truth($k1))) {
					$k1 = $k;
				}
				else {
					$k2 = $k;
				}
			}
			[$a, $b] = [Lua::str($k1), Lua::str($k2)];
			if (Lua::truth(($a > $b))) {
				[$k1, $k2] = [$k2, $k1];
			}
			return (Lua::str($k1) . '=' . Lua::str($params[$k1]) . '&' . Lua::str($k2) . '=' . Lua::str($params[$k2]));
		}
		return null;
	}

	protected function escapeAttr($s = null) {
		$s = Lua::str((Lua::truth($v6 = $s) ? $v6 : ''));
		return Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub($s, '[
	]', ' '), '&', '&amp;'), '<', '&lt;'), '>', '&gt;'), '"', '&quot;');
	}

	protected function splitCSV($s = null, $sep = null) {
		return SerializedData::csv($s, $sep);
	}

	protected function splitByLiteral($s = null, $sep = null, $maxParts = null) {
		return SerializedData::split($s, $sep, $maxParts);
	}

	protected function wkSplit2($s = null, $sep = null) {
		$s = Lua::str((Lua::truth($v13 = $s) ? $v13 : ''));
		$sep = Lua::str((Lua::truth($v14 = $sep) ? $v14 : ''));
		if (Lua::truth(($sep === ''))) {
			return [$s, ''];
		}
		$i = Lua::find($s, $sep, 1, true);
		if (Lua::truth(!Lua::truth($i))) {
			return [$s, ''];
		}
		return [Lua::sub($s, 1, ($i - 1)), Lua::sub($s, ($i + Lua::len($sep)))];
	}

	protected function wkExtractItemsFromSerializedData($raw = null) {
		return SerializedData::argumentItems($raw, true);
	}

	protected function wkEncodePathSegment($s = null) {
		$s = Lua::str((Lua::truth($v16 = $s) ? $v16 : ''));
		$s = Lua::gsub($s, '%%', '%%25');
		$s = Lua::gsub($s, '%?', '%%3F');
		$s = Lua::gsub($s, '#', '%%23');
		$s = Lua::gsub($s, '/', '%%2F');
		return $s;
	}

	protected function wkAddDataPath($formName = null, $pageTitle = null) {
		$form = $this->wkEncodePathSegment($this->wkDbKey($formName));
		$page = $this->wkEncodePathSegment($this->wkDbKey($pageTitle));
		return ('Special:AddData/' . $form . '/' . $page);
	}

	protected function addDataLink($formName = null, $pageTitle = null, $linktext = null, $tooltip = null, $opts = null) {
		$formName = Lua::str((Lua::truth($v17 = $formName) ? $v17 : ''));
		$pageTitle = Lua::str((Lua::truth($v18 = $pageTitle) ? $v18 : ''));
		if (Lua::truth((Lua::truth($v19 = ($formName === '')) ? $v19 : ($pageTitle === '')))) {
			return '';
		}
		$opts = (Lua::truth($v20 = (Lua::truth($v21 = (Lua::type($opts) === 'table')) ? $opts : $v21)) ? $v20 : new Table([]));
		$target = $this->wkAddDataPath($formName, $pageTitle);
		$lt = $linktext;
		if (Lua::truth((Lua::truth($v22 = ($lt === null)) ? $v22 : ($lt === '')))) {
			$lt = '&nbsp;';
		}
		$tt = Lua::str((Lua::truth($v23 = $tooltip) ? $v23 : ''));
		$classes = 'wk-adddata-link navigation-not-searchable noprint';
		if (Lua::truth(($opts['editButton'] === true))) {
			$classes = ($classes . ' bouton-modifier');
		}
		if (Lua::truth(($tt !== ''))) {
			return Lua::format('<span class="%s" data-wk-tooltip="%s">[[%s|%s]]</span>', $classes, $this->escapeAttr($tt), $target, $lt);
		}
		return Lua::format('<span class="%s">[[%s|%s]]</span>', $classes, $target, $lt);
	}

	protected function addDataEditLink($formName = null, $pageTitle = null, $linktext = null, $tooltip = null) {
		return $this->addDataLink($formName, $pageTitle, $linktext, $tooltip, new Table(['editButton' => true]));
	}

	protected function wkRunQueryPath($formName = null) {
		$form = $this->wkEncodePathSegment($this->wkDbKey($formName));
		return ('Special:RunQuery/' . $form);
	}

	protected function wkRunQueryHtmlTagButton($formName = null, $label = null, $tooltip = null, $query = null) {
		$formName = Lua::str($formName ?? '');
		if ($formName === '') { return ''; }
		$tooltip = $this->normalizeTooltipText(Lua::str($tooltip ?? ''));
		$query = $query instanceof Table ? $query : new Table;
		$href = Lua::str($this->context->localUrl($this->wkRunQueryPath($formName), $query));
		$attributes = new Table(['href' => 'javascript:void(0)', 'data-href' => $this->escapeAttr($href), 'class' => 'wk-btn__a wk-js-nav']);
		if ($tooltip !== '') { $attributes['title'] = $this->escapeAttr($tooltip); }
		return $this->context->htmlTag('a', '＋ ' . Lua::str($label ?? ''), $attributes, true);
	}

	protected function runQueryLink($opts = null) {
		$opts = (Lua::truth($v31 = $opts) ? $v31 : new Table([]));
		$formName = Lua::str((Lua::truth($v32 = $opts['form']) ? $v32 : ''));
		$fieldName = Lua::str((Lua::truth($v33 = $opts['field']) ? $v33 : ''));
		$value = Lua::str((Lua::truth($v34 = $opts['value']) ? $v34 : ''));
		if (Lua::truth((Lua::truth($v35 = (Lua::truth($v36 = ($formName === '')) ? $v36 : ($fieldName === ''))) ? $v35 : ($value === '')))) {
			return '';
		}
		$title = Lua::str((Lua::truth($v37 = $opts['_title']) ? $v37 : ''));
		if (Lua::truth(($title === ''))) {
			$title = $this->wkRunQueryPath($formName);
		}
		$q = new Table([]);
		$qkey = Lua::str((Lua::truth($v38 = $opts['_qkey']) ? $v38 : ''));
		if (Lua::truth(($qkey === ''))) {
			$qkey = ($formName . '[' . $fieldName . ']');
		}
		$q[$qkey] = $value;
		$extra = $opts['extra'];
		if (Lua::truth((Lua::type($extra) === 'table'))) {
			foreach (Lua::pairs($extra) as $k => $v) {
				$kk = Lua::str((Lua::truth($v39 = $k) ? $v39 : ''));
				if (Lua::truth((Lua::truth($v40 = ($kk !== '')) ? ($v !== null) : $v40))) {
					$vv = Lua::str($v);
					if (Lua::truth(($vv !== ''))) {
						if (Lua::truth(Lua::find($kk, '%[', 1, true))) {
							$q[$kk] = $vv;
						}
						else {
							$q[($formName . '[' . $kk . ']')] = $vv;
						}
					}
				}
			}
		}
		$q['_run'] = '1';
		$url = $this->absUrlFromTitle($title, $q);
		$label = Lua::str((Lua::truth($v41 = $opts['label']) ? $v41 : $value));
		$tt = $this->normalizeTooltipText(Lua::str((Lua::truth($v42 = $opts['tooltip']) ? $v42 : '')));
		$link = ('[' . $url . ' ' . $label . ']');
		if (Lua::truth(($tt !== ''))) {
			return Lua::format('<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>', $this->escapeAttr($tt), $link);
		}
		return Lua::format('<span class="wk-adddata-link">%s</span>', $link);
	}

	protected function listBreaker() {
		return '

<!--__WD_LIST_BREAK__-->';
	}

	protected function pfProp($PF = null, $k = null, $fallback = null) {
		$v = (Lua::truth($v43 = (Lua::truth($v44 = $PF) ? $PF[$k] : $v44)) ? $v43 : '');
		$v = Lua::trim(Lua::str((Lua::truth($v45 = $v) ? $v45 : '')));
		if (Lua::truth(($v !== ''))) {
			return $v;
		}
		return (Lua::truth($v46 = $fallback) ? $v46 : '');
	}

	protected function smwPropRef($PF = null, $key = null) {
		if (Lua::truth(($key === 'authors'))) {
			return $this->pfProp($PF, 'prop_author');
		}
		if (Lua::truth(($key === 'article_name'))) {
			return $this->pfProp($PF, 'prop_article_name');
		}
		if (Lua::truth(($key === 'work_name'))) {
			return $this->pfProp($PF, 'prop_work_name');
		}
		if (Lua::truth(($key === 'publishing_house'))) {
			return $this->pfProp($PF, 'prop_publishing_house');
		}
		if (Lua::truth(($key === 'place_of_publication'))) {
			return $this->pfProp($PF, 'prop_place_of_publication');
		}
		return '';
	}

	protected function smwQueueAdd($pending = null, $key = null, $val = null, $multi = null) {
		if (Lua::truth((Lua::truth($v47 = (Lua::truth($v48 = (Lua::truth($v49 = !Lua::truth($pending)) ? $v49 : !Lua::truth($key))) ? $v48 : ($key === ''))) ? $v47 : ($val === null)))) {
			return null;
		}
		if (Lua::truth((Lua::type($val) === 'string'))) {
			$val = Lua::trim($val);
			if (Lua::truth(($val === ''))) {
				return null;
			}
		}
		$cur = $pending[$key];
		if (Lua::truth($multi)) {
			if (Lua::truth(($cur === null))) {
				$pending[$key] = (Lua::truth($v50 = (Lua::truth($v51 = (Lua::type($val) === 'table')) ? $val : $v51)) ? $v50 : new Table([1 => $val]));
				return null;
			}
			if (Lua::truth((Lua::type($cur) !== 'table'))) {
				$cur = new Table([1 => $cur]);
				$pending[$key] = $cur;
			}
			if (Lua::truth((Lua::type($val) === 'table'))) {
				foreach (Lua::ipairs($val) as $_ => $x) {
					if (Lua::truth(($x !== null))) {
						$cur[(Lua::len($cur) + 1)] = $x;
					}
				}
			}
			else {
				$cur[(Lua::len($cur) + 1)] = $val;
			}
			return null;
		}
		$pending[$key] = $val;
	}

	protected function subMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v52 = $this->L($lang, 'subsection_banners', $key, ...$rest)) ? $v52 : '');
	}

	protected function buildSummaryAliases($lang = null) {
		$a = new Table([]);
		foreach (Lua::ipairs($this->SUMMARY_WARNING_BANNER_KEYS) as $_ => $key) {
			$alias = $this->subMsg($lang, ($key . '_alias'));
			$alias = Lua::trim(Lua::str((Lua::truth($v53 = $alias) ? $v53 : '')));
			if (Lua::truth(($alias !== ''))) {
				foreach (Lua::ipairs($this->splitCSV($alias, ',')) as $_ => $one) {
					$one = Lua::trim(Lua::str((Lua::truth($v54 = $one) ? $v54 : '')));
					if (Lua::truth(($one !== ''))) {
						$a[$one] = $key;
					}
				}
			}
		}
		return $a;
	}

	protected function buildSummaryLabelLookup($lang = null, $keys = null) {
		$lookup = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v55 = $keys) ? $v55 : new Table([]))) as $_ => $key) {
			$lab = Lua::trim($this->subMsg($lang, ($key . '_label')));
			if (Lua::truth(($lab !== ''))) {
				$lookup[$lab] = $key;
			}
		}
		return $lookup;
	}

	protected function resolveKeyFromLookup($raw = null, $lookup = null, $allowedKeys = null, $aliases = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v56 = $raw) ? $v56 : '')));
		if (Lua::truth(($raw === ''))) {
			return '';
		}
		$k = (Lua::truth($v57 = (Lua::truth($v58 = $lookup) ? $lookup[$raw] : $v58)) ? $v57 : '');
		if (Lua::truth(($k !== ''))) {
			return $k;
		}
		if (Lua::truth((Lua::truth($v59 = $aliases) ? $aliases[$raw] : $v59))) {
			return $aliases[$raw];
		}
		if (Lua::truth($allowedKeys)) {
			foreach (Lua::ipairs($allowedKeys) as $_ => $kk) {
				if (Lua::truth(($raw === $kk))) {
					return $kk;
				}
			}
		}
		return '';
	}

	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, $opts = null, $cats = null) {
		$key = Lua::str((Lua::truth($v60 = $key) ? $v60 : ''));
		if (Lua::truth(($key === ''))) {
			return '';
		}
		$opts = (Lua::truth($v61 = $opts) ? $v61 : new Table([]));
		$isReorg = ($opts['reorg'] === true);
		$icon = Lua::str((Lua::truth($v62 = $opts['icon']) ? $v62 : ''));
		$linkHtml = '';
		$title = '';
		$text = '';
		if (Lua::truth($isReorg)) {
			$formName = Lua::str((Lua::truth($v63 = $opts['form']) ? $v63 : ''));
			$tooltip = Lua::str((Lua::truth($v64 = $opts['tooltip']) ? $v64 : ''));
			$linkLabel = Lua::str((Lua::truth($v65 = $opts['link_label']) ? $v65 : ''));
			if (Lua::truth(($linkLabel === ''))) {
				$linkLabel = $this->L($lang, 'text', 'reorganize_link_label');
			}
			if (Lua::truth(($formName !== ''))) {
				$linkHtml = $this->addDataLink($formName, $pv['rawTitle'], $linkLabel, $tooltip);
			}
		}
		if (Lua::truth((Lua::truth($v66 = $isReorg) ? ($linkHtml !== '') : $v66))) {
			$title = $this->subMsg($lang, ($key . '_title'));
			$title = Lua::trim(Lua::str((Lua::truth($v67 = $title) ? $v67 : '')));
			if (Lua::truth(($title !== ''))) {
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
		if (Lua::truth((Lua::truth($v68 = ($title === '')) ? ($text === '') : $v68))) {
			return '';
		}
		$html = ('<div class="bandeau-avertissement wk-icon-before wk-icon-sensible">' . $title . '<span class="smw-highlighter smwttinline wk-icon-before wk-icon-help mw-no-invert" data-state="inline">' . '<div class="smwttcontent">' . $text . '</div>' . '</span>' . '</div>');
		$cat = $this->subMsg($lang, ($key . '_category'));
		if (Lua::truth((Lua::truth($v69 = (Lua::truth($v70 = $cats) ? $cat : $v70)) ? ($cat !== '') : $v69))) {
			Lua::insert($cats, ('[[Category:' . $cat . ']]'));
		}
		return $html;
	}

	protected function wkParseBiblioMarkers($raw = null) {
		$raw = Lua::str((Lua::truth($v71 = $raw) ? $v71 : ''));
		$raw = Lua::trim($raw);
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		if (Lua::truth((Lua::truth($v72 = !Lua::truth(Lua::find($raw, $this->FIELD_SEP, 1, true))) ? !Lua::truth(Lua::find($raw, $this->ITEM_SEP, 1, true)) : $v72))) {
			return new Table([]);
		}
		$items = new Table([]);
		$blocks = $this->splitByLiteral($raw, $this->ITEM_SEP);
		foreach (Lua::ipairs($blocks) as $_ => $block) {
			$block = Lua::trim($block);
			if (Lua::truth(($block !== ''))) {
				$f = new Table([]);
				$parts = $this->splitByLiteral($block, $this->FIELD_SEP);
				foreach (Lua::ipairs($parts) as $_ => $part) {
					$f[(Lua::len($f) + 1)] = Lua::trim(Lua::str((Lua::truth($v73 = $part) ? $v73 : '')));
				}
				$stop5 = 12;
				for ($i = (Lua::len($f) + 1); $i <= $stop5; $i++) {
					$f[$i] = '';
				}
				$items[(Lua::len($items) + 1)] = new Table(['authors' => $f[1], 'article' => $f[2], 'work' => $f[3], 'volume' => $f[4], 'number' => $f[5], 'location' => $f[6], 'page' => $f[7], 'publisher' => $f[8], 'place' => $f[9], 'date' => $f[10], 'link' => $f[11], 'warnings' => $f[12]]);
			}
		}
		return $items;
	}

	protected function wkParseWeblioMarkers($raw = null) {
		$raw = Lua::str((Lua::truth($v74 = $raw) ? $v74 : ''));
		$raw = Lua::trim($raw);
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		if (Lua::truth((Lua::truth($v75 = !Lua::truth(Lua::find($raw, $this->FIELD_SEP, 1, true))) ? !Lua::truth(Lua::find($raw, $this->ITEM_SEP, 1, true)) : $v75))) {
			return new Table([]);
		}
		$items = new Table([]);
		$blocks = $this->splitByLiteral($raw, $this->ITEM_SEP);
		foreach (Lua::ipairs($blocks) as $_ => $block) {
			$block = Lua::trim($block);
			if (Lua::truth(($block !== ''))) {
				$f = new Table([]);
				$parts = $this->splitByLiteral($block, $this->FIELD_SEP);
				foreach (Lua::ipairs($parts) as $_ => $part) {
					$f[(Lua::len($f) + 1)] = Lua::trim(Lua::str((Lua::truth($v76 = $part) ? $v76 : '')));
				}
				$stop5 = 6;
				for ($i = (Lua::len($f) + 1); $i <= $stop5; $i++) {
					$f[$i] = '';
				}
				$items[(Lua::len($items) + 1)] = new Table(['page' => $f[1], 'site' => $f[2], 'link' => $f[3], 'authors' => $f[4], 'date' => $f[5], 'warnings' => $f[6]]);
			}
		}
		return $items;
	}

	protected function wkParseVideoMarkers($raw = null) {
		$raw = Lua::str((Lua::truth($v77 = $raw) ? $v77 : ''));
		$raw = Lua::trim($raw);
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		if (Lua::truth((Lua::truth($v78 = !Lua::truth(Lua::find($raw, $this->FIELD_SEP, 1, true))) ? !Lua::truth(Lua::find($raw, $this->ITEM_SEP, 1, true)) : $v78))) {
			return new Table([]);
		}
		$items = new Table([]);
		$blocks = $this->splitByLiteral($raw, $this->ITEM_SEP);
		foreach (Lua::ipairs($blocks) as $_ => $block) {
			$block = Lua::trim($block);
			if (Lua::truth(($block !== ''))) {
				$f = new Table([]);
				$parts = $this->splitByLiteral($block, $this->FIELD_SEP);
				foreach (Lua::ipairs($parts) as $_ => $part) {
					$f[(Lua::len($f) + 1)] = Lua::trim(Lua::str((Lua::truth($v79 = $part) ? $v79 : '')));
				}
				$stop5 = 4;
				for ($i = (Lua::len($f) + 1); $i <= $stop5; $i++) {
					$f[$i] = '';
				}
				$items[(Lua::len($items) + 1)] = new Table(['title' => $f[1], 'link' => $f[2], 'authors' => $f[3], 'warnings' => $f[4]]);
			}
		}
		return $items;
	}

	protected function renderReferenceItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, $out = null) {
		$warnsRef = Lua::trim(Lua::str((Lua::truth($v80 = $itemWarnings) ? $v80 : '')));
		if (Lua::truth(($warnsRef === ''))) {
			return null;
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->REFERENCE_WARNING_BANNER_KEYS);
		foreach (Lua::ipairs($this->splitCSV($warnsRef, ',')) as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->REFERENCE_WARNING_BANNER_KEYS);
			if (Lua::truth(($key !== ''))) {
				Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, $cats));
			}
		}
	}

	protected function wkRenderOneBiblioLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$out = new Table([]);
		$authors = Lua::trim((Lua::truth($v81 = $item['authors']) ? $v81 : ''));
		if (Lua::truth(($authors === ''))) {
			$authors = $PF['unknown_author'];
		}
		$out[(Lua::len($out) + 1)] = $authors;
		{
			$k = (Lua::truth($v82 = (Lua::truth($v83 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v83)) ? $v82 : '');
			if (Lua::truth(($k !== ''))) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
		$article = Lua::trim((Lua::truth($v84 = $item['article']) ? $v84 : ''));
		$work = Lua::trim((Lua::truth($v85 = $item['work']) ? $v85 : ''));
		$link = Lua::trim((Lua::truth($v86 = $item['link']) ? $v86 : ''));
		{
			if (Lua::truth($smwPending)) {
				$ka = $this->smwPropRef($PF, 'article_name');
				if (Lua::truth((Lua::truth($v87 = ($ka !== '')) ? ($article !== '') : $v87))) {
					$this->smwQueueAdd($smwPending, $ka, $article, true);
				}
				$kw = $this->smwPropRef($PF, 'work_name');
				if (Lua::truth((Lua::truth($v88 = ($kw !== '')) ? ($work !== '') : $v88))) {
					$this->smwQueueAdd($smwPending, $kw, $work, true);
				}
			}
		}
		if (Lua::truth(($article !== ''))) {
			if (Lua::truth(($link !== ''))) {
				$out[(Lua::len($out) + 1)] = (', « [' . $link . ' ' . $article . '] »');
			}
			else {
				$out[(Lua::len($out) + 1)] = (', « ' . $article . ' »');
			}
		}
		if (Lua::truth(($work !== ''))) {
			if (Lua::truth(($article !== ''))) {
				$out[(Lua::len($out) + 1)] = (', \'\'' . $work . '\'\'');
			}
			else {
				if (Lua::truth(($link !== ''))) {
					$out[(Lua::len($out) + 1)] = (', \'\'[' . $link . ' ' . $work . ']\'\'');
				}
				else {
					$out[(Lua::len($out) + 1)] = (', \'\'' . $work . '\'\'');
				}
			}
		}
		$volume = Lua::trim((Lua::truth($v89 = $item['volume']) ? $v89 : ''));
		if (Lua::truth(($volume !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $PF['volume_label'] . '&#160;' . $volume);
		}
		$number = Lua::trim((Lua::truth($v90 = $item['number']) ? $v90 : ''));
		if (Lua::truth(($number !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $PF['issue_label'] . '&#160;' . $number);
		}
		$location = Lua::trim((Lua::truth($v91 = $item['location']) ? $v91 : ''));
		if (Lua::truth(($location !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $location);
		}
		$page = Lua::trim((Lua::truth($v92 = $item['page']) ? $v92 : ''));
		if (Lua::truth(($page !== ''))) {
			$out[(Lua::len($out) + 1)] = (', p.' . $page);
		}
		$publisher = Lua::trim((Lua::truth($v93 = $item['publisher']) ? $v93 : ''));
		if (Lua::truth(($publisher !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $publisher);
			{
				$kp = (Lua::truth($v94 = (Lua::truth($v95 = $smwPending) ? $this->smwPropRef($PF, 'publishing_house') : $v95)) ? $v94 : '');
				if (Lua::truth(($kp !== ''))) {
					$this->smwQueueAdd($smwPending, $kp, $publisher, true);
				}
			}
		}
		$place = Lua::trim((Lua::truth($v96 = $item['place']) ? $v96 : ''));
		if (Lua::truth(($place !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $place);
			{
				$kp = (Lua::truth($v97 = (Lua::truth($v98 = $smwPending) ? $this->smwPropRef($PF, 'place_of_publication') : $v98)) ? $v97 : '');
				if (Lua::truth(($kp !== ''))) {
					$this->smwQueueAdd($smwPending, $kp, $place, true);
				}
			}
		}
		$date = Lua::trim((Lua::truth($v99 = $item['date']) ? $v99 : ''));
		if (Lua::truth(($date !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderOneWeblioLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$out = new Table([]);
		$page = Lua::trim((Lua::truth($v100 = $item['page']) ? $v100 : ''));
		$site = Lua::trim((Lua::truth($v101 = $item['site']) ? $v101 : ''));
		$link = Lua::trim((Lua::truth($v102 = $item['link']) ? $v102 : ''));
		$date = Lua::trim((Lua::truth($v103 = $item['date']) ? $v103 : ''));
		$authors = Lua::trim((Lua::truth($v104 = $item['authors']) ? $v104 : ''));
		{
			$k = (Lua::truth($v105 = (Lua::truth($v106 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v106)) ? $v105 : '');
			if (Lua::truth((Lua::truth($v107 = ($k !== '')) ? ($authors !== '') : $v107))) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
		if (Lua::truth(($authors !== ''))) {
			$out[(Lua::len($out) + 1)] = $authors;
		}
		{
			$label = '';
			if (Lua::truth(($page !== ''))) {
				$label = $page;
			}
			else {
				if (Lua::truth(($site !== ''))) {
					$label = $site;
				}
			}
			if (Lua::truth((Lua::truth($v108 = ($link !== '')) ? ($label !== '') : $v108))) {
				$out[(Lua::len($out) + 1)] = ((Lua::truth($v109 = (Lua::truth($v110 = ($authors !== '')) ? ', ' : $v110)) ? $v109 : '') . '[' . $link . ' ' . $label . ']');
			}
			else {
				$out[(Lua::len($out) + 1)] = ((Lua::truth($v111 = (Lua::truth($v112 = ($authors !== '')) ? ', ' : $v112)) ? $v111 : '') . (Lua::truth($v113 = (Lua::truth($v114 = ($page !== '')) ? $page : $v114)) ? $v113 : $site));
			}
		}
		if (Lua::truth((Lua::truth($v115 = ($site !== '')) ? ($page !== '') : $v115))) {
			$out[(Lua::len($out) + 1)] = (', \'\'' . $site . '\'\'');
		}
		if (Lua::truth(($date !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderOneVideoLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$out = new Table([]);
		$title = Lua::trim((Lua::truth($v116 = $item['title']) ? $v116 : ''));
		$link = Lua::trim((Lua::truth($v117 = $item['link']) ? $v117 : ''));
		$authors = Lua::trim((Lua::truth($v118 = $item['authors']) ? $v118 : ''));
		{
			$k = (Lua::truth($v119 = (Lua::truth($v120 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v120)) ? $v119 : '');
			if (Lua::truth((Lua::truth($v121 = ($k !== '')) ? ($authors !== '') : $v121))) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
		if (Lua::truth(($authors !== ''))) {
			$out[(Lua::len($out) + 1)] = $authors;
		}
		if (Lua::truth((Lua::truth($v122 = ($link !== '')) ? ($title !== '') : $v122))) {
			$out[(Lua::len($out) + 1)] = ((Lua::truth($v123 = (Lua::truth($v124 = ($authors !== '')) ? ', ' : $v124)) ? $v123 : '') . '[' . $link . ' ' . $title . ']');
		}
		else {
			if (Lua::truth(($title !== ''))) {
				$out[(Lua::len($out) + 1)] = ((Lua::truth($v125 = (Lua::truth($v126 = ($authors !== '')) ? ', ' : $v126)) ? $v125 : '') . $title);
			}
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderReferencesFromRaw($rawB = null, $rawW = null, $rawV = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		[$b, $w, $v] = [new Table([]), new Table([]), new Table([])];
		foreach (Lua::ipairs($this->wkParseBiblioMarkers($rawB)) as $_ => $it) {
			$b[(Lua::len($b) + 1)] = $this->wkRenderOneBiblioLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		foreach (Lua::ipairs($this->wkParseWeblioMarkers($rawW)) as $_ => $it) {
			$w[(Lua::len($w) + 1)] = $this->wkRenderOneWeblioLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		foreach (Lua::ipairs($this->wkParseVideoMarkers($rawV)) as $_ => $it) {
			$v[(Lua::len($v) + 1)] = $this->wkRenderOneVideoLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		if (Lua::truth((Lua::truth($v127 = (Lua::truth($v128 = (Lua::len($b) === 0)) ? (Lua::len($w) === 0) : $v128)) ? (Lua::len($v) === 0) : $v127))) {
			return '';
		}
		$out = Lua::concat(new Table([1 => Lua::concat($b, '
'), 2 => Lua::concat($w, '
'), 3 => Lua::concat($v, '
')]), '
');
		$out = Lua::trim($out);
		return $out;
	}


	protected function smwKeyFromArray($q = null) {
		if (Lua::truth((Lua::type($q) !== 'table'))) {
			$s = Lua::str((Lua::truth($v130 = $q) ? $v130 : ''));
			return (Lua::str(Lua::len($s)) . ':' . $s);
		}
		$idx = new Table([]);
		$n = 0;
		foreach (Lua::pairs($q) as $k => $_value) {
			if (Lua::truth((Lua::type($k) === 'number'))) {
				$n = ($n + 1);
				$idx[$n] = $k;
			}
		}
		if (Lua::truth(($n === 0))) {
			return '';
		}
		Lua::sort($idx);
		$parts = new Table([]);
		$stop13 = $n;
		for ($i = 1; $i <= $stop13; $i++) {
			$v = Lua::str((Lua::truth($v131 = $q[$idx[$i]]) ? $v131 : ''));
			$parts[$i] = (Lua::str(Lua::len($v)) . ':' . $v);
		}
		return Lua::concat($parts, '
');
	}

	protected function getPageCreation($pv = null, $args = null) {
		$v = (Lua::truth($v132 = $args) ? $args['creation-date'] : $v132);
		$v = (Lua::truth($v133 = (Lua::truth($v134 = (Lua::type($v) === 'string')) ? Lua::trim($v) : $v134)) ? $v133 : '');
		if (Lua::truth(($v !== ''))) {
			return ($v . 'T00:00:00Z');
		}
		return null;
	}

	protected function makeSMWCache() {
		return new Table(['debatsPour' => null, 'debatsContre' => null, 'justifs' => null, 'objs' => null, 'debatsTousFinal' => null, 'debatCardsTous' => null, 'debatCardsPour' => null, 'debatCardsContre' => null, 'argCardsJustifs' => null, 'argCardsObjs' => null, 'breadcrumbChainsFinal' => null, 'parentDebatesFinal' => null]);
	}

	protected function renderTitleWarnings($pv = null, $cats = null, $lang = null) {
		$title = (Lua::truth($v135 = $pv['rawTitle']) ? $v135 : '');
		if (Lua::truth(($title === ''))) {
			return '';
		}
		$out = new Table([]);
		$n = 0;
		foreach (Lua::split($title, '%s+') as $w) {
			$w = Lua::trim($w);
			if (Lua::truth(($w !== ''))) {
				$n = ($n + 1);
				if (Lua::truth(($n >= 3))) {
					break;
				}
			}
		}
		if (Lua::truth((Lua::truth($v136 = ($n > 0)) ? ($n < 3) : $v136))) {
			Lua::insert($out, ('<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $this->L($lang, 'text', 'title_too_short_msg') . ' [[Special:MovePage/' . $pv['encoded'] . '|' . $this->L($lang, 'text', 'rename_page_label') . ']]' . ' ' . $this->L($lang, 'text', 'if_needed_msg') . '\'\'\'</div>'));
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'title_very_short') . ']]'));
		}
		if (Lua::truth((Lua::truth($v137 = (Lua::sub($title, (-1)) === '.')) ? (Lua::sub($title, (-4)) !== 'etc.') : $v137))) {
			Lua::insert($out, ('<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $this->L($lang, 'text', 'title_ends_with_dot_msg') . ' [[Special:MovePage/' . $pv['encoded'] . '|' . $this->L($lang, 'text', 'rename_page_label') . ']]' . '\'\'\'</div>'));
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'title_ends_with_dot') . ']]'));
		}
		if (Lua::truth(($lang === 'fr'))) {
			$prefix3 = Lua::sub($title, 1, 3);
			$prefix4 = Lua::sub($title, 1, 4);
			$prefix5 = Lua::sub($title, 1, 5);
			$prefix6 = Lua::sub($title, 1, 6);
			$prefix10 = Lua::sub($title, 1, 10);
			$prefix13 = Lua::sub($title, 1, 13);
			if (Lua::truth(($prefix3 === 'Ça'))) {
				Lua::insert($cats, '[[Category:Titres d\'arguments commençant par "Cela"]]');
			}
			else {
				if (Lua::truth(($prefix3 === 'Sa'))) {
					Lua::insert($cats, '[[Category:Titres d\'arguments commençant par "Sa"]]');
				}
			}
			$map4 = new Table(['Ces' => 'Titres d\'arguments commençant par "Ces"', 'Cet' => 'Titres d\'arguments commençant par "Cet"', 'Ils' => 'Titres d\'arguments commençant par "Ils"', 'Ses' => 'Titres d\'arguments commençant par "Ses"', 'Son' => 'Titres d\'arguments commençant par "Son"']);
			if (Lua::truth($map4[$prefix4])) {
				Lua::insert($cats, ('[[Category:' . $map4[$prefix4] . ']]'));
			}
			$map5 = new Table(['Cela' => 'Titres d\'arguments commençant par "Cela"', 'Elle' => 'Titres d\'arguments commençant par "Elle"', 'Leur' => 'Titres d\'arguments commençant par "Leur"', 'Leurs' => 'Titres d\'arguments commençant par "Leurs"', 'Non,' => 'Titres d\'arguments commençant par "Non"', 'Nous' => 'Titres d\'arguments commençant par "Nous"', 'Oui,' => 'Titres d\'arguments commençant par "Oui"']);
			if (Lua::truth($map5[$prefix5])) {
				Lua::insert($cats, ('[[Category:' . $map5[$prefix5] . ']]'));
			}
			$map6 = new Table(['Cette' => 'Titres d\'arguments commençant par "Cette"', 'Elles' => 'Titres d\'arguments commençant par "Elles"']);
			if (Lua::truth($map6[$prefix6])) {
				Lua::insert($cats, ('[[Category:' . $map6[$prefix6] . ']]'));
			}
			$map10 = new Table(['C\'est faux' => 'Titres d\'arguments commençant par "C\'est faux"', 'Hors-sujet' => 'Titres d\'arguments commençant par "Hors-sujet"']);
			if (Lua::truth($map10[$prefix10])) {
				Lua::insert($cats, ('[[Category:' . $map10[$prefix10] . ']]'));
			}
			if (Lua::truth(($prefix13 === 'Au contraire,'))) {
				Lua::insert($cats, '[[Category:Titres d\'arguments commençant par "Au contraire"]]');
			}
		}
		return Lua::concat($out);
	}

	protected function uniqList($list = null) {
		[$out, $seen] = [new Table([]), new Table([])];
		foreach (Lua::ipairs((Lua::truth($v138 = $list) ? $v138 : new Table([]))) as $_ => $x) {
			$x = Lua::trim(Lua::str((Lua::truth($v139 = $x) ? $v139 : '')));
			if (Lua::truth((Lua::truth($v140 = ($x !== '')) ? !Lua::truth($seen[$x]) : $v140))) {
				$seen[$x] = true;
				Lua::insert($out, $x);
			}
		}
		return $out;
	}

	protected function smwAskTitles($conditions = null, $limit = null) {
		$limit = (Lua::truth($v141 = Lua::number($limit)) ? $v141 : 200);
		$q = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v142 = $conditions) ? $v142 : new Table([]))) as $_ => $c) {
			Lua::insert($q, $c);
		}
		Lua::insert($q, ('limit=' . Lua::str($limit)));
		Lua::insert($q, 'link=none');
		$ck = ('TITLES
' . $this->smwKeyFromArray($q));
		$hit = $this->cacheGet($this->SMW_TITLES_CACHE, $ck);
		if (Lua::truth(($hit !== null))) {
			return $hit;
		}
		$res = $this->smwAskSafe($q);
		if (Lua::truth((Lua::type($res) !== 'table'))) {
			$this->cachePut($this->SMW_TITLES_CACHE, $ck, new Table([]));
			return new Table([]);
		}
		[$out, $seen] = [new Table([]), new Table([])];
		foreach (Lua::ipairs($res) as $_ => $row) {
			$ft = (Lua::truth($v143 = (Lua::truth($v144 = $row['fulltext']) ? $v144 : $row['page'])) ? $v143 : $row[1]);
			if (Lua::truth((Lua::truth($v145 = (Lua::truth($v146 = (Lua::type($ft) === 'string')) ? ($ft !== '') : $v146)) ? !Lua::truth($seen[$ft]) : $v145))) {
				$seen[$ft] = true;
				Lua::insert($out, $ft);
			}
		}
		$this->cachePut($this->SMW_TITLES_CACHE, $ck, $out);
		return $out;
	}

	protected function normTitle($s = null) {
		$s = Lua::str((Lua::truth($v147 = $s) ? $v147 : ''));
		$s = Lua::gsub($s, '_', ' ');
		return Lua::trim($s);
	}

	protected function smwSafeTitle($s = null) {
		$s = $this->canonicalTitle($s);
		if (Lua::truth(($s === ''))) {
			return '';
		}
		$s = Lua::gsub($s, '#.*$', '');
		$s = Lua::trim($s);
		if (Lua::truth(($s === ''))) {
			return '';
		}
		if (Lua::truth(Lua::find($s, '[%[%]{}|<>]'))) {
			return '';
		}
		return $s;
	}

	protected function containsTitle($val = null, $target = null) {
		$target = $this->normTitle($target);
		if (Lua::truth((Lua::truth($v148 = ($target === '')) ? $v148 : ($val === null)))) {
			return false;
		}
		$checkOne = function($x = null) use (&$val, &$target, &$checkOne, &$ft, &$_, &$y) {
		if (Lua::truth(($x === null))) {
			return false;
		}
		if (Lua::truth((Lua::type($x) === 'string'))) {
			return ($this->normTitle($x) === $target);
		}
		if (Lua::truth((Lua::type($x) === 'table'))) {
			$ft = (Lua::truth($v149 = (Lua::truth($v150 = (Lua::truth($v151 = $x['fulltext']) ? $v151 : $x['page'])) ? $v150 : $x['name'])) ? $v149 : $x['title']);
			if (Lua::truth((Lua::truth($v152 = (Lua::type($ft) === 'string')) ? ($this->normTitle($ft) === $target) : $v152))) {
				return true;
			}
			foreach (Lua::ipairs($x) as $_ => $y) {
				if (Lua::truth($checkOne($y))) {
					return true;
				}
			}
		}
		return false;
		};
		return $checkOne($val);
	}

	protected function ensureDebateParents($pv = null, $cache = null, $PF = null) {
		if (Lua::truth((Lua::truth($v153 = !Lua::truth($cache)) ? $v153 : ($cache['debatsPour'] !== null)))) {
			return null;
		}
		$argTitle = $this->smwSafeTitle($pv['rawTitle']);
		$propPro = Lua::str((Lua::truth($v154 = $PF['prop_arg_pro']) ? $v154 : ''));
		$propCon = Lua::str((Lua::truth($v155 = $PF['prop_arg_con']) ? $v155 : ''));
		if (Lua::truth((Lua::truth($v156 = (Lua::truth($v157 = ($propPro === '')) ? $v157 : ($propCon === ''))) ? $v156 : ($argTitle === '')))) {
			$cache['debatsPour'] = new Table([]);
			$cache['debatsContre'] = new Table([]);
			return null;
		}
		$q = new Table([1 => ('([[' . $propPro . '::' . $argTitle . ']] OR [[' . $propCon . '::' . $argTitle . ']] )'), 2 => ('?' . $propPro . '=pro'), 3 => ('?' . $propCon . '=con'), 4 => 'limit=200', 5 => 'link=none']);
		$res = $this->smwAskSafe($q);
		[$debatsPour, $debatsContre] = [new Table([]), new Table([])];
		[$seenP, $seenC] = [new Table([]), new Table([])];
		if (Lua::truth((Lua::type($res) === 'table'))) {
			foreach (Lua::ipairs($res) as $_ => $row) {
				$ft = (Lua::truth($v158 = (Lua::truth($v159 = $row['fulltext']) ? $v159 : $row['page'])) ? $v158 : $row[1]);
				if (Lua::truth((Lua::truth($v160 = (Lua::type($ft) === 'string')) ? ($ft !== '') : $v160))) {
					if (Lua::truth((Lua::truth($v161 = $this->containsTitle($row['pro'], $argTitle)) ? !Lua::truth($seenP[$ft]) : $v161))) {
						$seenP[$ft] = true;
						Lua::insert($debatsPour, $ft);
					}
					if (Lua::truth((Lua::truth($v162 = $this->containsTitle($row['con'], $argTitle)) ? !Lua::truth($seenC[$ft]) : $v162))) {
						$seenC[$ft] = true;
						Lua::insert($debatsContre, $ft);
					}
				}
			}
		}
		if (Lua::truth((Lua::truth($v163 = (Lua::len($debatsPour) === 0)) ? (Lua::len($debatsContre) === 0) : $v163))) {
			$debatsPour = $this->uniqList($this->smwAskTitles(new Table([1 => ('[[' . $propPro . '::' . $argTitle . ']]')]), 200));
			$debatsContre = $this->uniqList($this->smwAskTitles(new Table([1 => ('[[' . $propCon . '::' . $argTitle . ']]')]), 200));
		}
		$cache['debatsPour'] = $debatsPour;
		$cache['debatsContre'] = $debatsContre;
	}

	protected function ensureArgumentParents($pv = null, $cache = null, $PF = null) {
		if (Lua::truth((Lua::truth($v164 = !Lua::truth($cache)) ? $v164 : ($cache['justifs'] !== null)))) {
			return null;
		}
		$argTitle = $this->smwSafeTitle($pv['rawTitle']);
		$propJustif = Lua::str((Lua::truth($v165 = $PF['prop_justif']) ? $v165 : ''));
		$propObj = Lua::str((Lua::truth($v166 = $PF['prop_obj']) ? $v166 : ''));
		if (Lua::truth((Lua::truth($v167 = (Lua::truth($v168 = ($propJustif === '')) ? $v168 : ($propObj === ''))) ? $v167 : ($argTitle === '')))) {
			$cache['justifs'] = new Table([]);
			$cache['objs'] = new Table([]);
			return null;
		}
		$q = new Table([1 => ('([[' . $propJustif . '::' . $argTitle . ']] OR [[' . $propObj . '::' . $argTitle . ']] )'), 2 => ('?' . $propJustif . '=justif'), 3 => ('?' . $propObj . '=obj'), 4 => 'limit=200', 5 => 'link=none']);
		$res = $this->smwAskSafe($q);
		[$justifs, $objs] = [new Table([]), new Table([])];
		[$seenJ, $seenO] = [new Table([]), new Table([])];
		if (Lua::truth((Lua::type($res) === 'table'))) {
			foreach (Lua::ipairs($res) as $_ => $row) {
				$ft = (Lua::truth($v169 = (Lua::truth($v170 = $row['fulltext']) ? $v170 : $row['page'])) ? $v169 : $row[1]);
				if (Lua::truth((Lua::truth($v171 = (Lua::type($ft) === 'string')) ? ($ft !== '') : $v171))) {
					if (Lua::truth((Lua::truth($v172 = $this->containsTitle($row['justif'], $argTitle)) ? !Lua::truth($seenJ[$ft]) : $v172))) {
						$seenJ[$ft] = true;
						Lua::insert($justifs, $ft);
					}
					if (Lua::truth((Lua::truth($v173 = $this->containsTitle($row['obj'], $argTitle)) ? !Lua::truth($seenO[$ft]) : $v173))) {
						$seenO[$ft] = true;
						Lua::insert($objs, $ft);
					}
				}
			}
		}
		if (Lua::truth((Lua::truth($v174 = (Lua::len($justifs) === 0)) ? (Lua::len($objs) === 0) : $v174))) {
			$justifs = $this->uniqList($this->smwAskTitles(new Table([1 => ('[[' . $propJustif . '::' . $argTitle . ']]')]), 200));
			$objs = $this->uniqList($this->smwAskTitles(new Table([1 => ('[[' . $propObj . '::' . $argTitle . ']]')]), 200));
		}
		$cache['justifs'] = $justifs;
		$cache['objs'] = $objs;
	}

	protected function breadcrumbLenByteSafe($ch = null) {
		if (Lua::truth((Lua::truth($v175 = (Lua::type($ch) !== 'string')) ? $v175 : ($ch === '')))) {
			return 0;
		}
		$sep = '⟭';
		$seplen = 3;
		$n = 0;
		$i = 1;
		$Ls = Lua::len($ch);
		$trimAscii = function($s = null) use (&$ch, &$sep, &$seplen, &$n, &$i, &$Ls, &$trimAscii, &$j, &$part) {
		return (Lua::truth($v176 = Lua::match(Lua::str((Lua::truth($v177 = $s) ? $v177 : '')), '^%s*(.-)%s*$')) ? $v176 : '');
		};
		while (Lua::truth(($i <= ($Ls + 1)))) {
			$j = Lua::find($ch, $sep, $i, true);
			$part = null;
			if (Lua::truth($j)) {
				$part = Lua::sub($ch, $i, ($j - 1));
				$i = ($j + $seplen);
			}
			else {
				$part = Lua::sub($ch, $i);
				$i = ($Ls + 2);
			}
			if (Lua::truth(($trimAscii($part) !== ''))) {
				$n = ($n + 1);
				if (Lua::truth(($n >= 6))) {
					return $n;
				}
			}
		}
		return $n;
	}

	protected function ensureDebateParentsFinal($pv = null, $cache = null, $PF = null) {
		if (Lua::truth((Lua::truth($v178 = !Lua::truth($cache)) ? $v178 : ($cache['debatsTousFinal'] !== null)))) {
			return null;
		}
		$final = new Table([]);
		$this->ensureDebateParents($pv, $cache, $PF);
		foreach (Lua::ipairs((Lua::truth($v179 = $cache['debatsPour']) ? $v179 : new Table([]))) as $_ => $d) {
			Lua::insert($final, $this->canonicalTitle($d));
		}
		foreach (Lua::ipairs((Lua::truth($v180 = $cache['debatsContre']) ? $v180 : new Table([]))) as $_ => $d) {
			Lua::insert($final, $this->canonicalTitle($d));
		}
		if (Lua::truth($this->hasSMW())) {
			$this->ensureArgumentParents($pv, $cache, $PF);
			$propBreadcrumb = Lua::str((Lua::truth($v181 = $PF['prop_breadcrumb']) ? $v181 : ''));
			$propParentDebate = Lua::str((Lua::truth($v182 = $PF['prop_parent_debate']) ? $v182 : ''));
			if (Lua::truth((Lua::truth($v183 = ($propBreadcrumb !== '')) ? ($propParentDebate !== '') : $v183))) {
				$parents = new Table([]);
				foreach (Lua::ipairs((Lua::truth($v184 = $cache['justifs']) ? $v184 : new Table([]))) as $_ => $a) {
					Lua::insert($parents, $this->canonicalTitle($a));
				}
				foreach (Lua::ipairs((Lua::truth($v185 = $cache['objs']) ? $v185 : new Table([]))) as $_ => $a) {
					Lua::insert($parents, $this->canonicalTitle($a));
				}
				$parents = $this->uniqList($parents);
				if (Lua::truth((Lua::len($parents) > 0))) {
					$conds = new Table([]);
					foreach (Lua::ipairs($parents) as $_ => $t) {
						$safe = $this->smwSafeTitle($t);
						if (Lua::truth(($safe !== ''))) {
							Lua::insert($conds, ('[[' . $safe . ']]'));
						}
					}
					if (Lua::truth((Lua::len($conds) > 0))) {
						$q = new Table([1 => ('(' . Lua::concat($conds, ' OR ') . ')'), 2 => ('?' . $propBreadcrumb . '=chain'), 3 => ('?' . $propParentDebate . '=pdeb'), 4 => 'limit=200', 5 => 'link=none']);
						$res = $this->smwAskSafe($q);
						if (Lua::truth((Lua::type($res) === 'table'))) {
							foreach (Lua::ipairs($res) as $_ => $row) {
								$chain = $row['chain'];
								$okLen = false;
								if (Lua::truth((Lua::type($chain) === 'string'))) {
									$n = $this->breadcrumbLenByteSafe($chain);
									$okLen = (Lua::truth($v186 = ($n > 0)) ? ($n < 6) : $v186);
								}
								else {
									if (Lua::truth((Lua::type($chain) === 'table'))) {
										foreach (Lua::ipairs($chain) as $_ => $ch) {
											if (Lua::truth((Lua::type($ch) === 'string'))) {
												$n = $this->breadcrumbLenByteSafe($ch);
												if (Lua::truth((Lua::truth($v187 = ($n > 0)) ? ($n < 6) : $v187))) {
													$okLen = true;
													break;
												}
											}
										}
									}
								}
								if (Lua::truth($okLen)) {
									$pdeb = $row['pdeb'];
									if (Lua::truth((Lua::truth($v188 = (Lua::type($pdeb) === 'string')) ? (Lua::trim($pdeb) !== '') : $v188))) {
										Lua::insert($final, $this->canonicalTitle($pdeb));
									}
									else {
										if (Lua::truth((Lua::type($pdeb) === 'table'))) {
											foreach (Lua::ipairs($pdeb) as $_ => $x) {
												if (Lua::truth((Lua::truth($v189 = (Lua::type($x) === 'string')) ? (Lua::trim($x) !== '') : $v189))) {
													Lua::insert($final, $this->canonicalTitle($x));
												}
												else {
													if (Lua::truth((Lua::type($x) === 'table'))) {
														$ft = (Lua::truth($v190 = (Lua::truth($v191 = $x['fulltext']) ? $v191 : $x['page'])) ? $v190 : $x[1]);
														if (Lua::truth((Lua::truth($v192 = (Lua::type($ft) === 'string')) ? (Lua::trim($ft) !== '') : $v192))) {
															Lua::insert($final, $this->canonicalTitle($ft));
														}
													}
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}
		$cache['debatsTousFinal'] = $this->uniqList($final);
	}

	protected function normalizeTitleForCard($s = null) {
		$s = Lua::trim(Lua::str((Lua::truth($v193 = $s) ? $v193 : '')));
		if (Lua::truth(($s === ''))) {
			return '';
		}
		if (Lua::truth((Lua::truth($v194 = (Lua::byte($s, 1) !== 58)) ? !Lua::truth(Lua::find($s, '[%[<&]')) : $v194))) {
			return $s;
		}
		$s = Lua::gsub($s, '^:%s*', '');
		{
			$target = (Lua::truth($v195 = Lua::match($s, '^%[%[([^%]|]+)%|.-%]%]$')) ? $v195 : Lua::match($s, '^%[%[([^%]]+)%]%]$'));
			if (Lua::truth((Lua::truth($v196 = $target) ? ($target !== '') : $v196))) {
				$s = $target;
			}
		}
		$s = Lua::gsub(Lua::trim($s), '^:%s*', '');
		if (Lua::truth(Lua::find($s, '&', 1, true))) {
			$s = Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub($s, '&nbsp;', ' '), '&#160;', ' '), '&amp;', '&'), '&quot;', '"'), '&#39;', '\''), '&lt;', '<'), '&gt;', '>');
		}
		return Lua::trim($s);
	}

	protected function mwUrlEncodePF($s = null) {
		$s = Lua::str((Lua::truth($v197 = $s) ? $v197 : ''));
		$hit = $this->cacheGet($this->URLENC_CACHE, $s);
		if (Lua::truth(($hit !== null))) {
			return $hit;
		}
		$out = $this->context->uriEncode($s, 'QUERY');
		$out = Lua::gsub($out, '%%20', '+');
		$this->cachePut($this->URLENC_CACHE, $s, $out);
		return $out;
	}

	protected function argumentMapCardLua($targetTitle = null, $currentArgTitle = null, $typeValue = null) {
		$t = $this->normalizeTitleForCard($targetTitle);
		$a = $this->normalizeTitleForCard($currentArgTitle);
		$p2 = $this->mwUrlEncodePF($a);
		$dataAttr = '';
		if (Lua::truth((Lua::truth($v198 = $typeValue) ? ($typeValue !== '') : $v198))) {
			$dataAttr = (' data-' . $typeValue . '="' . $this->escapeAttr($p2) . '"');
		}
		return ('<span class="hover-map"' . $dataAttr . '>' . '[[' . $t . ']]' . '</span>');
	}

	protected function cardRendered($kind = null, $targetTitle = null, $currentArgTitle = null, $typeValue = null) {
		$key = ($kind . '
' . Lua::str((Lua::truth($v199 = $targetTitle) ? $v199 : '')) . '
' . Lua::str((Lua::truth($v200 = $currentArgTitle) ? $v200 : '')) . '
' . Lua::str((Lua::truth($v201 = $typeValue) ? $v201 : '')));
		$cached = $this->cacheGet($this->CARD_CACHE, $key);
		if (Lua::truth(($cached !== null))) {
			return $cached;
		}
		$rendered = $this->argumentMapCardLua($targetTitle, $currentArgTitle, $typeValue);
		$this->cachePut($this->CARD_CACHE, $key, $rendered);
		return $rendered;
	}

	protected function cardDebateRendered($debateTitle = null, $currentArgTitle = null, $typeValue = null) {
		return $this->cardRendered('debate', $debateTitle, $currentArgTitle, $typeValue);
	}

	protected function cardArgumentRendered($argTitle = null, $currentArgTitle = null, $typeValue = null) {
		return $this->cardRendered('argument', $argTitle, $currentArgTitle, $typeValue);
	}

	protected function ensureDebateCards($pv = null, $cache = null, $lang = null, $PF = null) {
		if (Lua::truth((Lua::truth($v202 = !Lua::truth($cache)) ? $v202 : ($cache['debatCardsTous'] !== null)))) {
			return null;
		}
		$argTitle = $pv['rawTitle'];
		$debateKey = function($d = null) use (&$pv, &$cache, &$lang, &$PF, &$argTitle, &$debateKey, &$linked, &$_, &$k, &$cardsTous, &$typeDebate, &$cardsPour, &$cardsContre) {
		return $this->canonicalTitle($d);
		};
		$linked = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v203 = $cache['debatsPour']) ? $v203 : new Table([]))) as $_ => $d) {
			$k = $debateKey($d);
			if (Lua::truth(($k !== ''))) {
				$linked[$k] = true;
			}
		}
		foreach (Lua::ipairs((Lua::truth($v204 = $cache['debatsContre']) ? $v204 : new Table([]))) as $_ => $d) {
			$k = $debateKey($d);
			if (Lua::truth(($k !== ''))) {
				$linked[$k] = true;
			}
		}
		$cardsTous = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v205 = $cache['parentDebatesFinal']) ? $v205 : new Table([]))) as $_ => $d) {
			$k = $debateKey($d);
			$typeDebate = (Lua::truth($v206 = (Lua::truth($v207 = (Lua::truth($v208 = ($k !== '')) ? $linked[$k] : $v208)) ? 'debate' : $v207)) ? $v206 : '');
			Lua::insert($cardsTous, $this->cardDebateRendered($d, $argTitle, $typeDebate));
		}
		$cache['debatCardsTous'] = $cardsTous;
		$cardsPour = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v209 = $cache['debatsPour']) ? $v209 : new Table([]))) as $_ => $d) {
			Lua::insert($cardsPour, $this->cardDebateRendered($d, $argTitle, 'debate'));
		}
		$cache['debatCardsPour'] = $cardsPour;
		$cardsContre = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v210 = $cache['debatsContre']) ? $v210 : new Table([]))) as $_ => $d) {
			Lua::insert($cardsContre, $this->cardDebateRendered($d, $argTitle, 'debate'));
		}
		$cache['debatCardsContre'] = $cardsContre;
	}

	protected function ensureArgumentCards($pv = null, $cache = null, $PF = null) {
		if (Lua::truth((Lua::truth($v211 = !Lua::truth($cache)) ? $v211 : ($cache['argCardsJustifs'] !== null)))) {
			return null;
		}
		$this->ensureArgumentParents($pv, $cache, $PF);
		$argTitle = $pv['rawTitle'];
		$typeArgument = 'argument';
		$cj = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v212 = $cache['justifs']) ? $v212 : new Table([]))) as $_ => $a) {
			Lua::insert($cj, $this->cardArgumentRendered($a, $argTitle, $typeArgument));
		}
		$cache['argCardsJustifs'] = $cj;
		$co = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v213 = $cache['objs']) ? $v213 : new Table([]))) as $_ => $a) {
			Lua::insert($co, $this->cardArgumentRendered($a, $argTitle, $typeArgument));
		}
		$cache['argCardsObjs'] = $co;
	}

	protected function ensureDot($s = null) {
		$s = Lua::str((Lua::truth($v214 = $s) ? $v214 : ''));
		$s = Lua::trim($s);
		if (Lua::truth(($s === ''))) {
			return $s;
		}
		if (Lua::truth((Lua::sub($s, (-1)) === '.'))) {
			return $s;
		}
		return ($s . '.');
	}

	protected function renderParentBanner($iconClass = null, $texte = null) {
		return ('<div class="bandeau-en-tete searchaux wk-icon-before ' . $iconClass . '">' . $this->ensureDot($texte) . '</div>');
	}

	protected function bannerMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v215 = $this->context->message('Argument.banners', $lang, $key, ...$rest)) ? $v215 : '');
	}

	protected function renderMetaWarningHtml($params = null) {
		$color = Lua::str((Lua::truth($v216 = $params['color']) ? $v216 : ''));
		$icon = Lua::str((Lua::truth($v217 = $params['icon']) ? $v217 : ''));
		$title = Lua::str((Lua::truth($v218 = $params['title']) ? $v218 : ''));
		$text = Lua::str((Lua::truth($v219 = $params['text']) ? $v219 : ''));
		return ('<div class="bandeau bandeau-' . $color . ' wk-icon-before ' . $icon . ' navigation-not-searchable">' . '<div class="bandeau-titre>' . $title . '</div>' . '<div class="bandeau-texte">' . $text . '</div>' . '</div>');
	}

	protected function renderBannerByKey($pv = null, $lang = null, $key = null, $cats = null) {
		$key = Lua::str((Lua::truth($v220 = $key) ? $v220 : ''));
		if (Lua::truth(($key === ''))) {
			return '';
		}
		$color = $this->bannerMsg($lang, ($key . '_color'));
		$icon = $this->bannerMsg($lang, ($key . '_icon'));
		$title = $this->bannerMsg($lang, ($key . '_title'), $pv['rawTitle']);
		$text = $this->bannerMsg($lang, ($key . '_text'), $pv['rawTitle']);
		if (Lua::truth((Lua::truth($v221 = (Lua::truth($v222 = (Lua::truth($v223 = ($color === '')) ? ($icon === '') : $v223)) ? ($title === '') : $v222)) ? ($text === '') : $v221))) {
			return '';
		}
		$html = $this->renderMetaWarningHtml(new Table(['color' => $color, 'icon' => $icon, 'title' => $title, 'text' => $text]));
		$cat = $this->bannerMsg($lang, ($key . '_category'));
		if (Lua::truth((Lua::truth($v224 = (Lua::truth($v225 = $cats) ? $cat : $v225)) ? ($cat !== '') : $v224))) {
			Lua::insert($cats, ('[[Category:' . $cat . ']]'));
		}
		return $html;
	}

	protected function buildBannerLabelLookup($lang = null, $keys = null) {
		$lookup = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v226 = $keys) ? $v226 : new Table([]))) as $_ => $key) {
			$lab = Lua::trim($this->bannerMsg($lang, ($key . '_label')));
			if (Lua::truth(($lab !== ''))) {
				$lookup[$lab] = $key;
			}
		}
		return $lookup;
	}

	protected function resolveBannerKeyFromLookup($raw = null, $lookup = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v227 = $raw) ? $v227 : '')));
		if (Lua::truth(($raw === ''))) {
			return '';
		}
		return (Lua::truth($v228 = (Lua::truth($v229 = $lookup) ? $lookup[$raw] : $v229)) ? $v228 : '');
	}

	protected function renderBannersFromArgList($rawList = null, $lookup = null, $pv = null, $lang = null, $cats = null) {
		$html = new Table([]);
		foreach (Lua::ipairs($this->splitCSV($rawList, ',')) as $_ => $x) {
			$key = $this->resolveBannerKeyFromLookup($x, $lookup);
			if (Lua::truth(($key !== ''))) {
				Lua::insert($html, $this->renderBannerByKey($pv, $lang, $key, $cats));
			}
		}
		return Lua::concat($html);
	}

	protected function joinCards($cards = null, $lang = null) {
		$n = (Lua::truth($v230 = (Lua::truth($v231 = $cards) ? Lua::len($cards) : $v231)) ? $v230 : 0);
		if (Lua::truth(($n === 0))) {
			return '';
		}
		if (Lua::truth(($n === 1))) {
			return (Lua::truth($v232 = $cards[1]) ? $v232 : '');
		}
		$conj = $this->L($lang, 'text', 'list_conjunction_text');
		$conj = Lua::str((Lua::truth($v233 = $conj) ? $v233 : ' et '));
		$conj = Lua::gsub($conj, '%s+', '&#32;');
		if (Lua::truth(($n === 2))) {
			return ((Lua::truth($v234 = $cards[1]) ? $v234 : '') . $conj . (Lua::truth($v235 = $cards[2]) ? $v235 : ''));
		}
		$head = Lua::concat($cards, ', ', 1, ($n - 1));
		return ($head . $conj . (Lua::truth($v236 = $cards[$n]) ? $v236 : ''));
	}

	protected function renderDebateParentsBoxes($pv = null, $cats = null, $lang = null, $cache = null, $PF = null) {
		if (Lua::truth(!Lua::truth($this->hasSMW()))) {
			return '';
		}
		$this->ensureDebateParents($pv, $cache, $PF);
		$this->ensureDebateCards($pv, $cache, $lang, $PF);
		$out = new Table([]);
		$nTous = Lua::len((Lua::truth($v237 = $cache['parentDebatesFinal']) ? $v237 : new Table([])));
		if (Lua::truth(($nTous > 0))) {
			$msgKey = (Lua::truth($v238 = (Lua::truth($v239 = ($nTous === 1)) ? 'parent_used_in_debate_1' : $v239)) ? $v238 : 'parent_used_in_debate_n');
			$texte = Lua::format($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache['debatCardsTous'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-parent', $texte));
		}
		if (Lua::truth((Lua::truth($v240 = $cache['debatsPour']) ? (Lua::len($cache['debatsPour']) > 0) : $v240))) {
			$msgKey = (Lua::truth($v241 = (Lua::truth($v242 = (Lua::len($cache['debatsPour']) === 1)) ? 'parent_is_pro_in_1' : $v242)) ? $v241 : 'parent_is_pro_in_n');
			$texte = Lua::format($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache['debatCardsPour'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-argument is-pro', $texte));
		}
		if (Lua::truth((Lua::truth($v243 = $cache['debatsContre']) ? (Lua::len($cache['debatsContre']) > 0) : $v243))) {
			$msgKey = (Lua::truth($v244 = (Lua::truth($v245 = (Lua::len($cache['debatsContre']) === 1)) ? 'parent_is_con_in_1' : $v245)) ? $v244 : 'parent_is_con_in_n');
			$texte = Lua::format($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache['debatCardsContre'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-argument is-against', $texte));
		}
		return Lua::concat($out);
	}

	protected function renderArgumentParentsBoxes($pv = null, $cats = null, $lang = null, $cache = null, $PF = null) {
		if (Lua::truth(!Lua::truth($this->hasSMW()))) {
			return '';
		}
		$this->ensureArgumentParents($pv, $cache, $PF);
		$this->ensureArgumentCards($pv, $cache, $PF);
		$out = new Table([]);
		if (Lua::truth((Lua::truth($v246 = $cache['justifs']) ? (Lua::len($cache['justifs']) > 0) : $v246))) {
			$texte = Lua::format($PF['parent_is_justification_of'], (': ' . $this->joinCards($cache['argCardsJustifs'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-argument is-pro', $texte));
		}
		if (Lua::truth((Lua::truth($v247 = $cache['objs']) ? (Lua::len($cache['objs']) > 0) : $v247))) {
			$texte = Lua::format($PF['parent_is_objection_to'], (': ' . $this->joinCards($cache['argCardsObjs'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-argument is-against', $texte));
		}
		return Lua::concat($out);
	}

	protected function renderDebatesParentsBottom($pv = null, $lang = null, $cache = null, $PF = null) {
		if (Lua::truth(!Lua::truth($this->hasSMW()))) {
			return '';
		}
		$this->ensureDebateCards($pv, $cache, $lang, $PF);
		$n = Lua::len((Lua::truth($v248 = $cache['parentDebatesFinal']) ? $v248 : new Table([])));
		if (Lua::truth(($n === 0))) {
			return '<h2 id="Parent_debates" style="display:none;"></h2>';
		}
		$title = (Lua::truth($v249 = (Lua::truth($v250 = ($n === 1)) ? $PF['parent_debates_title_1'] : $v250)) ? $v249 : $PF['parent_debates_title_n']);
		$lis = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v251 = $cache['debatCardsTous']) ? $v251 : new Table([]))) as $_ => $card) {
			Lua::insert($lis, ('<li style="position: relative;">' . $card . '</li>'));
		}
		return ('<h2 id="Parent_debates" class="wk-icon-before wk-icon-parent">' . $title . '</h2>' . '<ul class="hover-top navigation-not-searchable">' . Lua::concat($lis) . '</ul>');
	}

	protected function renderInitializationBlock($args = null, $pv = null, $cats = null, $lang = null, $cache = null, $PF = null) {
		$hasParents = (Lua::truth($v252 = (Lua::truth($v253 = (Lua::len((Lua::truth($v254 = $cache['parentDebatesFinal']) ? $v254 : new Table([]))) > 0)) ? $v253 : (Lua::len((Lua::truth($v255 = $cache['justifs']) ? $v255 : new Table([]))) > 0))) ? $v252 : (Lua::len((Lua::truth($v256 = $cache['objs']) ? $v256 : new Table([]))) > 0));
		if (Lua::truth($hasParents)) {
			return '';
		}
		$initRaw = $args['initialization'];
		$initRaw = (Lua::truth($v257 = (Lua::truth($v258 = (Lua::type($initRaw) === 'string')) ? Lua::trim($initRaw) : $v258)) ? $v257 : '');
		$hasInit = ($initRaw !== '');
		$orphanBanner = '';
		$orphanBanner = (Lua::truth($v259 = $this->renderBannerByKey($pv, $lang, 'argument_orphan', $cats)) ? $v259 : '');
		if (Lua::truth($hasInit)) {
			$today = $this->context->currentDate();
			$creationDate = Lua::trim(Lua::str((Lua::truth($v260 = $args['creation-date']) ? $v260 : '')));
			if (Lua::truth(($creationDate === $today))) {
				return $orphanBanner;
			}
			Lua::insert($cats, ('[[Category:' . $PF['cat_uninitialized'] . ']]'));
			$parts = $this->splitByLiteral($initRaw, '@', 2);
			$initType = Lua::trim((Lua::truth($v261 = $parts[1]) ? $v261 : ''));
			$parentId = Lua::trim((Lua::truth($v262 = $parts[2]) ? $v262 : ''));
			$parentPage = '';
			{
				$parentId = Lua::str((Lua::truth($v263 = $parentId) ? $v263 : ''));
				$parentId = Lua::trim($parentId);
				$parentId = (Lua::truth($v264 = Lua::match($parentId, '(%d+)')) ? $v264 : '');
				if (Lua::truth((Lua::truth($v265 = (Lua::truth($v266 = (Lua::truth($v267 = ($parentId !== '')) ? true : $v267)) ? $this->context->smwAvailable() : $v266)) ? (Lua::type(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)) === 'function') : $v265))) {
					$PF['prop_debate_number'] = $this->L($lang, 'props', 'debate_number');
					[$ok, $res] = Lua::pcall(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null), new Table([1 => ('[[' . $PF['prop_debate_number'] . '::' . $parentId . ']] OR [[' . $PF['prop_arg_number'] . '::' . $parentId . ']]'), 2 => 'limit=1', 3 => 'link=none']));
					if (Lua::truth((Lua::truth($v268 = (Lua::truth($v269 = $ok) ? (Lua::type($res) === 'table') : $v269)) ? $res[1] : $v268))) {
						$row = $res[1];
						$ft = (Lua::truth($v270 = (Lua::truth($v271 = $row['fulltext']) ? $v271 : $row['page'])) ? $v270 : $row[1]);
						if (Lua::truth((Lua::type($ft) === 'string'))) {
							$ft = Lua::trim($ft);
							if (Lua::truth(($ft !== ''))) {
								$parentPage = $ft;
							}
						}
					}
				}
			}
			$parentPage = $this->canonicalTitle($parentPage);
			$title = $pv['rawTitle'];
			$t_arg_pro = $this->L($lang, 'forms', 'arg_pro');
			$t_arg_con = $this->L($lang, 'forms', 'arg_con');
			$t_justification = $this->L($lang, 'params', 'justification_type_label');
			$t_objection = $this->L($lang, 'params', 'objection_type_label');
			$formName = null;
			if (Lua::truth(($initType === $t_arg_pro))) {
				$formName = $this->L($lang, 'forms', 'edit_args_pro');
			}
			else {
				if (Lua::truth(($initType === $t_arg_con))) {
					$formName = $this->L($lang, 'forms', 'edit_args_con');
				}
				else {
					if (Lua::truth(($initType === $t_justification))) {
						$formName = $this->L($lang, 'forms', 'justifications');
					}
					else {
						if (Lua::truth(($initType === $t_objection))) {
							$formName = $this->L($lang, 'forms', 'objections');
						}
					}
				}
			}
			if (Lua::truth((Lua::truth($v272 = (Lua::truth($v273 = (Lua::truth($v274 = !Lua::truth($formName)) ? $v274 : ($formName === ''))) ? $v273 : !Lua::truth($initType))) ? $v272 : ($initType === '')))) {
				return $orphanBanner;
			}
			$idx = 99;
			$prefix = ($initType . '[' . Lua::str($idx) . ']');
			$qsParts = new Table([1 => ($prefix . '[' . $this->L($lang, 'params', 'page') . ']=' . $title)]);
			$displayedTitleParam = Lua::trim(Lua::str((Lua::truth($v275 = $this->L($lang, 'params', 'displayed_title')) ? $v275 : '')));
			if (Lua::truth(($displayedTitleParam !== ''))) {
				Lua::insert($qsParts, ($prefix . '[' . $displayedTitleParam . ']=' . $title));
			}
			$qs = Lua::concat($qsParts, '&');
			$warnTitle = Lua::format((Lua::truth($v276 = $PF['init_warning_title']) ? $v276 : '%s'), $formName);
			$warnIntro = $PF['init_warning_intro'];
			$yesText = (Lua::truth($v277 = (Lua::truth($v278 = $PF['init_yes_finish_label']) ? $v278 : $PF['edit'])) ? $v277 : '');
			$yesTooltip = Lua::format((Lua::truth($v279 = $PF['init_yes_tt']) ? $v279 : '%s'), $parentPage);
			$okText = Lua::format((Lua::truth($v280 = $PF['init_ok_text']) ? $v280 : '%s'), $parentPage);
			$summary = null;
			if (Lua::truth(($initType === $t_arg_pro))) {
				$summary = $PF['init_summary_pro'];
			}
			else {
				if (Lua::truth(($initType === $t_arg_con))) {
					$summary = $PF['init_summary_con'];
				}
				else {
					if (Lua::truth(($initType === $t_justification))) {
						$summary = $PF['init_summary_justif'];
					}
					else {
						if (Lua::truth(($initType === $t_objection))) {
							$summary = $PF['init_summary_obj'];
						}
						else {
							$summary = '';
						}
					}
				}
			}
			if (Lua::truth(($summary !== ''))) {
				$summary = ($summary . ' : « ' . $title . ' »');
			}
			$params = new Table(['form' => $formName, 'target' => $parentPage, 'query string' => $qs,
				'link text' => $yesText, 'link type' => 'button', 'tooltip' => $yesTooltip, 'ok text' => $okText]);
			if ($summary !== '') { $params['summary'] = $summary; }
			$params['reload'] = true;
			[$ok, $r] = Lua::pcall(function() use ($params) { return $this->context->autoEdit($params); });
			$auto = $ok && Lua::truth($r) ? $r : '';

			return ($orphanBanner . '<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $warnTitle . ' [[' . $parentPage . ']].' . '\'\'\'

' . $warnIntro . '
' . $auto . '</div><!-- Fin de warningbox -->');
		}
		return $orphanBanner;
	}

	protected function ensureBreadcrumbSelection($pv = null, $cache = null, $cats = null, $lang = null, $PF = null) {
		if (Lua::truth((Lua::truth($v286 = !Lua::truth($cache)) ? $v286 : ($cache['breadcrumbChainsFinal'] !== null)))) {
			return null;
		}
		$cache['breadcrumbChainsFinal'] = new Table([]);
		$cache['parentDebatesFinal'] = new Table([]);
		if (Lua::truth((Lua::truth($v287 = !Lua::truth($this->hasSMW())) ? $v287 : (Lua::type(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)) !== 'function')))) {
			return null;
		}
		$lang = (Lua::truth($v288 = $lang) ? $v288 : 'fr');
		$title = $this->smwSafeTitle($pv['rawTitle']);
		if (Lua::truth(($title === ''))) {
			return null;
		}
		$propBreadcrumb = Lua::str((Lua::truth($v289 = $PF['prop_breadcrumb']) ? $v289 : ''));
		$propDebateName = Lua::str((Lua::truth($v290 = $PF['prop_debate_name']) ? $v290 : ''));
		$propPro = Lua::str((Lua::truth($v291 = $PF['prop_arg_pro']) ? $v291 : ''));
		$propCon = Lua::str((Lua::truth($v292 = $PF['prop_arg_con']) ? $v292 : ''));
		$propJustif = Lua::str((Lua::truth($v293 = $PF['prop_justif']) ? $v293 : ''));
		$propObj = Lua::str((Lua::truth($v294 = $PF['prop_obj']) ? $v294 : ''));
		if (Lua::truth((Lua::truth($v295 = (Lua::truth($v296 = (Lua::truth($v297 = (Lua::truth($v298 = ($propBreadcrumb === '')) ? $v298 : ($propPro === ''))) ? $v297 : ($propCon === ''))) ? $v296 : ($propJustif === ''))) ? $v295 : ($propObj === '')))) {
			return null;
		}
		$q = new Table([1 => ('([[' . $propJustif . '::' . $title . ']] OR [[' . $propObj . '::' . $title . ']] OR [[' . $propPro . '::' . $title . ']] OR [[' . $propCon . '::' . $title . ']] )'), 2 => ('?' . $propBreadcrumb . '=chain'), 3 => (Lua::truth($v299 = (Lua::truth($v300 = ($propDebateName !== '')) ? ('?' . $propDebateName . '=debateName') : $v300)) ? $v299 : null), 4 => 'limit=200', 5 => 'link=none']);
		$qq = new Table([]);
		foreach (Lua::ipairs($q) as $_ => $x) {
			if (Lua::truth($x)) {
				Lua::insert($qq, $x);
			}
		}
		$res = $this->smwAskSafe($qq);
		if (Lua::truth((Lua::truth($v301 = (Lua::type($res) !== 'table')) ? $v301 : (Lua::len($res) === 0)))) {
			return null;
		}
		$bestByDebate = new Table([]);
		$consider = function($ch = null) use (&$pv, &$cache, &$cats, &$lang, &$PF, &$title, &$propBreadcrumb, &$propDebateName, &$propPro, &$propCon, &$propJustif, &$propObj, &$q, &$qq, &$_, &$x, &$res, &$bestByDebate, &$consider, &$parts, &$clean, &$part, &$n, &$root, &$cur, &$row, &$chain, &$selected, &$item, &$filtered, &$chains, &$debates, &$tooLong, &$newChain) {
		if (Lua::truth((Lua::truth($v302 = (Lua::type($ch) !== 'string')) ? $v302 : ($ch === '')))) {
			return null;
		}
		$parts = $this->splitByLiteral($ch, '⟭');
		$clean = new Table([]);
		foreach (Lua::ipairs($parts) as $_ => $part) {
			$part = Lua::trim($part);
			if (Lua::truth(($part !== ''))) {
				$clean[(Lua::len($clean) + 1)] = $part;
			}
		}
		$n = Lua::len($clean);
		if (Lua::truth(($n === 0))) {
			return null;
		}
		$root = $this->canonicalTitle((Lua::truth($v303 = $clean[1]) ? $v303 : ''));
		if (Lua::truth(($root === ''))) {
			return null;
		}
		$cur = $bestByDebate[$root];
		if (Lua::truth((Lua::truth($v304 = !Lua::truth($cur)) ? $v304 : ($n < $cur['len'])))) {
			$bestByDebate[$root] = new Table(['root' => $root, 'chain' => Lua::concat($clean, '⟭'), 'len' => $n]);
		}
		};
		foreach (Lua::ipairs($res) as $_ => $row) {
			$chain = $row['chain'];
			if (Lua::truth((Lua::type($chain) === 'string'))) {
				$consider($chain);
			}
			else {
				if (Lua::truth((Lua::type($chain) === 'table'))) {
					foreach (Lua::ipairs($chain) as $_ => $ch) {
						$consider($ch);
					}
				}
			}
		}
		$selected = new Table([]);
		foreach (Lua::pairs($bestByDebate) as $_ => $item) {
			$selected[(Lua::len($selected) + 1)] = $item;
		}
		if (Lua::truth((Lua::len($selected) === 0))) {
			return null;
		}
		if (Lua::truth((Lua::len($selected) > 1))) {
			$filtered = new Table([]);
			foreach (Lua::ipairs($selected) as $_ => $item) {
				if (Lua::truth(($item['len'] < 4))) {
					$filtered[(Lua::len($filtered) + 1)] = $item;
				}
			}
			$selected = $filtered;
		}
		if (Lua::truth((Lua::len($selected) === 0))) {
			return null;
		}
		$chains = new Table([]);
		$debates = new Table([]);
		$tooLong = false;
		foreach (Lua::ipairs($selected) as $_ => $item) {
			$newChain = ($item['chain'] . '⟭' . $title);
			if (Lua::truth(($this->breadcrumbLenByteSafe($newChain) > 4))) {
				$tooLong = true;
			}
			$chains[(Lua::len($chains) + 1)] = $newChain;
			$debates[(Lua::len($debates) + 1)] = $item['root'];
		}
		if (Lua::truth($tooLong)) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'breadcrumb_too_long') . ']]'));
		}
		$cache['breadcrumbChainsFinal'] = $this->uniqList($chains);
		$cache['parentDebatesFinal'] = $this->uniqList($debates);
	}

	protected function wkBuildArgumentData($items = null) {
		$items = (Lua::truth($v305 = $items) ? $v305 : new Table([]));
		$listLines = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			Lua::insert($listLines, ('* ' . Lua::str((Lua::truth($v306 = $it['title']) ? $v306 : ''))));
		}
		$listText = Lua::concat($listLines, '
');
		$argsVarParts = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			$page = Lua::trim(Lua::str((Lua::truth($v307 = $it['page']) ? $v307 : '')));
			if (Lua::truth(($page !== ''))) {
				$title = Lua::trim(Lua::str((Lua::truth($v308 = $it['title']) ? $v308 : '')));
				if (Lua::truth((Lua::truth($v309 = ($title === '')) ? $v309 : ($title === $page)))) {
					Lua::insert($argsVarParts, $page);
				}
				else {
					Lua::insert($argsVarParts, ($page . $this->FIELD_SEP . $title));
				}
			}
		}
		$argsVar = Lua::concat($argsVarParts, $this->ITEM_SEP);
		$hoverLines = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			Lua::insert($hoverLines, ('<div class="argument-title--map wk-icon-before wk-icon-argument">' . '[[' . Lua::str((Lua::truth($v310 = $it['page']) ? $v310 : '')) . '|' . Lua::str((Lua::truth($v311 = $it['title']) ? $v311 : '')) . ']]' . '</div>'));
		}
		$mapHoverHtml = Lua::concat($hoverLines, '
');
		return new Table(['items' => $items, 'list' => $listText, 'argsVar' => $argsVar, 'mapHover' => $mapHoverHtml]);
	}

	protected function renderArgumentItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, $out = null) {
		$warnsArg = Lua::trim(Lua::str((Lua::truth($v312 = $itemWarnings) ? $v312 : '')));
		if (Lua::truth(($warnsArg === ''))) {
			return null;
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->DISPLAYED_TITLE_WARNING_BANNER_KEYS);
		$hasBanner = false;
		foreach (Lua::ipairs($this->splitCSV($warnsArg, ',')) as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->DISPLAYED_TITLE_WARNING_BANNER_KEYS);
			if (Lua::truth(($key !== ''))) {
				Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, null));
				$hasBanner = true;
			}
		}
		if (Lua::truth((Lua::truth($v313 = $hasBanner) ? $cats : $v313))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'displayed_titles_to_review') . ']]'));
		}
	}

	protected function wkRenderArgumentLi($pv = null, $lang = null, $it = null, $kind = null, $idx = null, $cats = null) {
		$isJustif = ($kind === 'justif');
		$idPrefix = (Lua::truth($v314 = (Lua::truth($v315 = $isJustif) ? 'Justification_' : $v315)) ? $v314 : 'Objection_');
		$page = Lua::str((Lua::truth($v316 = $it['page']) ? $v316 : ''));
		$titleShown = Lua::str((Lua::truth($v317 = $it['title']) ? $v317 : ''));
		if (Lua::truth(($titleShown === ''))) {
			$titleShown = $page;
		}
		$aId = $this->anchorId($titleShown);
		$warnings = Lua::str((Lua::truth($v318 = $it['warnings']) ? $v318 : ''));
		$banners = new Table([]);
		$this->renderArgumentItemBanners($warnings, $pv, $lang, $cats, $banners);
		return ('<li id="' . $idPrefix . Lua::str($idx) . '" class="argument level-1">' . '<div id="' . $this->escapeAttr($aId) . '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">' . '[[' . $page . '|' . $titleShown . ']]' . (Lua::truth($v319 = (Lua::truth($v320 = (Lua::len($banners) > 0)) ? Lua::concat($banners) : $v320)) ? $v319 : '') . '</div>' . '</li>');
	}

	protected function renderKeywords($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		$out = new Table([]);
		Lua::insert($out, ('<div style="font-size: 95%; margin-top: 1em;">' . $PF['keywords_label']));
		$raw = $args['keywords'];
		if (Lua::truth((Lua::truth($v321 = $raw) ? ($raw !== '') : $v321))) {
			$extra = null;
			if (Lua::truth((Lua::truth($v322 = ($PF['search_type_field'] !== '')) ? ($PF['search_type_value'] !== '') : $v322))) {
				$extra = new Table([$PF['search_type_field'] => $PF['search_type_value']]);
			}
			$rq_title = $this->wkRunQueryPath($PF['form_search_by_keywords']);
			$rq_qkey = ($PF['form_search_by_keywords'] . '[' . $PF['keywords_field'] . ']');
			$rendered = new Table([]);
			foreach (Lua::ipairs($this->splitCSV($raw, ',')) as $_ => $k) {
				$k = Lua::trim(Lua::str((Lua::truth($v323 = $k) ? $v323 : '')));
				if (Lua::truth(($k !== ''))) {
					$chip = $this->runQueryLink(new Table(['form' => $PF['form_search_by_keywords'], 'field' => $PF['keywords_field'], 'value' => $k, 'label' => $k, 'tooltip' => $PF['search_by_keyword_tt'], 'extra' => $extra, '_title' => $rq_title, '_qkey' => $rq_qkey]));
					if (Lua::truth((Lua::truth($v324 = $chip) ? ($chip !== '') : $v324))) {
						Lua::insert($rendered, $chip);
					}
					else {
						Lua::insert($rendered, $k);
					}
				}
			}
			if (Lua::truth((Lua::len($rendered) > 0))) {
				Lua::insert($out, Lua::concat($rendered, ', '));
			}
			else {
				Lua::insert($out, ('<strong>' . $PF['none'] . '</strong>'));
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_keywords'] . ']]'));
			}
		}
		else {
			Lua::insert($out, ('<strong>' . $PF['none'] . '</strong>'));
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_keywords'] . ']]'));
		}
		Lua::insert($out, ($this->addDataEditLink($PF['form_edit_keywords'], $pv['rawTitle'], '&nbsp;', $PF['edit_keywords_tooltip']) . '<span style="display: none;">.</span></div>'));
		return Lua::concat($out);
	}

	protected function renderSummary($args = null, $pv = null, $cats = null, $lang = null, $PF = null, $summaryId = null) {
		$edit = $this->addDataEditLink($PF['form_edit_summary'], $pv['rawTitle'], '&nbsp;', $PF['edit_summary_tooltip']);
		$out = new Table([]);
		Lua::insert($out, ('<h2' . $summaryId . ' class="section-modifiable wk-icon-before wk-icon-summary">' . $PF['summary'] . $edit . '</h2>'));
		{
			$raw = $args['summary-warnings'];
			$raw = (Lua::truth($v325 = (Lua::truth($v326 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v326)) ? $v325 : '');
			if (Lua::truth(($raw !== ''))) {
				$lookup = $this->buildSummaryLabelLookup($lang, $this->SUMMARY_WARNING_BANNER_KEYS);
				$aliases = $this->SUMMARY_WARNING_ALIASES;
				if (Lua::truth((Lua::truth($v327 = !Lua::truth($aliases)) ? $v327 : ($aliases['_lang'] !== $lang)))) {
					$aliases = $this->buildSummaryAliases($lang);
					$aliases['_lang'] = $lang;
					$this->SUMMARY_WARNING_ALIASES = $aliases;
				}
				foreach (Lua::ipairs($this->splitCSV($raw, ',')) as $_ => $x) {
					$key = $this->resolveKeyFromLookup($x, $lookup, $this->SUMMARY_WARNING_BANNER_KEYS, $aliases);
					if (Lua::truth(($key !== ''))) {
						Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, $cats));
					}
				}
			}
		}
		if (Lua::truth((Lua::truth($v328 = $args['summary']) ? ($args['summary'] !== '') : $v328))) {
			Lua::insert($out, ('<div class=\'argument-summary\'>
' . $args['summary'] . '</div>'));
		}
		else {
			Lua::insert($out, ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_summary'] . '</div>'));
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_summary'] . ']]'));
		}
		return Lua::concat($out);
	}

	protected function renderQuotes($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		$edit = $this->addDataEditLink($PF['form_edit_quotes'], $pv['rawTitle'], '&nbsp;', $PF['edit_quotes_tooltip']);
		$out = new Table([]);
		$idAttr = '';
		Lua::insert($out, ('<h2 class="section-modifiable wk-icon-before wk-icon-quote"' . $idAttr . '>' . $PF['quotes'] . $edit . '</h2>'));
		$q = $args['quotes'];
		if (Lua::truth((Lua::truth($v329 = $q) ? ($q !== '') : $v329))) {
			Lua::insert($out, $q);
		}
		else {
			Lua::insert($out, ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_quotes'] . '</div>'));
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_quotes'] . ']]'));
		}
		return Lua::concat($out);
	}

	protected function renderDebateDetailed($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		if (Lua::truth((Lua::truth($v330 = !Lua::truth($args['dedicated-debate'])) ? $v330 : ($args['dedicated-debate'] === '')))) {
			return '';
		}
		Lua::insert($cats, ('[[Category:' . $PF['cat_has_detailed_debate'] . ']]'));
		$contentToTransfer = (Lua::str((Lua::truth($v331 = $args['justifications']) ? $v331 : '')) . Lua::str((Lua::truth($v332 = $args['objections']) ? $v332 : '')) . Lua::str((Lua::truth($v333 = $args['bibliography']) ? $v333 : '')) . Lua::str((Lua::truth($v334 = $args['webliography']) ? $v334 : '')) . Lua::str((Lua::truth($v335 = $args['videography']) ? $v335 : '')));
		if (Lua::truth((Lua::trim($contentToTransfer) !== ''))) {
			Lua::insert($cats, ('[[Category:' . $PF['cat_content_to_transfer'] . ']]'));
		}
		$edit = $this->addDataEditLink($PF['form_edit_detailed_debate'], $pv['rawTitle'], '&nbsp;', Lua::format($PF['edit_detailed_debate_tooltip'], $pv['rawTitle']));
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-details">' . $PF['detailed_debate'] . $edit . '</h2>');
		$debat = $this->canonicalTitle($args['dedicated-debate']);
		$mapHtml = '';
		if (Lua::truth((Lua::truth($v336 = (Lua::truth($v337 = (Lua::truth($v338 = $this->hasSMW()) ? (Lua::type(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)) === 'function') : $v338)) ? $PF['prop_argument_map'] : $v337)) ? ($PF['prop_argument_map'] !== '') : $v336))) {
			$debatSafe = $this->smwSafeTitle($debat);
			if (Lua::truth(($debatSafe !== ''))) {
				$res = $this->smwAskSafe(new Table([1 => ('[[' . $debatSafe . ']]'), 2 => ('?' . $PF['prop_argument_map'] . '=map'), 3 => 'limit=1', 4 => 'link=none']));
				if (Lua::truth((Lua::truth($v339 = (Lua::truth($v340 = (Lua::type($res) === 'table')) ? $res[1] : $v340)) ? $res[1]['map'] : $v339))) {
					$v = $res[1]['map'];
					if (Lua::truth((Lua::type($v) === 'string'))) {
						$mapHtml = $v;
					}
					else {
						if (Lua::truth((Lua::type($v) === 'table'))) {
							foreach (Lua::ipairs($v) as $_ => $x) {
								if (Lua::truth((Lua::truth($v341 = (Lua::type($x) === 'string')) ? (Lua::trim($x) !== '') : $v341))) {
									$mapHtml = $x;
									break;
								}
								else {
									if (Lua::truth((Lua::type($x) === 'table'))) {
										$ft = (Lua::truth($v342 = (Lua::truth($v343 = $x['fulltext']) ? $v343 : $x['page'])) ? $v342 : $x[1]);
										if (Lua::truth((Lua::truth($v344 = (Lua::type($ft) === 'string')) ? (Lua::trim($ft) !== '') : $v344))) {
											$mapHtml = $ft;
											break;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		$box = ('<div class="bandeau-debat-detaille">' . '<div style="font-weight: bold;">' . Lua::format($PF['arg_is_debate'], $this->context->nowiki($pv['rawTitle'])) . '</div>' . '<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert navigation-not-searchable onglet-externe">' . $PF['detailed_debate_label'] . '<span class="onglet-externe">[[ ' . $debat . ' ]]</span>' . '</div></div>');
		$mapBox = '';
		if (Lua::truth(($mapHtml !== ''))) {
			$mapBox = ('<div class="encart-debat-detaille onglet-externe">' . '<div class="titre-debat-detaille">' . $debat . '</div>' . $mapHtml . '</div>');
		}
		return ($h2 . $box . $mapBox);
	}

	protected function safeRubriqueValue($s = null) {
		$s = Lua::trim(Lua::str((Lua::truth($v345 = $s) ? $v345 : '')));
		if (Lua::truth(($s === ''))) {
			return '';
		}
		if (Lua::truth(Lua::find($s, '[%[%]{}|#<>]'))) {
			return '';
		}
		$s = Lua::gsub($s, '%s+', ' ');
		$s = Lua::trim($s);
		return $s;
	}

	protected function renderRubriques($args = null, $cats = null, $lang = null, $PF = null) {
		if (Lua::truth((Lua::truth($v346 = $args['sections']) ? ($args['sections'] !== '') : $v346))) {
			$out = new Table([]);
			$nOk = 0;
			foreach (Lua::ipairs($this->splitCSV($args['sections'], ',')) as $_ => $r) {
				$r = $this->safeRubriqueValue($r);
				if (Lua::truth(($r !== ''))) {
					$nOk = ($nOk + 1);
					Lua::insert($out, ('[[Category:' . $r . ']]'));
				}
			}
			if (Lua::truth(($nOk > 0))) {
				return Lua::concat($out);
			}
			return ('[[Category:' . $PF['cat_no_sections'] . ']]');
		}
		else {
			return ('[[Category:' . $PF['cat_no_sections'] . ']]');
		}
	}

	protected function wkParseQuoteMarkers($raw = null) {
		$raw = Lua::str((Lua::truth($v347 = $raw) ? $v347 : ''));
		$raw = Lua::trim($raw);
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		if (Lua::truth(!Lua::truth(Lua::find($raw, $this->QUOTE_FIELD_SEP, 1, true)))) {
			return new Table([]);
		}
		$items = new Table([]);
		$blocks = null;
		if (Lua::truth(Lua::find($raw, $this->QUOTE_ITEM_SEP, 1, true))) {
			$blocks = $this->splitByLiteral($raw, $this->QUOTE_ITEM_SEP);
		}
		else {
			$blocks = new Table([1 => $raw]);
		}
		foreach (Lua::ipairs($blocks) as $_ => $block) {
			$block = Lua::trim($block);
			if (Lua::truth(($block !== ''))) {
				$f = new Table([]);
				$parts = $this->splitByLiteral($block, $this->QUOTE_FIELD_SEP);
				foreach (Lua::ipairs($parts) as $_ => $part) {
					$f[(Lua::len($f) + 1)] = Lua::trim(Lua::str((Lua::truth($v348 = $part) ? $v348 : '')));
				}
				$stop5 = 13;
				for ($i = (Lua::len($f) + 1); $i <= $stop5; $i++) {
					$f[$i] = '';
				}
				$items[(Lua::len($items) + 1)] = new Table(['quote' => $f[1], 'authors' => $f[2], 'article' => $f[3], 'work' => $f[4], 'volume' => $f[5], 'number' => $f[6], 'location' => $f[7], 'page' => $f[8], 'publisher' => $f[9], 'place' => $f[10], 'date' => $f[11], 'link' => $f[12], 'warnings' => $f[13]]);
			}
		}
		return $items;
	}

	protected function wkRenderOneQuoteHtml($item = null, $idx = null, $pv = null, $lang = null, $cats = null, $PF = null) {
		$quote = Lua::str((Lua::truth($v349 = $item['quote']) ? $v349 : ''));
		$authors = Lua::trim((Lua::truth($v350 = $item['authors']) ? $v350 : ''));
		$article = Lua::trim((Lua::truth($v351 = $item['article']) ? $v351 : ''));
		$work = Lua::trim((Lua::truth($v352 = $item['work']) ? $v352 : ''));
		$volume = Lua::trim((Lua::truth($v353 = $item['volume']) ? $v353 : ''));
		$number = Lua::trim((Lua::truth($v354 = $item['number']) ? $v354 : ''));
		$page = Lua::trim((Lua::truth($v355 = $item['page']) ? $v355 : ''));
		$location = Lua::trim((Lua::truth($v356 = $item['location']) ? $v356 : ''));
		$publisher = Lua::trim((Lua::truth($v357 = $item['publisher']) ? $v357 : ''));
		$place = Lua::trim((Lua::truth($v358 = $item['place']) ? $v358 : ''));
		$date = Lua::trim((Lua::truth($v359 = $item['date']) ? $v359 : ''));
		$link = Lua::trim((Lua::truth($v360 = $item['link']) ? $v360 : ''));
		$warnings = Lua::trim((Lua::truth($v361 = $item['warnings']) ? $v361 : ''));
		$this->vset('WD_QUOTES_MAX', Lua::str($idx));
		if (Lua::truth(($authors !== ''))) {
			$cur = Lua::trim(Lua::str((Lua::truth($v362 = $this->vget('WD_QUOTES_AUTHORS')) ? $v362 : '')));
			$this->vset('WD_QUOTES_AUTHORS', (Lua::truth($v363 = (Lua::truth($v364 = ($cur === '')) ? $authors : $v364)) ? $v363 : ($cur . ',' . $authors)));
		}
		if (Lua::truth(($article !== ''))) {
			$cur = Lua::trim(Lua::str((Lua::truth($v365 = $this->vget('WD_QUOTES_ARTICLES')) ? $v365 : '')));
			$this->vset('WD_QUOTES_ARTICLES', (Lua::truth($v366 = (Lua::truth($v367 = ($cur === '')) ? $article : $v367)) ? $v366 : ($cur . ',' . $article)));
		}
		if (Lua::truth(($work !== ''))) {
			$cur = Lua::trim(Lua::str((Lua::truth($v368 = $this->vget('WD_QUOTES_WORKS')) ? $v368 : '')));
			$this->vset('WD_QUOTES_WORKS', (Lua::truth($v369 = (Lua::truth($v370 = ($cur === '')) ? $work : $v370)) ? $v369 : ($cur . ',' . $work)));
		}
		if (Lua::truth(($publisher !== ''))) {
			$cur = Lua::trim(Lua::str((Lua::truth($v371 = $this->vget('WD_QUOTES_PUBLISHERS')) ? $v371 : '')));
			$this->vset('WD_QUOTES_PUBLISHERS', (Lua::truth($v372 = (Lua::truth($v373 = ($cur === '')) ? $publisher : $v373)) ? $v372 : ($cur . ',' . $publisher)));
		}
		if (Lua::truth(($place !== ''))) {
			$cur = Lua::trim(Lua::str((Lua::truth($v374 = $this->vget('WD_QUOTES_PLACES')) ? $v374 : '')));
			$this->vset('WD_QUOTES_PLACES', (Lua::truth($v375 = (Lua::truth($v376 = ($cur === '')) ? $place : $v376)) ? $v375 : ($cur . ',' . $place)));
		}
		$warnHtml = '';
		if (Lua::truth(($warnings !== ''))) {
			$lookup = $this->buildSummaryLabelLookup($lang, $this->QUOTE_WARNING_BANNER_KEYS);
			$b = new Table([]);
			foreach (Lua::split($warnings, ',', true) as $x) {
				$x = Lua::trim($x);
				if (Lua::truth(($x !== ''))) {
					$key = $this->resolveKeyFromLookup($x, $lookup, $this->QUOTE_WARNING_BANNER_KEYS);
					if (Lua::truth(($key !== ''))) {
						$h = $this->renderSubsectionBanner($pv, $lang, $key, null, $cats);
						if (Lua::truth(($h !== ''))) {
							$b[(Lua::len($b) + 1)] = $h;
						}
					}
				}
			}
			if (Lua::truth((Lua::len($b) > 0))) {
				$warnHtml = ('<div>' . Lua::concat($b, '') . '</div>');
			}
		}
		$bq = ('<blockquote data-wk-cite="' . Lua::str($idx) . '">' . $warnHtml . '« ' . $quote . ' »' . '</blockquote>');
		$ref = new Table([]);
		Lua::insert($ref, ('<div class="reference-citation" data-wk-cite="' . Lua::str($idx) . '">'));
		if (Lua::truth(($authors !== ''))) {
			Lua::insert($ref, $authors);
		}
		else {
			Lua::insert($ref, $PF['unknown_author']);
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_authors'] . ']]'));
		}
		if (Lua::truth(($article !== ''))) {
			if (Lua::truth(($link !== ''))) {
				Lua::insert($ref, (', « [' . $link . ' ' . $article . '] »'));
			}
			else {
				Lua::insert($ref, (', « ' . $article . ' »'));
			}
		}
		if (Lua::truth(($work !== ''))) {
			if (Lua::truth(($article !== ''))) {
				Lua::insert($ref, (', \'\'' . $work . '\'\''));
			}
			else {
				if (Lua::truth(($link !== ''))) {
					Lua::insert($ref, (', \'\'[' . $link . ' ' . $work . ']\'\''));
				}
				else {
					Lua::insert($ref, (', \'\'' . $work . '\'\''));
				}
			}
		}
		if (Lua::truth(($volume !== ''))) {
			Lua::insert($ref, (', ' . $PF['volume_label'] . '&#160;' . $volume));
		}
		if (Lua::truth(($number !== ''))) {
			Lua::insert($ref, (', ' . $PF['issue_label'] . '&#160;' . $number));
		}
		if (Lua::truth(($page !== ''))) {
			Lua::insert($ref, (', p.' . $page));
		}
		if (Lua::truth(($location !== ''))) {
			Lua::insert($ref, (', ' . $location));
		}
		if (Lua::truth(($publisher !== ''))) {
			Lua::insert($ref, (', ' . $publisher));
		}
		if (Lua::truth(($place !== ''))) {
			Lua::insert($ref, (', ' . $place));
		}
		if (Lua::truth(($date !== ''))) {
			Lua::insert($ref, (', ' . $date));
		}
		Lua::insert($ref, '</div>');
		$html = ($bq . '
' . Lua::concat($ref) . '
');
		if (Lua::truth((Lua::truth($v377 = ($idx >= 1)) ? ($idx <= 4) : $v377))) {
			$this->vset(('WD_QUOTE_' . Lua::str($idx)), $html);
		}
		return $html;
	}

	protected function wkRenderQuotesFromSerialized($raw = null, $pv = null, $lang = null, $cats = null, $PF = null) {
		$items = $this->wkParseQuoteMarkers($raw);
		if (Lua::truth((Lua::len($items) === 0))) {
			return '';
		}
		$out = new Table([]);
		foreach (Lua::ipairs($items) as $i => $it) {
			$out[(Lua::len($out) + 1)] = $this->wkRenderOneQuoteHtml($it, $i, $pv, $lang, $cats, $PF);
		}
		return Lua::concat($out, '');
	}

	protected function setSemanticData($args = null, $pv = null, $lang = null, $cache = null, $PF = null, $maps = null, $rawB = null, $rawW = null, $rawV = null) {
		$props = new Table([]);
		$props[$PF['prop_arg_name']] = $pv['rawTitle'];
		$props[$PF['prop_arg_number']] = $pv['pageId'];
		if (Lua::truth($this->hasSMW())) {
			$this->ensureDebateParentsFinal($pv, $cache, $PF);
			$this->ensureArgumentParents($pv, $cache, $PF);
			{
				$chains = $this->uniqList((Lua::truth($v378 = $cache['breadcrumbChainsFinal']) ? $v378 : new Table([])));
				if (Lua::truth((Lua::truth($v379 = (Lua::truth($v380 = (Lua::len($chains) > 0)) ? $PF['prop_breadcrumb'] : $v380)) ? ($PF['prop_breadcrumb'] !== '') : $v379))) {
					$props[$PF['prop_breadcrumb']] = $chains;
				}
				if (Lua::truth((Lua::truth($v381 = $PF['prop_parent_debate']) ? ($PF['prop_parent_debate'] !== '') : $v381))) {
					$dp = $this->uniqList((Lua::truth($v382 = $cache['parentDebatesFinal']) ? $v382 : new Table([])));
					if (Lua::truth((Lua::len($dp) > 0))) {
						$props[$PF['prop_parent_debate']] = $dp;
					}
				}
			}
			{
				$parents = new Table([]);
				foreach (Lua::ipairs((Lua::truth($v383 = $cache['justifs']) ? $v383 : new Table([]))) as $_ => $x) {
					Lua::insert($parents, $this->canonicalTitle($x));
				}
				foreach (Lua::ipairs((Lua::truth($v384 = $cache['objs']) ? $v384 : new Table([]))) as $_ => $x) {
					Lua::insert($parents, $this->canonicalTitle($x));
				}
				$parents = $this->uniqList($parents);
				if (Lua::truth((Lua::truth($v385 = (Lua::truth($v386 = (Lua::len($parents) > 0)) ? $PF['prop_parent_argument'] : $v386)) ? ($PF['prop_parent_argument'] !== '') : $v385))) {
					$props[$PF['prop_parent_argument']] = $parents;
				}
			}
		}
		$maps = (Lua::truth($v387 = $maps) ? $v387 : new Table([]));
		$justif = (Lua::truth($v388 = $maps['justif']) ? $v388 : new Table([]));
		$obj = (Lua::truth($v389 = $maps['obj']) ? $v389 : new Table([]));
		{
			$lst = new Table([]);
			foreach (Lua::ipairs((Lua::truth($v390 = $justif['items']) ? $v390 : new Table([]))) as $_ => $it) {
				$t = $this->canonicalTitle((Lua::truth($v391 = $it['page']) ? $v391 : ''));
				if (Lua::truth(($t !== ''))) {
					Lua::insert($lst, $t);
				}
			}
			$lst = $this->uniqList($lst);
			if (Lua::truth((Lua::truth($v392 = (Lua::truth($v393 = (Lua::len($lst) > 0)) ? $PF['prop_justif'] : $v393)) ? ($PF['prop_justif'] !== '') : $v392))) {
				$props[$PF['prop_justif']] = $lst;
			}
		}
		{
			$lst = new Table([]);
			foreach (Lua::ipairs((Lua::truth($v394 = $obj['items']) ? $v394 : new Table([]))) as $_ => $it) {
				$t = $this->canonicalTitle((Lua::truth($v395 = $it['page']) ? $v395 : ''));
				if (Lua::truth(($t !== ''))) {
					Lua::insert($lst, $t);
				}
			}
			$lst = $this->uniqList($lst);
			if (Lua::truth((Lua::truth($v396 = (Lua::truth($v397 = (Lua::len($lst) > 0)) ? $PF['prop_obj'] : $v397)) ? ($PF['prop_obj'] !== '') : $v396))) {
				$props[$PF['prop_obj']] = $lst;
			}
		}
		$justifSurvol = Lua::trim((Lua::truth($v398 = $justif['mapHover']) ? $v398 : ''));
		$objSurvol = Lua::trim((Lua::truth($v399 = $obj['mapHover']) ? $v399 : ''));
		if (Lua::truth(($justifSurvol === ''))) {
			$justifSurvol = ('<div class="argument-title--map carte-vide">' . $PF['no_justifications_short'] . '</div>');
		}
		if (Lua::truth(($objSurvol === ''))) {
			$objSurvol = ('<div class="argument-title--map carte-vide">' . $PF['no_objections_short'] . '</div>');
		}
		if (Lua::truth((Lua::truth($v400 = $PF['prop_argument_map']) ? ($PF['prop_argument_map'] !== '') : $v400))) {
			$props[$PF['prop_argument_map']] = ('<div class="wk-arg-map navigation-not-searchable">' . '<div class="wk-arg-map__col is-pro">' . '<div class="wk-arg-map__th">' . $PF['justifications'] . '</div>' . '<div class="wk-arg-map__td">' . $justifSurvol . '</div>' . '</div>' . '<div class="wk-arg-map__col is-con">' . '<div class="wk-arg-map__th">' . $PF['objections'] . '</div>' . '<div class="wk-arg-map__td">' . $objSurvol . '</div>' . '</div>' . '</div>');
		}
		{
			$lst = Lua::trim((Lua::truth($v401 = $justif['argsVar']) ? $v401 : ''));
			if (Lua::truth((Lua::truth($v402 = (Lua::truth($v403 = ($lst !== '')) ? $PF['prop_justif_list'] : $v403)) ? ($PF['prop_justif_list'] !== '') : $v402))) {
				$props[$PF['prop_justif_list']] = $lst;
			}
		}
		{
			$lst = Lua::trim((Lua::truth($v404 = $obj['argsVar']) ? $v404 : ''));
			if (Lua::truth((Lua::truth($v405 = (Lua::truth($v406 = ($lst !== '')) ? $PF['prop_obj_list'] : $v406)) ? ($PF['prop_obj_list'] !== '') : $v405))) {
				$props[$PF['prop_obj_list']] = $lst;
			}
		}
		$mc = $args['keywords'];
		if (Lua::truth((Lua::truth($v407 = $mc) ? ($mc !== '') : $v407))) {
			$list = $this->splitCSV($mc, ',');
			if (Lua::truth((Lua::len($list) > 0))) {
				$props[$PF['prop_keyword']] = $list;
			}
		}
		if (Lua::truth((Lua::truth($v408 = $args['dedicated-debate']) ? ($args['dedicated-debate'] !== '') : $v408))) {
			$props[$PF['prop_detailed_debate']] = $this->canonicalTitle($args['dedicated-debate']);
		}
		{
			$uniqCSVVar = function($varName = null) use (&$args, &$pv, &$lang, &$cache, &$PF, &$maps, &$rawB, &$rawW, &$rawV, &$props, &$chains, &$dp, &$parents, &$_, &$x, &$justif, &$obj, &$lst, &$it, &$t, &$justifSurvol, &$objSurvol, &$mc, &$list, &$uniqCSVVar, &$raw, &$authors, &$k, &$articles, &$works, &$pubs, &$places, &$r) {
		$raw = Lua::str((Lua::truth($v409 = $this->vget($varName)) ? $v409 : ''));
		$raw = Lua::trim($raw);
		if (Lua::truth(($raw === ''))) {
			return null;
		}
		$lst = $this->splitCSV($raw, ',');
		$lst = $this->uniqList($lst);
		return (Lua::truth($v410 = (Lua::truth($v411 = (Lua::len($lst) > 0)) ? $lst : $v411)) ? $v410 : null);
		};
			$authors = $uniqCSVVar('WD_QUOTES_AUTHORS');
			if (Lua::truth($authors)) {
				$k = $this->smwPropRef($PF, 'authors');
				if (Lua::truth(($k !== ''))) {
					$this->smwQueueAdd($props, $k, $authors, true);
				}
			}
			$articles = $uniqCSVVar('WD_QUOTES_ARTICLES');
			if (Lua::truth($articles)) {
				$k = $this->smwPropRef($PF, 'article_name');
				if (Lua::truth(($k !== ''))) {
					$this->smwQueueAdd($props, $k, $articles, true);
				}
			}
			$works = $uniqCSVVar('WD_QUOTES_WORKS');
			if (Lua::truth($works)) {
				$k = $this->smwPropRef($PF, 'work_name');
				if (Lua::truth(($k !== ''))) {
					$this->smwQueueAdd($props, $k, $works, true);
				}
			}
			$pubs = $uniqCSVVar('WD_QUOTES_PUBLISHERS');
			if (Lua::truth($pubs)) {
				$k = $this->smwPropRef($PF, 'publishing_house');
				if (Lua::truth(($k !== ''))) {
					$this->smwQueueAdd($props, $k, $pubs, true);
				}
			}
			$places = $uniqCSVVar('WD_QUOTES_PLACES');
			if (Lua::truth($places)) {
				$k = $this->smwPropRef($PF, 'place_of_publication');
				if (Lua::truth(($k !== ''))) {
					$this->smwQueueAdd($props, $k, $places, true);
				}
			}
		}
		{
			if (Lua::truth((Lua::truth($v412 = $args['sections']) ? ($args['sections'] !== '') : $v412))) {
				$lst = new Table([]);
				foreach (Lua::ipairs($this->splitCSV($args['sections'], ',')) as $_ => $r) {
					$r = $this->safeRubriqueValue($r);
					if (Lua::truth(($r !== ''))) {
						Lua::insert($lst, $r);
					}
				}
				$lst = $this->uniqList($lst);
				if (Lua::truth((Lua::truth($v413 = (Lua::truth($v414 = (Lua::len($lst) > 0)) ? $PF['prop_section'] : $v414)) ? ($PF['prop_section'] !== '') : $v413))) {
					$props[$PF['prop_section']] = $lst;
				}
			}
		}
		$this->smwSetSafe($props);
	}

	protected function seoL($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v415 = $this->context->message('Argument.seo', $lang, $key, ...$rest)) ? $v415 : '');
	}

	protected function seoJoinList($items = null) {
		$out = new Table([]);
		$seen = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v416 = $items) ? $v416 : new Table([]))) as $_ => $v) {
			$v = Lua::trim(Lua::str((Lua::truth($v417 = $v) ? $v417 : '')));
			if (Lua::truth((Lua::truth($v418 = ($v !== '')) ? !Lua::truth($seen[$v]) : $v418))) {
				$seen[$v] = true;
				$out[(Lua::len($out) + 1)] = $v;
			}
		}
		return Lua::concat($out, ', ');
	}

	protected function buildSeoKeywords($args = null, $lang = null) {
		$items = new Table([]);
		foreach (Lua::ipairs($this->splitCSV($args['keywords'], ',')) as $_ => $k) {
			$items[(Lua::len($items) + 1)] = $k;
		}
		foreach (Lua::ipairs($this->splitCSV($args['sections'], ',')) as $_ => $s) {
			$items[(Lua::len($items) + 1)] = $s;
		}
		return $this->seoJoinList($items);
	}

	protected function renderFullSEO($args = null, $pv = null, $lang = null) {
		$titleText = (Lua::truth($v419 = $pv['rawTitle']) ? $v419 : '');
		$ck = ('SEO
' . Lua::str((Lua::truth($v420 = $lang) ? $v420 : '')) . '
' . Lua::str((Lua::truth($v421 = $pv['pageId']) ? $v421 : '')) . '
' . $titleText . '
' . $this->seoKeyPart((Lua::truth($v422 = $args['keywords']) ? $v422 : '')) . '
' . $this->seoKeyPart((Lua::truth($v423 = $args['sections']) ? $v423 : '')));
		$hit = $this->cacheGet($this->SEO_CACHE, $ck);
		if (Lua::truth(($hit !== null))) {
			return '';
		}
		$this->cachePut($this->SEO_CACHE, $ck, true);
		$topic = Lua::trim(Lua::str((Lua::truth($v424 = $args['keywords']) ? $v424 : '')));
		if (Lua::truth(($topic === ''))) {
			$topic = $titleText;
		}
		$kw = $this->buildSeoKeywords($args, $lang);
		$this->wkSeoSet(new Table(['title' => ($titleText . $this->L($lang, 'seo', 'space') . ': ' . $this->L($lang, 'seo', 'shortdesc_plain') . ' – ' . $this->L($lang, 'seo', 'site_name')), 'title_mode' => ($titleText . $this->L($lang, 'seo', 'space') . ': ' . $this->L($lang, 'seo', 'shortdesc_plain')), 'title_separator' => Lua::format($this->seoL($lang, 'title_separator'), $titleText), 'description' => Lua::format($this->seoL($lang, 'description'), $topic), 'keywords' => $kw, 'author' => $this->seoL($lang, 'author'), 'image' => $this->seoL($lang, 'image'), 'image_alt' => $this->seoL($lang, 'image_alt'), 'type' => 'article', 'section' => $this->seoL($lang, 'section'), 'site_name' => $this->seoL($lang, 'site_name'), 'twitter_site' => $this->seoL($lang, 'twitter_site'), 'locale' => $this->seoL($lang, 'locale'), 'robots' => 'index,follow', 'googlebot' => 'index,follow']));
		return '';
	}

	protected function wkBuildWikiUrl($lang = null, $prefixKey = null, $labelKey = null) {
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $this->L($lang, 'urls', $labelKey)), 'WIKI'));
	}

	protected function wkBuildWikiUrlFromText($lang = null, $prefixKey = null, $text = null) {
		$text = Lua::trim(Lua::str((Lua::truth($v425 = $text) ? $v425 : '')));
		if (Lua::truth(($text === ''))) {
			return '';
		}
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $text), 'WIKI'));
	}

	protected function wkFullUrl($titleText = null) {
		$titleText = Lua::trim(Lua::str((Lua::truth($v426 = $titleText) ? $v426 : '')));
		if (Lua::truth(($titleText === ''))) {
			return '';
		}
		[$ok, $u] = Lua::pcall([$this->context, 'fullUrl'], $titleText);
		if (Lua::truth((Lua::truth($v427 = $ok) ? $u : $v427))) {
			return Lua::str($u);
		}
		return '';
	}

	protected function wkTakeFirst($items = null, $limit = null) {
		$out = new Table([]);
		$limit = (Lua::truth($v428 = Lua::number($limit)) ? $v428 : 0);
		if (Lua::truth(($limit <= 0))) {
			return $out;
		}
		$stop5 = Lua::len($items);
		for ($i = 1; $i <= $stop5; $i++) {
			if (Lua::truth(($i > $limit))) {
				break;
			}
			$out[(Lua::len($out) + 1)] = $items[$i];
		}
		return $out;
	}

	protected function wkBuildWebsiteNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return new Table(['@type' => 'WebSite', '@id' => ($homeUrl . '#website'), 'url' => $homeUrl, 'name' => $siteName]);
	}

	protected function wkBuildOrganizationNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return new Table(['@type' => 'Organization', '@id' => ($homeUrl . '#organization'), 'name' => $siteName, 'url' => $homeUrl]);
	}

	protected function wkBuildBreadcrumbNode($pv = null, $lang = null) {
		$homeLabel = $this->L($lang, 'urls', 'home_label');
		$homeUrl = $this->L($lang, 'urls', 'home');
		$argsLabel = $this->L($lang, 'urls', 'arguments_label');
		$argsUrl = $this->wkBuildWikiUrl($lang, 'portal_prefix', 'arguments_label');
		return new Table(['@type' => 'BreadcrumbList', '@id' => ($pv['pageUrl'] . '#breadcrumb'), 'itemListElement' => new Table([1 => new Table(['@type' => 'ListItem', 'position' => 1, 'item' => new Table(['@id' => $homeUrl, 'name' => $homeLabel])]), 2 => new Table(['@type' => 'ListItem', 'position' => 2, 'item' => new Table(['@id' => $argsUrl, 'name' => $argsLabel])]), 3 => new Table(['@type' => 'ListItem', 'position' => 3, 'item' => new Table(['@id' => $pv['pageUrl'], 'name' => $pv['rawTitle']])])])]);
	}

	protected function wkBuildAboutAndMentions($args = null) {
		$about = new Table([]);
		$mentions = new Table([]);
		$rawKeywords = $this->splitCSV((Lua::truth($v429 = (Lua::truth($v430 = $args) ? $args['keywords'] : $v430)) ? $v429 : ''), ',');
		$stop3 = Lua::len($rawKeywords);
		for ($i = 1; $i <= $stop3; $i++) {
			$kw = Lua::trim(Lua::str((Lua::truth($v431 = $rawKeywords[$i]) ? $v431 : '')));
			if (Lua::truth(($kw !== ''))) {
				$node = new Table(['@type' => 'Thing', 'name' => $kw]);
				if (Lua::truth(($i <= 3))) {
					$about[(Lua::len($about) + 1)] = $node;
				}
				else {
					$mentions[(Lua::len($mentions) + 1)] = $node;
				}
			}
		}
		return [$about, $mentions];
	}

	protected function wkBuildKeywords($args = null, $lang = null) {
		$out = new Table([]);
		$sections = $this->splitCSV((Lua::truth($v432 = (Lua::truth($v433 = $args) ? $args['sections'] : $v433)) ? $v432 : ''), ',');
		$termSetId = $this->wkBuildWikiUrl($lang, 'category_prefix', 'sections_label');
		$stop3 = Lua::len($sections);
		for ($i = 1; $i <= $stop3; $i++) {
			if (Lua::truth(($i > 4))) {
				break;
			}
			$section = Lua::trim(Lua::str((Lua::truth($v434 = $sections[$i]) ? $v434 : '')));
			if (Lua::truth(($section !== ''))) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'DefinedTerm', '@id' => $this->wkBuildWikiUrlFromText($lang, 'category_prefix', $section), 'name' => $section, 'inDefinedTermSet' => new Table(['@id' => $termSetId])]);
			}
		}
		return $out;
	}

	protected function wkBuildDiscussionUrl($pv = null, $lang = null) {
		return $this->wkBuildWikiUrlFromText($lang, 'discussion_prefix', $pv['rawTitle']);
	}

	protected function wkAppendAllValidIds($out = null, $titles = null, $suffix = null, $lang = null) {
		$added = false;
		foreach (Lua::ipairs((Lua::truth($v435 = $titles) ? $v435 : new Table([]))) as $_ => $title) {
			$title = $this->canonicalTitle(Lua::trim(Lua::str((Lua::truth($v436 = $title) ? $v436 : ''))));
			if (Lua::truth(($title !== ''))) {
				$url = $this->wkFullUrl($title, $lang);
				if (Lua::truth(($url !== ''))) {
					$out[(Lua::len($out) + 1)] = new Table(['@id' => ($url . $suffix)]);
					$added = true;
				}
			}
		}
		return $added;
	}

	protected function wkAppendFirstValidId($out = null, $titles = null, $suffix = null, $lang = null) {
		foreach (Lua::ipairs((Lua::truth($v437 = $titles) ? $v437 : new Table([]))) as $_ => $title) {
			$title = $this->canonicalTitle(Lua::trim(Lua::str((Lua::truth($v438 = $title) ? $v438 : ''))));
			if (Lua::truth(($title !== ''))) {
				$url = $this->wkFullUrl($title, $lang);
				if (Lua::truth(($url !== ''))) {
					$out[(Lua::len($out) + 1)] = new Table(['@id' => ($url . $suffix)]);
					return true;
				}
			}
		}
		return false;
	}

	protected function wkBuildClaimIsPartOf($lang = null, $cache = null) {
		$out = new Table([]);
		$out[(Lua::len($out) + 1)] = new Table(['@id' => $this->wkBuildWikiUrl($lang, 'category_prefix', 'arguments_label')]);
		$debats = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v439 = (Lua::truth($v440 = $cache) ? $cache['debatsPour'] : $v440)) ? $v439 : new Table([]))) as $_ => $d) {
			Lua::insert($debats, $this->canonicalTitle($d));
		}
		foreach (Lua::ipairs((Lua::truth($v441 = (Lua::truth($v442 = $cache) ? $cache['debatsContre'] : $v442)) ? $v441 : new Table([]))) as $_ => $d) {
			Lua::insert($debats, $this->canonicalTitle($d));
		}
		$debats = $this->uniqList($debats);
		if (Lua::truth($this->wkAppendAllValidIds($out, $debats, '#qa', $lang))) {
			return $out;
		}
		if (Lua::truth($this->wkAppendFirstValidId($out, (Lua::truth($v443 = (Lua::truth($v444 = $cache) ? $cache['justifs'] : $v444)) ? $v443 : new Table([])), '#claim', $lang))) {
			return $out;
		}
		if (Lua::truth($this->wkAppendFirstValidId($out, (Lua::truth($v445 = (Lua::truth($v446 = $cache) ? $cache['objs'] : $v446)) ? $v445 : new Table([])), '#claim', $lang))) {
			return $out;
		}
		return $out;
	}

	protected function wkBuildHasPart($args = null, $maps = null, $lang = null) {
		$out = new Table([]);
		$detailedDebate = Lua::trim(Lua::str((Lua::truth($v447 = (Lua::truth($v448 = $args) ? $args['dedicated-debate'] : $v448)) ? $v447 : '')));
		$detailedDebate = $this->canonicalTitle($detailedDebate);
		if (Lua::truth(($detailedDebate !== ''))) {
			$url = $this->wkFullUrl($detailedDebate, $lang);
			if (Lua::truth(($url !== ''))) {
				$out[(Lua::len($out) + 1)] = new Table(['@id' => ($url . '#qa')]);
				return $out;
			}
		}
		$justifItems = (Lua::truth($v449 = (Lua::truth($v450 = (Lua::truth($v451 = $maps) ? $maps['justif'] : $v451)) ? $maps['justif']['items'] : $v450)) ? $v449 : new Table([]));
		$objItems = (Lua::truth($v452 = (Lua::truth($v453 = (Lua::truth($v454 = $maps) ? $maps['obj'] : $v454)) ? $maps['obj']['items'] : $v453)) ? $v452 : new Table([]));
		$stop8 = min(Lua::len($justifItems), 3);
		for ($i = 1; $i <= $stop8; $i++) {
			$item = $justifItems[$i];
			$argTitle = Lua::trim(Lua::str((Lua::truth($v455 = (Lua::truth($v456 = $item) ? $item['page'] : $v456)) ? $v455 : '')));
			$argTitle = $this->canonicalTitle($argTitle);
			$url = $this->wkFullUrl($argTitle, $lang);
			if (Lua::truth(($url !== ''))) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'Claim', '@id' => ($url . '#claim'), 'additionalType' => $this->wkBuildWikiUrl($lang, 'property_prefix', 'justification_label')]);
			}
		}
		$stop12 = min(Lua::len($objItems), 1);
		for ($i = 1; $i <= $stop12; $i++) {
			$item = $objItems[$i];
			$argTitle = Lua::trim(Lua::str((Lua::truth($v457 = (Lua::truth($v458 = $item) ? $item['page'] : $v458)) ? $v457 : '')));
			$argTitle = $this->canonicalTitle($argTitle);
			$url = $this->wkFullUrl($argTitle, $lang);
			if (Lua::truth(($url !== ''))) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'Claim', '@id' => ($url . '#claim'), 'additionalType' => $this->wkBuildWikiUrl($lang, 'property_prefix', 'objection_label')]);
			}
		}
		return $out;
	}

	protected function wkBuildQuoteAuthorValue($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v459 = $raw) ? $v459 : '')));
		if (Lua::truth(($raw === ''))) {
			return null;
		}
		$authors = $this->splitCSV($raw, ',');
		if (Lua::truth((Lua::len($authors) === 0))) {
			return null;
		}
		if (Lua::truth((Lua::len($authors) === 1))) {
			return new Table(['@type' => 'Person', 'name' => $authors[1]]);
		}
		$out = new Table([]);
		foreach (Lua::ipairs($authors) as $_ => $one) {
			$one = Lua::trim(Lua::str((Lua::truth($v460 = $one) ? $v460 : '')));
			if (Lua::truth(($one !== ''))) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'Person', 'name' => $one]);
			}
		}
		if (Lua::truth((Lua::len($out) === 0))) {
			return null;
		}
		return $out;
	}

	protected function wkBuildSingleNodeFromFirstItem($items = null, $buildNode = null) {
		if (Lua::truth((Lua::truth($v461 = (Lua::type($items) !== 'table')) ? $v461 : !Lua::truth($items[1])))) {
			return new Table([]);
		}
		$node = $buildNode($items[1]);
		if (Lua::truth($node)) {
			return new Table([1 => $node]);
		}
		return new Table([]);
	}

	protected function wkBuildClaimCitationsFromQuotes($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v462 = $raw) ? $v462 : '')));
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		$items = $this->wkParseQuoteMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$authors, &$article, &$work, &$volume, &$number, &$publisher, &$date, &$link, &$node, &$authorValue) {
		$authors = Lua::trim(Lua::str((Lua::truth($v463 = $item['authors']) ? $v463 : '')));
		$article = Lua::trim(Lua::str((Lua::truth($v464 = $item['article']) ? $v464 : '')));
		$work = Lua::trim(Lua::str((Lua::truth($v465 = $item['work']) ? $v465 : '')));
		$volume = Lua::trim(Lua::str((Lua::truth($v466 = $item['volume']) ? $v466 : '')));
		$number = Lua::trim(Lua::str((Lua::truth($v467 = $item['number']) ? $v467 : '')));
		$publisher = Lua::trim(Lua::str((Lua::truth($v468 = $item['publisher']) ? $v468 : '')));
		$date = Lua::trim(Lua::str((Lua::truth($v469 = $item['date']) ? $v469 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v470 = $item['link']) ? $v470 : '')));
		$node = null;
		if (Lua::truth((Lua::truth($v471 = (Lua::truth($v472 = ($number !== '')) ? ($article !== '') : $v472)) ? ($work !== '') : $v471))) {
			$node = new Table(['@type' => 'Article', 'name' => $article, 'isPartOf' => new Table(['@type' => 'Periodical', 'name' => $work])]);
			if (Lua::truth(($volume !== ''))) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if (Lua::truth((Lua::truth($v473 = ($publisher !== '')) ? ($work !== '') : $v473))) {
				$node = new Table(['@type' => 'Book', 'name' => $work]);
				if (Lua::truth(($article !== ''))) {
					$node['description'] = $article;
				}
				if (Lua::truth(($volume !== ''))) {
					$node['volumeNumber'] = $volume;
				}
				if (Lua::truth(($number !== ''))) {
					$node['issueNumber'] = $number;
				}
			}
			else {
				if (Lua::truth((Lua::truth($v474 = ($link !== '')) ? ($article !== '') : $v474))) {
					$node = new Table(['@type' => 'WebPage', 'name' => $article]);
					if (Lua::truth(($work !== ''))) {
						$node['isPartOf'] = new Table(['@type' => 'WebSite', 'name' => $work]);
					}
				}
			}
		}
		if (Lua::truth(!Lua::truth($node))) {
			return null;
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if (Lua::truth($authorValue)) {
			$node['author'] = $authorValue;
		}
		if (Lua::truth(($publisher !== ''))) {
			$node['publisher'] = new Table(['@type' => 'Organization', 'name' => $publisher]);
		}
		if (Lua::truth(($date !== ''))) {
			$node['datePublished'] = $date;
		}
		if (Lua::truth(($link !== ''))) {
			$node['url'] = $link;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromBibliography($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v475 = $raw) ? $v475 : '')));
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		$items = $this->wkParseBiblioMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$authors, &$article, &$work, &$volume, &$number, &$publisher, &$date, &$link, &$node, &$authorValue) {
		$authors = Lua::trim(Lua::str((Lua::truth($v476 = $item['authors']) ? $v476 : '')));
		$article = Lua::trim(Lua::str((Lua::truth($v477 = $item['article']) ? $v477 : '')));
		$work = Lua::trim(Lua::str((Lua::truth($v478 = $item['work']) ? $v478 : '')));
		$volume = Lua::trim(Lua::str((Lua::truth($v479 = $item['volume']) ? $v479 : '')));
		$number = Lua::trim(Lua::str((Lua::truth($v480 = $item['number']) ? $v480 : '')));
		$publisher = Lua::trim(Lua::str((Lua::truth($v481 = $item['publisher']) ? $v481 : '')));
		$date = Lua::trim(Lua::str((Lua::truth($v482 = $item['date']) ? $v482 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v483 = $item['link']) ? $v483 : '')));
		$node = null;
		if (Lua::truth((Lua::truth($v484 = (Lua::truth($v485 = ($number !== '')) ? ($article !== '') : $v485)) ? ($work !== '') : $v484))) {
			$node = new Table(['@type' => 'Article', 'name' => $article, 'isPartOf' => new Table(['@type' => 'Periodical', 'name' => $work])]);
			if (Lua::truth(($volume !== ''))) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if (Lua::truth((Lua::truth($v486 = ($publisher !== '')) ? ($work !== '') : $v486))) {
				$node = new Table(['@type' => 'Book', 'name' => $work]);
				if (Lua::truth(($article !== ''))) {
					$node['description'] = $article;
				}
				if (Lua::truth(($volume !== ''))) {
					$node['volumeNumber'] = $volume;
				}
				if (Lua::truth(($number !== ''))) {
					$node['issueNumber'] = $number;
				}
			}
			else {
				if (Lua::truth((Lua::truth($v487 = ($link !== '')) ? ($article !== '') : $v487))) {
					$node = new Table(['@type' => 'WebPage', 'name' => $article]);
					if (Lua::truth(($work !== ''))) {
						$node['isPartOf'] = new Table(['@type' => 'WebSite', 'name' => $work]);
					}
				}
			}
		}
		if (Lua::truth(!Lua::truth($node))) {
			return null;
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if (Lua::truth($authorValue)) {
			$node['author'] = $authorValue;
		}
		if (Lua::truth(($publisher !== ''))) {
			$node['publisher'] = new Table(['@type' => 'Organization', 'name' => $publisher]);
		}
		if (Lua::truth(($date !== ''))) {
			$node['datePublished'] = $date;
		}
		if (Lua::truth(($link !== ''))) {
			$node['url'] = $link;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromWebliography($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v488 = $raw) ? $v488 : '')));
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		$items = $this->wkParseWeblioMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$page, &$site, &$link, &$date, &$node) {
		$page = Lua::trim(Lua::str((Lua::truth($v489 = $item['page']) ? $v489 : '')));
		$site = Lua::trim(Lua::str((Lua::truth($v490 = $item['site']) ? $v490 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v491 = $item['link']) ? $v491 : '')));
		$date = Lua::trim(Lua::str((Lua::truth($v492 = $item['date']) ? $v492 : '')));
		if (Lua::truth((Lua::truth($v493 = ($link === '')) ? $v493 : ($page === '')))) {
			return null;
		}
		$node = new Table(['@type' => 'WebPage', 'name' => $page, 'url' => $link]);
		if (Lua::truth(($site !== ''))) {
			$node['isPartOf'] = new Table(['@type' => 'WebSite', 'name' => $site]);
		}
		if (Lua::truth(($date !== ''))) {
			$node['datePublished'] = $date;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromVideography($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v494 = $raw) ? $v494 : '')));
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		$items = $this->wkParseVideoMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$title, &$link, &$authors, &$node, &$authorValue) {
		$title = Lua::trim(Lua::str((Lua::truth($v495 = $item['title']) ? $v495 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v496 = $item['link']) ? $v496 : '')));
		$authors = Lua::trim(Lua::str((Lua::truth($v497 = $item['authors']) ? $v497 : '')));
		if (Lua::truth((Lua::truth($v498 = ($title === '')) ? $v498 : ($link === '')))) {
			return null;
		}
		$node = new Table(['@type' => 'VideoObject', 'name' => $title, 'url' => $link]);
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if (Lua::truth($authorValue)) {
			$node['author'] = $authorValue;
		}
		return $node;
		});
	}


	protected function renderArgumentSchemaJSONLD($args = null, $pv = null, $lang = null, $cache = null, $PF = null, $maps = null) {
		$this->ensureDebateParentsFinal($pv, $cache, $PF);
		$this->ensureArgumentParents($pv, $cache, $PF);
		$this->ensureBreadcrumbSelection($pv, $cache, new Table([]), $lang, $PF);
		$websiteNode = $this->wkBuildWebsiteNode($lang);
		$breadcrumbNode = $this->wkBuildBreadcrumbNode($pv, $lang);
		$organizationNode = $this->wkBuildOrganizationNode($lang);
		[$about, $mentions] = $this->wkBuildAboutAndMentions($args);
		$keywords = $this->wkBuildKeywords($args, $lang);
		$isPartOf = $this->wkBuildClaimIsPartOf($lang, $cache);
		$hasPart = $this->wkBuildHasPart($args, $maps, $lang);
		$creationDate = $this->getPageCreation($pv, $args);
		$citations = new Table([]);
		$q = $this->wkBuildClaimCitationsFromQuotes((Lua::truth($v500 = $args['_quotes_wikitext']) ? $v500 : ''));
		if (Lua::truth($q[1])) {
			$citations[(Lua::len($citations) + 1)] = $q[1];
		}
		$b = $this->wkBuildClaimCitationsFromBibliography((Lua::truth($v501 = $args['bibliography']) ? $v501 : ''));
		if (Lua::truth($b[1])) {
			$citations[(Lua::len($citations) + 1)] = $b[1];
		}
		$w = $this->wkBuildClaimCitationsFromWebliography((Lua::truth($v502 = $args['webliography']) ? $v502 : ''));
		if (Lua::truth($w[1])) {
			$citations[(Lua::len($citations) + 1)] = $w[1];
		}
		$subjectOf = $this->wkBuildClaimCitationsFromVideography((Lua::truth($v503 = $args['videography']) ? $v503 : ''));
		$pageNode = new Table(['@type' => 'WebPage', '@id' => $pv['pageUrl'], 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'isPartOf' => new Table(['@id' => ($this->L($lang, 'urls', 'home') . '#website')]), 'breadcrumb' => new Table(['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'mainEntity' => new Table(['@id' => ($pv['pageUrl'] . '#claim')])]);
		$claimNode = new Table(['@type' => 'Claim', '@id' => ($pv['pageUrl'] . '#claim'), 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'learningResourceType' => 'argument', 'educationalUse' => 'analysis', 'educationalLevel' => 'higher education', 'audience' => new Table(['@type' => 'EducationalAudience', 'educationalRole' => 'student']), 'isPartOf' => $isPartOf, 'breadcrumb' => new Table(['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang), 'publisher' => new Table(['@id' => ($this->L($lang, 'urls', 'home') . '#organization')])]);
		if (Lua::truth((Lua::truth($v504 = $args['established-name']) ? (Lua::trim($args['established-name']) !== '') : $v504))) {
			$claimNode['alternateName'] = $args['established-name'];
		}
		{
			$summary = Lua::trim(Lua::str((Lua::truth($v505 = (Lua::truth($v506 = $args) ? $args['summary'] : $v506)) ? $v505 : '')));
			if (Lua::truth(($summary !== ''))) {
				$claimNode['abstract'] = $summary;
			}
		}
		if (Lua::truth($creationDate)) {
			$claimNode['datePublished'] = $creationDate;
		}
		if (Lua::truth((Lua::len($about) > 0))) {
			$claimNode['about'] = $about;
		}
		if (Lua::truth((Lua::len($mentions) > 0))) {
			$claimNode['mentions'] = $mentions;
		}
		if (Lua::truth((Lua::len($keywords) > 0))) {
			$claimNode['keywords'] = $keywords;
		}
		if (Lua::truth((Lua::len($hasPart) > 0))) {
			$claimNode['hasPart'] = $hasPart;
		}
		if (Lua::truth((Lua::len($citations) > 0))) {
			$claimNode['citation'] = $citations;
		}
		if (Lua::truth((Lua::len($subjectOf) > 0))) {
			$claimNode['subjectOf'] = $subjectOf;
		}
		$data = new Table(['@context' => 'https://schema.org', '@graph' => new Table([1 => $websiteNode, 2 => $organizationNode, 3 => $breadcrumbNode, 4 => $pageNode, 5 => $claimNode])]);
		$json = $this->safeJsonEncode($data);
		return $this->context->jsonLd($json);
	}

	protected function canonicalTitle($s = null) {
		$s = Lua::trim(Lua::str((Lua::truth($v507 = $s) ? $v507 : '')));
		if (Lua::truth(($s === ''))) {
			return '';
		}
		$s = Lua::gsub($s, '^:%s*', '');
		$s = Lua::gsub(Lua::gsub($s, '^%[%[%s*', ''), '%s*%]%]$', '');
		$s = (Lua::truth($v508 = Lua::match($s, '^([^%]|]+)%|')) ? $v508 : $s);
		$s = Lua::trim($s);
		$t = $this->titleNewCached($s);
		return (Lua::truth($v509 = (Lua::truth($v510 = $t) ? $t['prefixedText'] : $v510)) ? $v509 : $s);
	}

	protected function computeArgumentMapsFromData($args = null) {
		$rawJ = (Lua::truth($v511 = $args['justifications']) ? $v511 : '');
		$rawO = (Lua::truth($v512 = $args['objections']) ? $v512 : '');
		$justifItems = $this->wkExtractItemsFromSerializedData($rawJ);
		$objItems = $this->wkExtractItemsFromSerializedData($rawO);
		return new Table(['justif' => $this->wkBuildArgumentData($justifItems), 'obj' => $this->wkBuildArgumentData($objItems)]);
	}

	public function render($frame = null) {
		$this->vset('WD_QUOTES_AUTHORS', '');
		$this->vset('WD_QUOTES_ARTICLES', '');
		$this->vset('WD_QUOTES_WORKS', '');
		$this->vset('WD_QUOTES_PUBLISHERS', '');
		$this->vset('WD_QUOTES_PLACES', '');
		$this->vset('WD_QUOTES_MAX', '');
		$this->vset('WD_QUOTE_1', '');
		$this->vset('WD_QUOTE_2', '');
		$this->vset('WD_QUOTE_3', '');
		$this->vset('WD_QUOTE_4', '');
		$this->EXPAND_CACHE = $this->cacheMake(250);
		$this->CARD_CACHE = $this->cacheMake(600);
		$this->SMW_ASK_CACHE = $this->cacheMake(250);
		$this->SMW_TITLES_CACHE = $this->cacheMake(250);
		$this->I18N_DOMAIN = new Table([]);
		$this->I18N_MSG = new Table([]);
		$this->TITLE_CACHE = $this->cacheMake(600);
		$this->SEO_CACHE = $this->cacheMake(60);
		$args = $this->getArgs($frame);
		$norm = function($k = null) use (&$frame, &$EXPAND_CACHE, &$CARD_CACHE, &$SMW_ASK_CACHE, &$SMW_TITLES_CACHE, &$I18N_DOMAIN, &$I18N_MSG, &$TITLE_CACHE, &$SEO_CACHE, &$args, &$norm, &$v, &$rawB, &$rawW, &$rawV, &$lang, &$pv, &$cache, &$pieces, &$cats, &$PF, &$q, &$ok, &$r, &$hasResume, &$hasQuotes, &$hasRefs, &$titleLookup, &$argLookup, &$out, &$maps, &$btnForm, &$summaryId, &$detailed, &$editRefs, &$refsHtml, &$justifItems, &$objItems, &$editJust, &$raw, &$lookup, &$_, &$x, &$key, &$ulJ, &$lis, &$i, &$it, &$baseField, &$sideLabel, &$label, &$tooltip, &$rqForm, &$rqQuery, &$editObj, &$ulO) {
		$v = $args[$k];
		if (Lua::truth((Lua::type($v) !== 'string'))) {
			return null;
		}
		$v = Lua::trim($v);
		if (Lua::truth(($v === ''))) {
			$args[$k] = null;
		}
		else {
			$args[$k] = $v;
		}
		};
		$norm('summary');
		$norm('quotes');
		$norm('bibliography');
		$norm('webliography');
		$norm('videography');
		$norm('keywords');
		$norm('sections');
		$norm('interlanguage');
		$norm('dedicated-debate');
		$norm('title-warnings');
		$norm('argument-warnings');
		$norm('summary-warnings');
		$norm('justification-warnings');
		$norm('objection-warnings');
		$rawB = (Lua::truth($v513 = $args['bibliography']) ? $v513 : '');
		$rawW = (Lua::truth($v514 = $args['webliography']) ? $v514 : '');
		$rawV = (Lua::truth($v515 = $args['videography']) ? $v515 : '');
		if (Lua::truth((Lua::type($args['justifications']) !== 'string'))) {
			$args['justifications'] = null;
		}
		if (Lua::truth((Lua::type($args['objections']) !== 'string'))) {
			$args['objections'] = null;
		}
		$lang = $this->detectLang($args);
		$pv = $this->computePageVars();
		$pv['lang'] = $lang;
		$cache = $this->makeSMWCache();
		[$pieces, $cats] = [new Table([]), new Table([])];
		$PF = new Table([]);
		$PF['file_search'] = $this->L($lang, 'files', 'search');
		$PF['parent_is_justification_of'] = $this->L($lang, 'text', 'parent_is_justification_of');
		$PF['parent_is_objection_to'] = $this->L($lang, 'text', 'parent_is_objection_to');
		$PF['keywords_label'] = $this->L($lang, 'text', 'keywords_label_text');
		$PF['none'] = $this->L($lang, 'text', 'none_label');
		$PF['keywords_field'] = $this->L($lang, 'params', 'keywords_field');
		$PF['form_search_by_keywords'] = $this->L($lang, 'forms', 'search_by_keywords_title');
		$PF['search_by_keyword_tt'] = $this->L($lang, 'text', 'search_by_keyword_tt');
		$PF['search_type_field'] = Lua::str((Lua::truth($v516 = $this->L($lang, 'params', 'search_type_field')) ? $v516 : ''));
		$PF['search_type_value'] = Lua::str((Lua::truth($v517 = $this->L($lang, 'params', 'search_type_value')) ? $v517 : ''));
		$PF['form_edit_keywords'] = $this->L($lang, 'forms', 'keywords_edit_title');
		$PF['edit_keywords_tooltip'] = $this->L($lang, 'text', 'keywords_edit_tt');
		$PF['form_edit_summary'] = $this->L($lang, 'forms', 'summary_edit_title');
		$PF['edit_summary_tooltip'] = $this->L($lang, 'text', 'summary_edit_tt');
		$PF['form_edit_quotes'] = $this->L($lang, 'forms', 'quotes_edit_title');
		$PF['edit_quotes_tooltip'] = $this->L($lang, 'text', 'quotes_edit_tt');
		$PF['form_edit_references'] = $this->L($lang, 'forms', 'references_edit_title');
		$PF['edit_references_tooltip'] = $this->L($lang, 'text', 'references_edit_tt');
		$PF['form_edit_justifications'] = $this->L($lang, 'forms', 'justifications_form');
		$PF['edit_justifications_tooltip'] = $this->L($lang, 'text', 'justifications_edit_tt');
		$PF['form_edit_objections'] = $this->L($lang, 'forms', 'objections_form');
		$PF['edit_objections_tooltip'] = $this->L($lang, 'text', 'objections_edit_tt');
		$PF['form_new_arg_title'] = $this->L($lang, 'forms', 'new_arg_title');
		$PF['arg_new_title_field_base'] = $this->L($lang, 'text', 'arg_new_title_field_base');
		$PF['justification_add_label'] = $this->L($lang, 'text', 'justification_add_label');
		$PF['justification_add_tt'] = $this->L($lang, 'text', 'justification_add_tt');
		$PF['objection_add_label'] = $this->L($lang, 'text', 'objection_add_label');
		$PF['objection_add_tt'] = $this->L($lang, 'text', 'objection_add_tt');
		$PF['summary'] = $this->L($lang, 'text', 'summary_title');
		$PF['no_summary'] = $this->L($lang, 'text', 'none_summary_msg');
		$PF['quotes'] = $this->L($lang, 'text', 'quotes_title');
		$PF['no_quotes'] = $this->L($lang, 'text', 'none_quotes_msg');
		$PF['references'] = $this->L($lang, 'text', 'references_title');
		$PF['no_references'] = $this->L($lang, 'text', 'none_references_msg');
		$PF['justifications'] = $this->L($lang, 'text', 'justifications_title');
		$PF['no_justifications'] = $this->L($lang, 'text', 'none_justifications_msg');
		$PF['no_justifications_short'] = $this->L($lang, 'text', 'none_justifications_short_msg');
		$PF['objections'] = $this->L($lang, 'text', 'objections_title');
		$PF['no_objections'] = $this->L($lang, 'text', 'none_objections_msg');
		$PF['no_objections_short'] = $this->L($lang, 'text', 'none_objections_short_msg');
		$PF['form_edit_detailed_debate'] = $this->L($lang, 'forms', 'detailed_debate_edit_title');
		$PF['edit_detailed_debate_tooltip'] = $this->L($lang, 'text', 'detailed_debate_edit_tt');
		$PF['detailed_debate'] = $this->L($lang, 'text', 'detailed_debate_title');
		$PF['detailed_debate_label'] = $this->L($lang, 'text', 'detailed_debate_label_text');
		$PF['arg_is_debate'] = $this->L($lang, 'text', 'arg_is_debate_msg');
		$PF['form_edit_sections'] = $this->L($lang, 'forms', 'sections_edit_title');
		$PF['edit_sections_tooltip'] = $this->L($lang, 'text', 'sections_edit_tt');
		$PF['form_edit_interlanguage'] = $this->L($lang, 'forms', 'interlanguage_edit_title');
		$PF['edit_interlanguage_tooltip'] = $this->L($lang, 'text', 'interlanguage_edit_tt');
		$PF['edit'] = $this->L($lang, 'text', 'edit_label');
		$PF['rename_link_text'] = $this->L($lang, 'text', 'rename_link_label');
		$PF['type_justification'] = $this->L($lang, 'params', 'justification_type_label');
		$PF['type_objection'] = $this->L($lang, 'params', 'objection_type_label');
		$PF['unknown_author'] = $this->L($lang, 'text', 'unknown_author');
		$PF['volume_label'] = $this->L($lang, 'text', 'volume_label');
		$PF['issue_label'] = $this->L($lang, 'text', 'issue_label');
		$PF['more_quotes_button'] = $this->L($lang, 'text', 'more_quotes_button');
		$PF['more_quotes_note'] = $this->L($lang, 'text', 'more_quotes_note');
		$PF['parent_debates_title_1'] = $this->L($lang, 'text', 'parent_debates_title_1');
		$PF['parent_debates_title_n'] = $this->L($lang, 'text', 'parent_debates_title_n');
		$PF['cat_arguments'] = $this->L($lang, 'categories', 'arguments');
		$PF['cat_no_keywords'] = $this->L($lang, 'categories', 'keywords_missing');
		$PF['cat_no_summary'] = $this->L($lang, 'categories', 'summary_missing');
		$PF['cat_no_quotes'] = $this->L($lang, 'categories', 'quotes_missing');
		$PF['cat_no_references'] = $this->L($lang, 'categories', 'references_missing');
		$PF['cat_no_justifications'] = $this->L($lang, 'categories', 'justifications_missing');
		$PF['cat_no_objections'] = $this->L($lang, 'categories', 'objections_missing');
		$PF['cat_no_sections'] = $this->L($lang, 'categories', 'sections_missing');
		$PF['cat_no_content'] = $this->L($lang, 'categories', 'content_missing');
		$PF['cat_has_detailed_debate'] = $this->L($lang, 'categories', 'has_detailed_debate');
		$PF['cat_content_to_transfer'] = $this->L($lang, 'categories', 'content_to_transfer');
		$PF['cat_no_authors'] = $this->L($lang, 'categories', 'authors_missing');
		$PF['prop_arg_name'] = $this->L($lang, 'props', 'arg_page_name');
		$PF['prop_arg_number'] = $this->L($lang, 'props', 'arg_number');
		$PF['prop_argument_map'] = $this->L($lang, 'props', 'argument_map');
		$PF['prop_justif_list'] = $this->L($lang, 'props', 'justif_list');
		$PF['prop_obj_list'] = $this->L($lang, 'props', 'obj_list');
		$PF['prop_keyword'] = $this->L($lang, 'props', 'keyword');
		$PF['prop_detailed_debate'] = $this->L($lang, 'props', 'detailed_debate');
		$PF['prop_author'] = $this->L($lang, 'props', 'author');
		$PF['prop_article_name'] = $this->L($lang, 'props', 'article_name');
		$PF['prop_work_name'] = $this->L($lang, 'props', 'work_name');
		$PF['prop_publishing_house'] = $this->L($lang, 'props', 'publishing_house');
		$PF['prop_place_of_publication'] = $this->L($lang, 'props', 'place_of_publication');
		$PF['prop_arg_pro'] = $this->L($lang, 'props', 'arg_pro');
		$PF['prop_arg_con'] = $this->L($lang, 'props', 'arg_con');
		$PF['prop_justif'] = $this->L($lang, 'props', 'justif');
		$PF['prop_obj'] = $this->L($lang, 'props', 'obj');
		$PF['prop_breadcrumb'] = $this->L($lang, 'props', 'breadcrumb');
		$PF['prop_debate_name'] = $this->L($lang, 'props', 'debate_name');
		$PF['prop_parent_debate'] = $this->L($lang, 'props', 'parent_debate');
		$PF['prop_parent_argument'] = $this->L($lang, 'props', 'parent_argument');
		$PF['prop_section'] = $this->L($lang, 'props', 'section');
		$PF['cat_initialized'] = $this->L($lang, 'categories', 'initialized');
		$PF['cat_uninitialized'] = $this->L($lang, 'categories', 'uninitialized');
		$PF['init_warning_title'] = $this->L($lang, 'text', 'init_warning_title');
		$PF['init_warning_intro'] = $this->L($lang, 'text', 'init_warning_intro');
		$PF['init_yes_finish_label'] = $this->L($lang, 'text', 'init_yes_finish_label');
		$PF['init_yes_tt'] = $this->L($lang, 'text', 'init_yes_tt');
		$PF['init_ok_text'] = $this->L($lang, 'text', 'init_ok_text');
		$PF['init_no_rename_label'] = $this->L($lang, 'text', 'init_no_rename_label');
		$PF['init_summary_pro'] = $this->L($lang, 'text', 'init_summary_pro');
		$PF['init_summary_con'] = $this->L($lang, 'text', 'init_summary_con');
		$PF['init_summary_justif'] = $this->L($lang, 'text', 'init_summary_justif');
		$PF['init_summary_obj'] = $this->L($lang, 'text', 'init_summary_obj');
		{
			$q = $args['quotes'];
			if (Lua::truth((Lua::truth($v518 = (Lua::type($q) === 'string')) ? ($q !== '') : $v518))) {
				[$ok, $r] = Lua::pcall(function($text) { return $this->context->expandQuotes($text); }, $q);
				if (Lua::truth((Lua::truth($v519 = $ok) ? (Lua::type($r) === 'string') : $v519))) {
					$args['_quotes_wikitext'] = $r;
					$args['quotes'] = $this->wkRenderQuotesFromSerialized($r, $pv, $lang, $cats, $PF);
				}
				else {
					$args['_quotes_wikitext'] = '';
					$args['quotes'] = '';
				}
			}
		}
		$hasResume = ($args['summary'] !== null);
		$hasQuotes = ($args['quotes'] !== null);
		$hasRefs = (Lua::truth($v520 = (Lua::truth($v521 = ($args['bibliography'] !== null)) ? $v521 : ($args['webliography'] !== null))) ? $v520 : ($args['videography'] !== null));
		if (Lua::truth($args['established-name'])) {
			Lua::insert($pieces, $this->context->shortDescription($args['established-name']));
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'has_alt_name') . ']]'));
		}
		Lua::insert($cats, ('[[Category:' . $PF['cat_arguments'] . ']]'));
		Lua::insert($pieces, $this->renderTitleWarnings($pv, $cats, $lang));
		{
			$titleLookup = $this->buildBannerLabelLookup($lang, $this->TITLE_WARNING_BANNER_KEYS);
			$argLookup = $this->buildBannerLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
			$out = new Table([]);
			if (Lua::truth($args['title-warnings'])) {
				Lua::insert($out, $this->renderBannersFromArgList($args['title-warnings'], $titleLookup, $pv, $lang, $cats));
			}
			if (Lua::truth((Lua::len($out) > 0))) {
				Lua::insert($pieces, Lua::concat($out));
			}
		}
		$maps = $this->computeArgumentMapsFromData($args);
		$this->ensureBreadcrumbSelection($pv, $cache, $cats, $lang, $PF);
		Lua::insert($pieces, $this->renderDebateParentsBoxes($pv, $cats, $lang, $cache, $PF));
		Lua::insert($pieces, $this->renderArgumentParentsBoxes($pv, $cats, $lang, $cache, $PF));
		Lua::insert($pieces, $this->renderInitializationBlock($args, $pv, $cats, $lang, $cache, $PF, $maps));
		Lua::insert($pieces, $this->renderKeywords($args, $pv, $cats, $lang, $PF));
		if (Lua::truth($args['argument-warnings'])) {
			$argLookup = $this->buildBannerLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
			$out = new Table([]);
			$btnForm = $this->addDataLink($this->L($lang, 'forms', 'banners_edit_title'), $pv['rawTitle'], '', $this->L($lang, 'text', 'argument_banners_edit_tt'), new Table(['editButton' => true]));
			Lua::insert($out, ('<h2 id="Summary" class="section-modifiable wk-icon-before wk-icon-flag">' . $this->L($lang, 'text', 'warnings_title') . $btnForm . '</h2>'));
			Lua::insert($out, $this->renderBannersFromArgList($args['argument-warnings'], $argLookup, $pv, $lang, $cats));
			Lua::insert($pieces, Lua::concat($out));
		}
		$summaryId = ' id="Summary"';
		if (Lua::truth((Lua::truth($v522 = $args['argument-warnings']) ? (Lua::str($args['argument-warnings']) !== '') : $v522))) {
			$summaryId = '';
		}
		Lua::insert($pieces, $this->renderSummary($args, $pv, $cats, $lang, $PF, $summaryId));
		Lua::insert($pieces, $this->renderQuotes($args, $pv, $cats, $lang, $PF));
		$detailed = $this->renderDebateDetailed($args, $pv, $cats, $lang, $PF);
		if (Lua::truth(($detailed !== ''))) {
			Lua::insert($pieces, $detailed);
		}
		else {
			$out = new Table([]);
			$editRefs = $this->addDataEditLink($PF['form_edit_references'], $pv['rawTitle'], '&nbsp;', $PF['edit_references_tooltip']);
			Lua::insert($out, ('<h2 class="section-modifiable wk-icon-before wk-icon-bibliography">' . $PF['references'] . $editRefs . '</h2>'));
			{
				$refsHtml = $this->wkRenderReferencesFromRaw($rawB, $rawW, $rawV, $pv, $lang, $cats, $PF, null, $args);
				if (Lua::truth(($refsHtml !== ''))) {
					Lua::insert($out, ('<ul class="references-argument">' . $refsHtml . '</ul>'));
				}
				else {
					Lua::insert($out, ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_references'] . '</div>'));
					Lua::insert($cats, ('[[Category:' . $PF['cat_no_references'] . ']]'));
				}
			}
			$justifItems = (Lua::truth($v523 = (Lua::truth($v524 = $maps['justif']) ? $maps['justif']['items'] : $v524)) ? $v523 : new Table([]));
			$objItems = (Lua::truth($v525 = (Lua::truth($v526 = $maps['obj']) ? $maps['obj']['items'] : $v526)) ? $v525 : new Table([]));
			$editJust = $this->addDataEditLink($PF['form_edit_justifications'], $pv['rawTitle'], '&nbsp;', $PF['edit_justifications_tooltip']);
			Lua::insert($out, ('<h2 class="section-modifiable wk-icon-before wk-icon-argument is-pro">' . $PF['justifications'] . $editJust . '</h2>'));
			{
				$raw = $args['justification-warnings'];
				$raw = (Lua::truth($v527 = (Lua::truth($v528 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v528)) ? $v527 : '');
				if (Lua::truth(($raw !== ''))) {
					$lookup = $this->buildSummaryLabelLookup($lang, $this->JUSTIFICATION_WARNING_BANNER_KEYS);
					foreach (Lua::ipairs($this->splitCSV($raw, ',')) as $_ => $x) {
						$key = $this->resolveKeyFromLookup($x, $lookup, $this->JUSTIFICATION_WARNING_BANNER_KEYS);
						if (Lua::truth(($key !== ''))) {
							Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, new Table(['reorg' => true, 'form' => $PF['form_edit_justifications'], 'tooltip' => $PF['edit_justifications_tooltip']]), $cats));
						}
					}
				}
			}
			if (Lua::truth((Lua::len($justifItems) > 0))) {
				$ulJ = (HtmlBuilder::create('ul'))->addClass('argument-list is-pro');
				$lis = new Table([]);
				foreach (Lua::ipairs($justifItems) as $i => $it) {
					Lua::insert($lis, $this->wkRenderArgumentLi($pv, $lang, $it, 'justif', $i, $cats));
				}
				($ulJ)->wikitext(Lua::concat($lis, '
'));
				Lua::insert($out, Lua::str($ulJ));
			}
			else {
				Lua::insert($out, ('<div class="aucun-argument navigation-not-searchable">' . $PF['no_justifications'] . '</div>'));
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_justifications'] . ']]'));
			}
			{
				$baseField = Lua::str((Lua::truth($v529 = $PF['arg_new_title_field_base']) ? $v529 : ''));
				$sideLabel = Lua::str((Lua::truth($v530 = $PF['side_pro_word']) ? $v530 : 'pour'));
				$label = Lua::str((Lua::truth($v531 = $PF['justification_add_label']) ? $v531 : ''));
				$tooltip = Lua::format($PF['justification_add_tt'], $pv['rawTitle']);
				$rqForm = Lua::str((Lua::truth($v532 = $PF['form_new_arg_title']) ? $v532 : ''));
				$rqQuery = new Table([]);
				$rqQuery[($baseField . '[type]')] = Lua::str((Lua::truth($v533 = $PF['type_justification']) ? $v533 : ''));
				$rqQuery[($baseField . '[ID]')] = Lua::str((Lua::truth($v534 = $pv['pageId']) ? $v534 : ''));
				$rqQuery['_run'] = '1';
				Lua::insert($out, ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($rqForm, $label, $tooltip, $rqQuery) . '</div>'));
			}
			$editObj = $this->addDataEditLink($PF['form_edit_objections'], $pv['rawTitle'], '&nbsp;', $PF['edit_objections_tooltip']);
			Lua::insert($out, ($this->listBreaker() . '<h2 class="section-modifiable wk-icon-before wk-icon-argument is-con">' . $PF['objections'] . $editObj . '</h2>'));
			{
				$raw = $args['objection-warnings'];
				$raw = (Lua::truth($v535 = (Lua::truth($v536 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v536)) ? $v535 : '');
				if (Lua::truth(($raw !== ''))) {
					$lookup = $this->buildSummaryLabelLookup($lang, $this->OBJECTION_WARNING_BANNER_KEYS);
					foreach (Lua::ipairs($this->splitCSV($raw, ',')) as $_ => $x) {
						$key = $this->resolveKeyFromLookup($x, $lookup, $this->OBJECTION_WARNING_BANNER_KEYS);
						if (Lua::truth(($key !== ''))) {
							Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, new Table(['reorg' => true, 'form' => $PF['form_edit_objections'], 'tooltip' => $PF['edit_objections_tooltip']]), $cats));
						}
					}
				}
			}
			if (Lua::truth((Lua::len($objItems) > 0))) {
				$ulO = (HtmlBuilder::create('ul'))->addClass('argument-list is-con');
				$lis = new Table([]);
				foreach (Lua::ipairs($objItems) as $i => $it) {
					Lua::insert($lis, $this->wkRenderArgumentLi($pv, $lang, $it, 'obj', $i, $cats));
				}
				($ulO)->wikitext(Lua::concat($lis, '
'));
				Lua::insert($out, Lua::str($ulO));
			}
			else {
				Lua::insert($out, ('<div class="aucun-argument navigation-not-searchable">' . $PF['no_objections'] . '</div>'));
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_objections'] . ']]'));
			}
			{
				$baseField = Lua::str((Lua::truth($v537 = $PF['arg_new_title_field_base']) ? $v537 : ''));
				$label = Lua::str((Lua::truth($v538 = $PF['objection_add_label']) ? $v538 : ''));
				$tooltip = Lua::format($PF['objection_add_tt'], $pv['rawTitle']);
				$rqForm = Lua::str((Lua::truth($v539 = $PF['form_new_arg_title']) ? $v539 : ''));
				$rqQuery = new Table([]);
				$rqQuery[($baseField . '[type]')] = Lua::str((Lua::truth($v540 = $PF['type_objection']) ? $v540 : ''));
				$rqQuery[($baseField . '[ID]')] = Lua::str((Lua::truth($v541 = $pv['pageId']) ? $v541 : ''));
				$rqQuery['_run'] = '1';
				Lua::insert($out, ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($rqForm, $label, $tooltip, $rqQuery) . '</div>'));
			}
			Lua::insert($pieces, Lua::concat($out));
		}
		Lua::insert($pieces, $this->renderDebatesParentsBottom($pv, $lang, $cache, $PF));
		if (Lua::truth($args['interlanguage'])) {
			Lua::insert($pieces, $args['interlanguage']);
		}
		if (Lua::truth((Lua::truth($v542 = !Lua::truth($args['creation-date'])) ? $v542 : (Lua::trim($args['creation-date']) === '')))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'creation_date_missing') . ']]'));
		}
		Lua::insert($pieces, $this->renderRubriques($args, $cats, $lang, $PF));
		if (Lua::truth((Lua::truth($v543 = (Lua::truth($v544 = !Lua::truth($hasResume)) ? !Lua::truth($hasQuotes) : $v544)) ? !Lua::truth($hasRefs) : $v543))) {
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_content'] . ']]'));
		}
		$this->setSemanticData($args, $pv, $lang, $cache, $PF, $maps, $rawB, $rawW, $rawV);
		$this->renderFullSEO($args, $pv, $lang);
		Lua::insert($pieces, Lua::concat($cats, ''));
		Lua::insert($pieces, ('<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[[Special:MovePage/' . $pv['encoded'] . '| ' . $PF['rename_link_text'] . ']]</span>'));
		Lua::insert($pieces, ('<span id="bouton-modifier-categories" style="display: none;">' . $this->addDataEditLink($PF['form_edit_sections'], $pv['rawTitle'], $PF['edit'], $PF['edit_sections_tooltip']) . '</span>'));
		if (Lua::truth((Lua::truth($v545 = $args['interlanguage']) ? (Lua::trim(Lua::str((Lua::truth($v546 = $args['interlanguage']) ? $v546 : ''))) !== '') : $v545))) {
			Lua::insert($pieces, ('<span id="bouton-modifier-interlangue" style="display: none;"' . ' data-wk-tooltip="' . $this->escapeAttr($PF['edit_interlanguage_tooltip']) . '">' . $this->addDataEditLink($PF['form_edit_interlanguage'], $pv['rawTitle'], $PF['edit'], $PF['edit_interlanguage_tooltip']) . '</span>'));
		}
		Lua::insert($pieces, $this->renderArgumentSchemaJSONLD($args, $pv, $lang, $cache, $PF, $maps));
		return Lua::concat($pieces, '');
	}

	protected function hasSMW() { return $this->context->smwAvailable(); }
	protected function wkSeoHas() { return true; }
	protected function vset($name, $value) { $this->context->variableSet($name, Lua::str($value ?? '')); }
	protected function vget($name) { return $this->context->variableGet($name); }
	protected function titleNewCached($text) { return $this->context->titleInfo($text); }
	protected function safeJsonEncode($data) { return $this->context->jsonEncode($data); }
	protected function seoKeyPart($text) { return md5(Lua::str($text ?? '')); }
	protected function absUrlFromTitle($title, $query) { return $this->context->fullUrl($title, $query) ?? $this->context->localUrl($title, $query) ?? ''; }
	protected function smwAskSafe($query) {
		if (!$this->hasSMW()) { return null; }
		$key = $this->smwKeyFromArray($query);
		$hit = $this->cacheGet($this->SMW_ASK_CACHE, $key);
		if ($hit !== null) { return $hit === false ? null : $hit; }
		$result = $this->context->smwAsk($query);
		$this->cachePut($this->SMW_ASK_CACHE, $key, $result ?? false);
		return $result;
	}
	protected function getArgs($frame = null) { return $this->context->arguments(); }
	protected function detectLang($args = null) { return $this->context->language($args); }
	protected function L($lang = null, $section = null, $key = null, ...$rest) { return $this->context->message('Argument.' . $section, $lang, $key, ...$rest); }
	protected function computePageVars() { return $this->context->pageVars(); }
	protected function wkDbKey($page = null) { return $this->context->dbKey($page); }
	protected function wkSeoSet($data = null) { $this->context->seoSet($data); }
	protected function smwSetSafe($data = null) { $this->context->smwSet($data); }
}
