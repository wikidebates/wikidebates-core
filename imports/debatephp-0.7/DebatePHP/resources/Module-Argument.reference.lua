--	Module:Argument (i18n multi-lang) — Optimisé / Purifié
--	Objectif : rendu du modèle « Argument » (MediaWiki 1.43) fidèle au rendu existant

local p = {}

----------------------------------------------------------------------
--	Accès global au frame + VariablesLua (accélération sans changer le rendu)
----------------------------------------------------------------------

local mw				= mw
local F					= mw.getCurrentFrame()
local V					= (mw.ext and mw.ext.VariablesLua) or nil
local WD_I18N			= require('Module:WD/I18N')

--	Locaux (micro-optis)
local tostring			= tostring
local type				= type
local tonumber			= tonumber
local ipairs			= ipairs
local pairs				= pairs
local table_concat		= table.concat
local table_insert		= table.insert
local table_remove		= table.remove
local table_sort		= table.sort
local t_trim			= mw.text.trim
local t_gsplit			= mw.text.gsplit
local t_nowiki			= mw.text.nowiki
local t_jsonEncode		= mw.text.jsonEncode
local t_jsonDecode		= mw.text.jsonDecode
local html_create		= mw.html.create
local mw_title_new		= mw.title.new
local mw_uri_encode		= mw.uri.encode
local mw_uri_decode		= mw.uri.decode
local mw_uri_fullUrl	= mw.uri.fullUrl
local uri_localUrl		= mw.uri.localUrl
local mw_util			= mw.util
local mw_util_getUrl	= (mw_util and mw_util.getUrl) or nil
local mw_hash			= (mw.hash and type(mw.hash.hashValue) == "function") and mw.hash or nil
local string_format		= string.format
local gsub				= string.gsub
local ITEM_SEP			= "⟭"
local FIELD_SEP			= "⟬"
local QUOTE_ITEM_SEP	= "⟭⟭⟭"
local QUOTE_FIELD_SEP	= "⟬⟬⟬"

----------------------------------------------------------------------
--	Anchor intégré (remplace Module:Anchor)
----------------------------------------------------------------------

local function anchorId( s )
	s = tostring( s or "" )

	--	Normalisation des apostrophes HTML éventuelles
	s = gsub( s, "&#039;", "'" )
	s = gsub( s, "&#39;", "'" )
	s = gsub( s, "&apos;", "'" )

	--	Nettoyage minimal
	s = gsub( s, "%s+", " " )
	s = t_trim( s )

	--	Espaces → underscore
	s = gsub( s, " ", "_" )

	--	Suppression caractères cassants (HTML / wiki)
	s = gsub( s, '[%[%]{}|#<>"]', "" )

	return s
end

----------------------------------------------------------------------
--	wrappers VariablesLua (no-op si extension absente)
----------------------------------------------------------------------

local function vset(name, val)
	if V and type(V.vardefine) == "function" then
		pcall(V.vardefine, name, val ~= nil and tostring(val) or "")
	end
end

local function vget(name)
	if V and type(V.var) == "function" then
		local ok, res = pcall(V.var, name)
		if ok then return res end
	end
	return nil
end

local function detectLang(args)
	return WD_I18N.getLangFromArgs(args or {})
end

----------------------------------------------------------------------
--	I18N : cache local (par rendu)
----------------------------------------------------------------------

local I18N_DOMAIN = {}
local I18N_MSG = {}

local function L(lang, section, key, ...)
	lang = tostring(lang or "fr")
	section = tostring(section or "")
	key = tostring(key or "")

	local domain = I18N_DOMAIN[section]
	if not domain then
		domain = "Argument." .. section
		I18N_DOMAIN[section] = domain
	end

	if select("#", ...) == 0 then
		local k = lang .. "\n" .. domain .. "\n" .. key
		local hit = I18N_MSG[k]
		if hit ~= nil then
			return hit
		end
		local text = WD_I18N.msg(domain, lang, key)
		I18N_MSG[k] = text
		return text
	end

	return WD_I18N.msg(domain, lang, key, ...)
end

----------------------------------------------------------------------
--	Caches (bornage mémoire) — Patch #6
----------------------------------------------------------------------

local function cacheMake(max)
	return { data = {}, order = {}, max = max or 200 }
end

local function cacheGet(C, k)
	return C.data[k]
end

local function cachePut(C, k, v)
	if C.data[k] ~= nil then
		C.data[k] = v
		return
	end
	C.data[k] = v
	table_insert(C.order, k)
	if #C.order > (C.max or 200) then
		local old = table_remove(C.order, 1)
		if old ~= nil then
			C.data[old] = nil
		end
	end
end

--	Mémoïsation expandTemplate (bandeaux, schemas, etc.)
local EXPAND_CACHE = cacheMake(250)

----------------------------------------------------------------------
--	Helpers
----------------------------------------------------------------------

----------------------------------------------------------------------
--	Tooltip normalize (apostrophes / guillemets)
----------------------------------------------------------------------

local function normalizeTooltipText(s)
	s = tostring(s or "")
	if s == "" then return "" end
	s = s:gsub("'", "’")
	s = s:gsub('"', "“")
	return s
end

local canonicalTitle

local function fastParamsKey(params)
	if type(params) ~= "table" then
		return ""
	end

	local n = 0
	for _ in pairs(params) do
		n = n + 1
		if n > 2 then
			return nil
		end
	end

	if n == 1 and params[1] ~= nil then
		return "1=" .. tostring(params[1])
	end

	if n == 2 then
		local k1, k2 = nil, nil
		for k in pairs(params) do
			if not k1 then
				k1 = k
			else
				k2 = k
			end
		end

		local a, b = tostring(k1), tostring(k2)
		if a > b then
			k1, k2 = k2, k1
		end

		return tostring(k1) .. "=" .. tostring(params[k1]) .. "&" .. tostring(k2) .. "=" .. tostring(params[k2])
	end

	return nil
end

local function safeJsonEncode(obj)
	local ok, s = pcall(t_jsonEncode, obj)
	if ok and type(s) == "string" then
		return s
	end
	return tostring(obj or "")
end

local function expandCached(frame, title, params)
	local pk = fastParamsKey(params)
	local k
	if pk then
		k = tostring(title or "") .. "\n" .. pk
	else
		k = tostring(title or "") .. "\n" .. safeJsonEncode(params or {})
	end

	local hit = cacheGet(EXPAND_CACHE, k)
	if hit ~= nil then
		return hit
	end

	local ok, res = pcall(function()
		return frame:expandTemplate{ title = title, args = params or {} }
	end)
	local out = ok and (res or "") or ""

	cachePut(EXPAND_CACHE, k, out)
	return out
end

local function hasSMW()
	return type(mw.smw) == "table" and type(mw.smw.set) == "function"
end

local function escapeAttr(s)
	s = tostring(s or "")
	return s
		:gsub("[\r\n\t]", " ")
		:gsub("&", "&amp;")
		:gsub("<", "&lt;")
		:gsub(">", "&gt;")
		:gsub('"', "&quot;")
end

local function getArgs(frame)
	local A = {}

	local function addArgs(src)
		if not src then return end
		for k, v in pairs(src) do
			if type(v) == "string" and v ~= "" then
				A[k] = v
			end
		end
	end

	local parent = frame:getParent()
	if parent and parent.args then
		addArgs(parent.args)
	end
	if frame and frame.args then
		addArgs(frame.args)
	end

	return A
end

local function splitCSV(s, sep)
	if not s or s == "" then return {} end
	sep = sep or ","

	if not s:find(sep, 1, true) then
		local one = t_trim(s)
		if one ~= "" then
			return { one }
		end
		return {}
	end

	local out = {}
	for part in t_gsplit(s, sep, true) do
		part = t_trim(part)
		if part ~= "" then
			table_insert(out, part)
		end
	end
	return out
end

--	Patch #1 : split littéral multi-caractères (ex: "⟭")
local function splitByLiteral(s, sep, maxParts)
	s = tostring(s or "")
	sep = tostring(sep or "")
	if s == "" or sep == "" then
		return { s }
	end

	local out = {}
	local i = 1
	local n = 0
	local seplen = #sep

	while true do
		local j = s:find(sep, i, true)
		if not j then
			n = n + 1
			out[n] = s:sub(i)
			break
		end

		n = n + 1
		out[n] = s:sub(i, j - 1)
		i = j + seplen

		if maxParts and n >= maxParts then
			n = n + 1
			out[n] = s:sub(i)
			break
		end
	end

	return out
end

local function wkSplit2( s, sep )
	s = tostring( s or "" )
	sep = tostring( sep or "" )

	if sep == "" then
		return s, ""
	end

	local i = s:find( sep, 1, true )
	if not i then
		return s, ""
	end

	return s:sub( 1, i - 1 ), s:sub( i + #sep )
end

local function wkExtractItemsFromSerializedData( raw )
	raw = tostring( raw or "" )
	raw = t_trim( raw )

	if raw == "" then
		return {}
	end

	--	Heuristique: si aucun séparateur, pas notre format
	if not raw:find( ITEM_SEP, 1, true ) then
		return {}
	end

	local items = {}
	local blocks = splitByLiteral( raw, ITEM_SEP )

	for _, block in ipairs( blocks ) do
		block = t_trim( block )

		if block ~= "" then
			local page, rest = wkSplit2( block, FIELD_SEP )
			local title, warnings = wkSplit2( rest, FIELD_SEP )

			page = t_trim( page )

			if page ~= "" then
				title = t_trim( title )
				warnings = t_trim( warnings )

				if title == "" then
					title = page
				end

				table_insert( items, {
					page = page,
					title = title,
					warnings = warnings
				} )
			end
		end
	end

	return items
end

local TITLE_CACHE = cacheMake(600)

local function titleNewCached(s)
	s = tostring(s or "")
	local hit = cacheGet(TITLE_CACHE, s)
	if hit ~= nil then
		return (hit ~= false) and hit or nil
	end
	local t = mw_title_new(s)
	cachePut(TITLE_CACHE, s, t or false)
	return t
end

local function wkDbKey(page)
	page = tostring(page or "")
	if page == "" then
		return ""
	end

	if not page:find(":", 1, true) and not page:find("[%[%]{}|#<>]") then
		return page:gsub(" ", "_")
	end

	local t = titleNewCached(page)
	if not t then
		return page:gsub("%s", "_")
	end
	return t.prefixedText:gsub(" ", "_")
end

local function wkEncodePathSegment(s)
	s = tostring(s or "")
	s = s:gsub("%%", "%%25")
	s = s:gsub("%?", "%%3F")
	s = s:gsub("#", "%%23")
	s = s:gsub("/", "%%2F")
	return s
end

local function wkAddDataPath(formName, pageTitle)
	local form = wkEncodePathSegment(wkDbKey(formName))
	local page = wkEncodePathSegment(wkDbKey(pageTitle))
	return "Special:AddData/" .. form .. "/" .. page
end

local function addDataLink( formName, pageTitle, linktext, tooltip, opts )
	formName = tostring( formName or "" )
	pageTitle = tostring( pageTitle or "" )
	if formName == "" or pageTitle == "" then
		return ""
	end

	opts = ( type( opts ) == "table" ) and opts or {}

	local target = wkAddDataPath( formName, pageTitle )

	local lt = linktext
	if lt == nil or lt == "" then
		lt = "&nbsp;"
	end

	local tt = tostring( tooltip or "" )

	local classes = "wk-adddata-link navigation-not-searchable noprint"
	if opts.editButton == true then
		classes = classes .. " bouton-modifier"
	end

	if tt ~= "" then
		return string_format(
			'<span class="%s" data-wk-tooltip="%s">[[%s|%s]]</span>',
			classes,
			escapeAttr( tt ),
			target,
			lt
		)
	end

	return string_format(
		'<span class="%s">[[%s|%s]]</span>',
		classes,
		target,
		lt
	)
end

local function addDataEditLink( formName, pageTitle, linktext, tooltip )
	return addDataLink( formName, pageTitle, linktext, tooltip, { editButton = true } )
end

local function wkRunQueryPath(formName)
	local form = wkEncodePathSegment(wkDbKey(formName))
	return "Special:RunQuery/" .. form
end

local function wkRunQueryHtmlTagButton( formName, label, tooltip, query )
	formName	= tostring( formName or "" )
	label		= tostring( label or "" )
	query		= ( type( query ) == "table" ) and query or {}
	tooltip		= tostring( tooltip or "" )

	tooltip = normalizeTooltipText( tooltip )

	if formName == "" then
		return ""
	end

	local title	= wkRunQueryPath( formName )
	local href	= tostring( uri_localUrl( title, query ) )

	local content = "＋ " .. label

	return F:preprocess(
		'<htmltag tagname="a"'
		.. ' href="javascript:void(0)"'
		.. ' data-href="' .. escapeAttr( href ) .. '"'
		.. ' class="wk-btn__a wk-js-nav"'
		.. ( tooltip ~= "" and ( ' title="' .. escapeAttr( tooltip ) .. '"' ) or "" )
		.. '>'
		.. content
		.. '</htmltag>'
	)
end

local function absUrlFromTitle(title, q)
	local ok, url = pcall(mw_uri_fullUrl, title, q)
	if ok and url then
		return tostring(url)
	end

	local server = ""
	if mw.site and mw.site.server then
		server = tostring(mw.site.server or "")
	end

	if mw_util_getUrl then
		return server .. tostring(mw_util_getUrl(title, q))
	end

	return server .. tostring(uri_localUrl(title, q))
end

local function runQueryLink(opts)
	opts = opts or {}

	local formName	= tostring(opts.form or "")
	local fieldName	= tostring(opts.field or "")
	local value		= tostring(opts.value or "")

	if formName == "" or fieldName == "" or value == "" then
		return ""
	end

	local title	= tostring(opts._title or "")
	if title == "" then
		title = wkRunQueryPath(formName)
	end

	local q		= {}
	local qkey	= tostring(opts._qkey or "")
	if qkey == "" then
		qkey = formName .. "[" .. fieldName .. "]"
	end
	q[qkey] = value

	local extra = opts.extra
	if type(extra) == "table" then
		for k, v in pairs(extra) do
			local kk = tostring(k or "")
			if kk ~= "" and v ~= nil then
				local vv = tostring(v)
				if vv ~= "" then
					if kk:find("%[", 1, true) then
						q[kk] = vv
					else
						q[formName .. "[" .. kk .. "]"] = vv
					end
				end
			end
		end
	end

	q["_run"] = "1"

	local url	= absUrlFromTitle(title, q)
	local label	= tostring(opts.label or value)
	local tt		= normalizeTooltipText(tostring(opts.tooltip or ""))

	local link = "[" .. url .. " " .. label .. "]"

	if tt ~= "" then
		return string.format(
			'<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>',
			escapeAttr(tt),
			link
		)
	end

	return string.format('<span class="wk-adddata-link">%s</span>', link)
end

local function listBreaker()
	return "\n\n<!--__WD_LIST_BREAK__-->"
end

----------------------------------------------------------------------
--	SMW : props refs (i18n via PF) — comme Debate
----------------------------------------------------------------------

local function pfProp( PF, k, fallback )
	local v = PF and PF[ k ] or ""
	v = t_trim( tostring( v or "" ) )
	if v ~= "" then
		return v
	end
	return fallback or ""
end

local function smwPropRef( PF, key )
	--	Utilise PF.prop_* (i18n), fallback sur tes noms existants
	if key == "authors" then
		return pfProp( PF, "prop_author" )
	end
	if key == "article_name" then
		return pfProp( PF, "prop_article_name" )
	end
	if key == "work_name" then
		return pfProp( PF, "prop_work_name" )
	end
	if key == "publishing_house" then
		return pfProp( PF, "prop_publishing_house" )
	end
	if key == "place_of_publication" then
		return pfProp( PF, "prop_place_of_publication" )
	end
	return ""
end

----------------------------------------------------------------------
--	SMW batch : queue + flush unique
----------------------------------------------------------------------

local function smwQueueAdd(pending, key, val, multi)
	if not pending or not key or key == "" or val == nil then
		return
	end

	if type(val) == "string" then
		val = t_trim(val)
		if val == "" then
			return
		end
	end

	local cur = pending[key]

	if multi then
		if cur == nil then
			pending[key] = (type(val) == "table") and val or { val }
			return
		end

		if type(cur) ~= "table" then
			cur = { cur }
			pending[key] = cur
		end

		if type(val) == "table" then
			for _, x in ipairs(val) do
				if x ~= nil then
					cur[#cur + 1] = x
				end
			end
		else
			cur[#cur + 1] = val
		end

		return
	end

	pending[key] = val
end

----------------------------------------------------------------------
--	Minibandeaux (summary-warnings) — rendu natif (sans modèle)
----------------------------------------------------------------------

local JUSTIFICATION_WARNING_BANNER_KEYS = {
	"justifications_to_reorganize",
}

local OBJECTION_WARNING_BANNER_KEYS = {
	"objections_to_reorganize",
}

local QUOTE_WARNING_BANNER_KEYS = {
	"quote_too_short",
	"quote_too_long",
	"quote_ref_incomplete",
	"quote_translated",
}

local REFERENCE_WARNING_BANNER_KEYS = {
	"reference_quality_insufficient",
	"reference_incomplete",
	"dead_link",
}

local SUMMARY_WARNING_BANNER_KEYS = {
	"summary_disadvantageous",
	"summary_to_be_written",
	"summary_unclear",
	"summary_too_long",
	"summary_to_separate",
	"summary_style_to_review",
}

local SUMMARY_WARNING_ALIASES = nil

local DISPLAYED_TITLE_WARNING_BANNER_KEYS = {
	"displayed_title_too_long",
	"displayed_title_unclear",
	"displayed_title_disadvantageous",
}

local function subMsg( lang, key, ... )
	return L( lang, "subsection_banners", key, ... ) or ""
end

local function buildSummaryAliases( lang )
	local a = {}

	for _, key in ipairs( SUMMARY_WARNING_BANNER_KEYS ) do
		local alias = subMsg( lang, key .. "_alias" )
		alias = t_trim( tostring( alias or "" ) )

		if alias ~= "" then
			for _, one in ipairs( splitCSV( alias, "," ) ) do
				one = t_trim( tostring( one or "" ) )
				if one ~= "" then
					a[ one ] = key
				end
			end
		end
	end

	return a
end

local function buildSummaryLabelLookup( lang, keys )
	local lookup = {}
	for _, key in ipairs( keys or {} ) do
		local lab = t_trim( subMsg( lang, key .. "_label" ) )
		if lab ~= "" then
			lookup[ lab ] = key
		end
	end
	return lookup
end

-- Résolveur générique : label -> key, alias -> key, ou key directe si autorisée
local function resolveKeyFromLookup(raw, lookup, allowedKeys, aliases)
	raw = t_trim(tostring(raw or ""))
	if raw == "" then
		return ""
	end

	-- 1) Match par label i18n
	local k = (lookup and lookup[raw]) or ""
	if k ~= "" then
		return k
	end

	-- 2) Match par alias (optionnel)
	if aliases and aliases[raw] then
		return aliases[raw]
	end

	-- 3) Match direct si l'utilisateur a mis la key brute
	if allowedKeys then
		for _, kk in ipairs(allowedKeys) do
			if raw == kk then
				return kk
			end
		end
	end

	return ""
end

local function renderSubsectionBanner( pv, lang, key, opts, cats )
	key = tostring( key or "" )
	if key == "" then
		return ""
	end

	opts = opts or {}

	local isReorg		= opts.reorg == true
	local icon			= tostring( opts.icon or "" )
	local linkHtml		= ""
	local title			= ""
	local text			= ""

	--	Lien de réorganisation (optionnel) — AddData (même source que le H2)
	if isReorg then
		local formName	= tostring( opts.form or "" )
		local tooltip	= tostring( opts.tooltip or "" )
		local linkLabel	= tostring( opts.link_label or "" )

		if linkLabel == "" then
			linkLabel = L( lang, "text", "reorganize_link_label" )
		end

		if formName ~= "" then
			linkHtml = addDataLink( formName, pv.rawTitle, linkLabel, tooltip )
		end
	end

	--	Titre/texte (mêmes messages i18n que tes fonctions actuelles)
	if isReorg and linkHtml ~= "" then
		title = subMsg( lang, key .. "_title" )
		title = t_trim( tostring( title or "" ) )
		if title ~= "" then
			title = title .. "&nbsp;" .. linkHtml
		else
			title = linkHtml
		end
	else
		title = subMsg( lang, key .. "_title", pv.rawTitle )
	end

	text = subMsg( lang, key .. "_text", pv.rawTitle )

	if title == "" and text == "" then
		return ""
	end

	local html =
		'<div class="bandeau-avertissement wk-icon-before wk-icon-sensible">'
		.. title
		.. '<span class="smw-highlighter smwttinline wk-icon-before wk-icon-help mw-no-invert" data-state="inline">'
		.. '<div class="smwttcontent">' .. text .. '</div>'
		.. '</span>'
		.. '</div>'

	local cat = subMsg( lang, key .. "_category" )
	if cats and cat and cat ~= "" then
		table_insert( cats, "[[Category:" .. cat .. "]]" )
	end

	return html
end

local function wkParseBiblioMarkers(raw)
	raw = tostring(raw or "")
	raw = t_trim(raw)
	if raw == "" then
		return {}
	end

	if not raw:find(FIELD_SEP, 1, true) and not raw:find(ITEM_SEP, 1, true) then
		return {}
	end

	local items = {}
	local blocks = splitByLiteral(raw, ITEM_SEP)

	for _, block in ipairs(blocks) do
		block = t_trim(block)
		if block ~= "" then
			local f = {}
			local parts = splitByLiteral(block, FIELD_SEP)

			for _, part in ipairs(parts) do
				f[#f + 1] = t_trim(tostring(part or ""))
			end

			for i = #f + 1, 12 do
				f[i] = ""
			end

			items[#items + 1] = {
				authors		= f[1],
				article		= f[2],
				work		= f[3],
				volume		= f[4],
				number		= f[5],
				location	= f[6],
				page		= f[7],
				publisher	= f[8],
				place		= f[9],
				date		= f[10],
				link		= f[11],
				warnings	= f[12]
			}
		end
	end

	return items
end

local function wkParseWeblioMarkers(raw)
	raw = tostring(raw or "")
	raw = t_trim(raw)
	if raw == "" then
		return {}
	end

	if not raw:find(FIELD_SEP, 1, true) and not raw:find(ITEM_SEP, 1, true) then
		return {}
	end

	local items = {}
	local blocks = splitByLiteral(raw, ITEM_SEP)

	for _, block in ipairs(blocks) do
		block = t_trim(block)
		if block ~= "" then
			local f = {}
			local parts = splitByLiteral(block, FIELD_SEP)

			for _, part in ipairs(parts) do
				f[#f + 1] = t_trim(tostring(part or ""))
			end

			for i = #f + 1, 6 do
				f[i] = ""
			end

			items[#items + 1] = {
				page		= f[1],
				site		= f[2],
				link		= f[3],
				authors		= f[4],
				date		= f[5],
				warnings	= f[6]
			}
		end
	end

	return items
end

local function wkParseVideoMarkers(raw)
	raw = tostring(raw or "")
	raw = t_trim(raw)
	if raw == "" then
		return {}
	end

	if not raw:find(FIELD_SEP, 1, true) and not raw:find(ITEM_SEP, 1, true) then
		return {}
	end

	local items = {}
	local blocks = splitByLiteral(raw, ITEM_SEP)

	for _, block in ipairs(blocks) do
		block = t_trim(block)
		if block ~= "" then
			local f = {}
			local parts = splitByLiteral(block, FIELD_SEP)

			for _, part in ipairs(parts) do
				f[#f + 1] = t_trim(tostring(part or ""))
			end

			for i = #f + 1, 4 do
				f[i] = ""
			end

			items[#items + 1] = {
				title		= f[1],
				link		= f[2],
				authors		= f[3],
				warnings	= f[4]
			}
		end
	end

	return items
end

local function renderReferenceItemBanners(itemWarnings, pv, lang, cats, out)
	local warnsRef = t_trim(tostring(itemWarnings or ""))
	if warnsRef == "" then
		return
	end

	local lookup = buildSummaryLabelLookup(lang, REFERENCE_WARNING_BANNER_KEYS)

	for _, x in ipairs(splitCSV(warnsRef, ",")) do
		local key = resolveKeyFromLookup(x, lookup, REFERENCE_WARNING_BANNER_KEYS)
		if key ~= "" then
			table_insert(out, renderSubsectionBanner(pv, lang, key, nil, cats))
		end
	end
end

local function wkRenderOneBiblioLi( item, pv, lang, cats, PF, smwPending, args )
	local out = {}

	local authors = t_trim( item.authors or "" )
	if authors == "" then
		authors = PF.unknown_author
	end
	out[ #out + 1 ] = authors

	do
		local k = smwPending and smwPropRef( PF, "authors" ) or ""
		if k ~= "" then
			smwQueueAdd( smwPending, k, authors, true )
		end
	end

	local article = t_trim( item.article or "" )
	local work = t_trim( item.work or "" )
	local link = t_trim( item.link or "" )

	do
		if smwPending then
			local ka = smwPropRef( PF, "article_name" )
			if ka ~= "" and article ~= "" then
				smwQueueAdd( smwPending, ka, article, true )
			end

			local kw = smwPropRef( PF, "work_name" )
			if kw ~= "" and work ~= "" then
				smwQueueAdd( smwPending, kw, work, true )
			end
		end
	end

	if article ~= "" then
		if link ~= "" then
			out[ #out + 1 ] = ", « [" .. link .. " " .. article .. "] »"
		else
			out[ #out + 1 ] = ", « " .. article .. " »"
		end
	end

	if work ~= "" then
		if article ~= "" then
			out[ #out + 1 ] = ", ''" .. work .. "''"
		else
			if link ~= "" then
				out[ #out + 1 ] = ", ''[" .. link .. " " .. work .. "]''"
			else
				out[ #out + 1 ] = ", ''" .. work .. "''"
			end
		end
	end

	local volume = t_trim( item.volume or "" )
	if volume ~= "" then
		out[ #out + 1 ] = ", " .. PF.volume_label .. "&#160;" .. volume
	end

	local number = t_trim( item.number or "" )
	if number ~= "" then
		out[ #out + 1 ] = ", " .. PF.issue_label .. "&#160;" .. number
	end

	local location = t_trim( item.location or "" )
	if location ~= "" then
		out[ #out + 1 ] = ", " .. location
	end

	local page = t_trim( item.page or "" )
	if page ~= "" then
		out[ #out + 1 ] = ", p." .. page
	end

	local publisher = t_trim( item.publisher or "" )
	if publisher ~= "" then
		out[ #out + 1 ] = ", " .. publisher
		do
			local kp = smwPending and smwPropRef( PF, "publishing_house" ) or ""
			if kp ~= "" then
				smwQueueAdd( smwPending, kp, publisher, true )
			end
		end
	end

	local place = t_trim( item.place or "" )
	if place ~= "" then
		out[ #out + 1 ] = ", " .. place
		do
			local kp = smwPending and smwPropRef( PF, "place_of_publication" ) or ""
			if kp ~= "" then
				smwQueueAdd( smwPending, kp, place, true )
			end
		end
	end

	local date = t_trim( item.date or "" )
	if date ~= "" then
		out[ #out + 1 ] = ", " .. date
	end

	renderReferenceItemBanners(item.warnings, pv, lang, cats, out)

	return "<li>" .. table_concat( out ) .. "</li>"
end

local function wkRenderOneWeblioLi( item, pv, lang, cats, PF, smwPending, args )
	local out = {}

	local page		= t_trim( item.page or "" )
	local site		= t_trim( item.site or "" )
	local link		= t_trim( item.link or "" )
	local date		= t_trim( item.date or "" )
	local authors	= t_trim( item.authors or "" )

	do
		local k = smwPending and smwPropRef( PF, "authors" ) or ""
		if k ~= "" and authors ~= "" then
			smwQueueAdd( smwPending, k, authors, true )
		end
	end

	if authors ~= "" then
		out[ #out + 1 ] = authors
	end

	do
		local label = ""
		if page ~= "" then
			label = page
		elseif site ~= "" then
			label = site
		end

		if link ~= "" and label ~= "" then
			out[ #out + 1 ] = ( authors ~= "" and ", " or "" ) .. "[" .. link .. " " .. label .. "]"
		else
			out[ #out + 1 ] = ( authors ~= "" and ", " or "" ) .. ( ( page ~= "" and page ) or site )
		end
	end

	if site ~= "" and page ~= "" then
		out[ #out + 1 ] = ", ''" .. site .. "''"
	end
	if date ~= "" then
		out[ #out + 1 ] = ", " .. date
	end

	renderReferenceItemBanners(item.warnings, pv, lang, cats, out)

	return "<li>" .. table_concat( out ) .. "</li>"
end

local function wkRenderOneVideoLi( item, pv, lang, cats, PF, smwPending, args )
	local out = {}

	local title = t_trim( item.title or "" )
	local link = t_trim( item.link or "" )
	local authors = t_trim( item.authors or "" )

	do
		local k = smwPending and smwPropRef( PF, "authors" ) or ""
		if k ~= "" and authors ~= "" then
			smwQueueAdd( smwPending, k, authors, true )
		end
	end

	if authors ~= "" then
		out[ #out + 1 ] = authors
	end

	if link ~= "" and title ~= "" then
		out[ #out + 1 ] = ( authors ~= "" and ", " or "" ) .. "[" .. link .. " " .. title .. "]"
	elseif title ~= "" then
		out[ #out + 1 ] = ( authors ~= "" and ", " or "" ) .. title
	end

	renderReferenceItemBanners(item.warnings, pv, lang, cats, out)

	return "<li>" .. table_concat( out ) .. "</li>"
end

local function wkRenderReferencesFromRaw( rawB, rawW, rawV, pv, lang, cats, PF, smwPending, args )
	local b, w, v = {}, {}, {}

	for _, it in ipairs( wkParseBiblioMarkers( rawB ) ) do
		b[ #b + 1 ] = wkRenderOneBiblioLi( it, pv, lang, cats, PF, smwPending, args )
	end
	for _, it in ipairs( wkParseWeblioMarkers( rawW ) ) do
		w[ #w + 1 ] = wkRenderOneWeblioLi( it, pv, lang, cats, PF, smwPending, args )
	end
	for _, it in ipairs( wkParseVideoMarkers( rawV ) ) do
		v[ #v + 1 ] = wkRenderOneVideoLi( it, pv, lang, cats, PF, smwPending, args )
	end

	if #b == 0 and #w == 0 and #v == 0 then
		return ""
	end

	local out = table_concat( {
		table_concat( b, "\n" ),
		table_concat( w, "\n" ),
		table_concat( v, "\n" )
	}, "\n" )

	out = t_trim( out )
	return out
end

----------------------------------------------------------------------
--	htmltag : générateur (mode qui marche)
----------------------------------------------------------------------

local function tagHtmlTag(tagName, content, attrs)
	local src = "{{#tag:htmltag|" .. (content or "") .. "|tagname=" .. tostring(tagName)

	if attrs then
		local keys = {}
		local n = 0
		for k in pairs(attrs) do
			n = n + 1
			keys[n] = k
		end

		if n > 0 then
			table_sort(keys)
			for i = 1, n do
				local k = keys[i]
				src = src .. "|" .. tostring(k) .. "=" .. tostring(attrs[k])
			end
		end
	end

	src = src .. "}}"
	return F:preprocess(src)
end

----------------------------------------------------------------------
--	SMW : cache fin (par rendu) — bornés (Patch #6)
----------------------------------------------------------------------

local SMW_ASK_CACHE = cacheMake(250)
local SMW_TITLES_CACHE = cacheMake(250)

--	Patch #2 : clé cache non-collidable (length-prefix)
local function smwKeyFromArray(q)
	if type(q) ~= "table" then
		local s = tostring(q or "")
		return tostring(#s) .. ":" .. s
	end

	local idx = {}
	local n = 0
	for k in pairs(q) do
		if type(k) == "number" then
			n = n + 1
			idx[n] = k
		end
	end

	if n == 0 then
		return ""
	end

	table_sort(idx)

	local parts = {}
	for i = 1, n do
		local v = tostring(q[idx[i]] or "")
		parts[i] = tostring(#v) .. ":" .. v
	end

	return table_concat(parts, "\n")
end

local function smwSetSafe(props)
	if not mw.smw or type(mw.smw.set) ~= "function" then
		return
	end
	pcall(mw.smw.set, props)
end

--	Cache négatif (sentinelle false)
local function smwAskSafe(q)
	if not hasSMW() or type(mw.smw.ask) ~= "function" then return nil end

	local k = smwKeyFromArray(q)
	local hit = cacheGet(SMW_ASK_CACHE, k)
	if hit ~= nil then
		return (hit ~= false) and hit or nil
	end

	local ok, res = pcall(function() return mw.smw.ask(q) end)
	if ok then
		cachePut(SMW_ASK_CACHE, k, res)
		return res
	end

	cachePut(SMW_ASK_CACHE, k, false)
	return nil
end

----------------------------------------------------------------------
--	Bloc d’en-tête / variables de page (cache key-safe)
----------------------------------------------------------------------

local function computePageVars()
	local title = mw.title.getCurrentTitle()

	local rawTitle = title.prefixedText
	local encoded = mw_uri_encode(rawTitle, "WIKI")

	local pageUrl = ""
	do
		local ok, u = pcall(mw_uri_fullUrl, title.prefixedText)
		pageUrl = (ok and u) and tostring(u) or ""
	end

	local pageId = tostring(title.id or "")

	return {
		title = title,
		rawTitle = rawTitle,
		encoded = encoded,
		pageUrl = pageUrl,
		pageId = pageId
	}
end

local function getPageCreation(pv, args)
	local v = args and args["creation-date"]
	v = (type(v) == "string") and t_trim(v) or ""

	if v ~= "" then
		return v .. "T00:00:00Z"
	end

	return nil
end

----------------------------------------------------------------------
--	Cache SMW (parents) + cartes pré-rendues
----------------------------------------------------------------------

local function makeSMWCache()
	return {
		debatsPour = nil,
		debatsContre = nil,
		justifs = nil,
		objs = nil,

		debatsTousFinal = nil,

		debatCardsTous = nil,
		debatCardsPour = nil,
		debatCardsContre = nil,
		argCardsJustifs = nil,
		argCardsObjs = nil,

		breadcrumbChainsFinal = nil,
		parentDebatesFinal = nil
	}
end

----------------------------------------------------------------------
--	Titre : avertissements + catégories (legacy)
----------------------------------------------------------------------

local function renderTitleWarnings(pv, cats, lang)
	local title = pv.rawTitle or ""
	if title == "" then return "" end

	local out = {}

	local n = 0
	for w in t_gsplit(title, "%s+") do
		w = t_trim(w)
		if w ~= "" then
			n = n + 1
			if n >= 3 then break end
		end
	end

	if n > 0 and n < 3 then
		table_insert(out,
			'<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\''
			.. L(lang, "text", "title_too_short_msg")
			.. ' [[Special:MovePage/' .. pv.encoded .. '|' .. L(lang, "text", "rename_page_label") .. ']]'
			.. ' ' .. L(lang, "text", "if_needed_msg")
			.. '\'\'\'</div>'
		)
		table_insert(cats, "[[Category:" .. L(lang, "categories", "title_very_short") .. "]]")
	end

	if title:sub(-1) == "." and title:sub(-4) ~= "etc." then
		table_insert(out,
			'<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\''
			.. L(lang, "text", "title_ends_with_dot_msg")
			.. ' [[Special:MovePage/' .. pv.encoded .. '|' .. L(lang, "text", "rename_page_label") .. ']]'
			.. '\'\'\'</div>'
		)
		table_insert(cats, "[[Category:" .. L(lang, "categories", "title_ends_with_dot") .. "]]")
	end

	if lang == "fr" then
		local prefix3 = title:sub(1, 3)
		local prefix4 = title:sub(1, 4)
		local prefix5 = title:sub(1, 5)
		local prefix6 = title:sub(1, 6)
		local prefix10 = title:sub(1, 10)
		local prefix13 = title:sub(1, 13)

		if prefix3 == "Ça" then
			table_insert(cats, "[[Category:Titres d'arguments commençant par \"Cela\"]]")
		elseif prefix3 == "Sa" then
			table_insert(cats, "[[Category:Titres d'arguments commençant par \"Sa\"]]")
		end

		local map4 = {
			["Ces"] = "Titres d'arguments commençant par \"Ces\"",
			["Cet"] = "Titres d'arguments commençant par \"Cet\"",
			["Ils"] = "Titres d'arguments commençant par \"Ils\"",
			["Ses"] = "Titres d'arguments commençant par \"Ses\"",
			["Son"] = "Titres d'arguments commençant par \"Son\""
		}
		if map4[prefix4] then
			table_insert(cats, "[[Category:" .. map4[prefix4] .. "]]")
		end

		local map5 = {
			["Cela"] = "Titres d'arguments commençant par \"Cela\"",
			["Elle"] = "Titres d'arguments commençant par \"Elle\"",
			["Leur"] = "Titres d'arguments commençant par \"Leur\"",
			["Leurs"] = "Titres d'arguments commençant par \"Leurs\"",
			["Non,"] = "Titres d'arguments commençant par \"Non\"",
			["Nous"] = "Titres d'arguments commençant par \"Nous\"",
			["Oui,"] = "Titres d'arguments commençant par \"Oui\""
		}
		if map5[prefix5] then
			table_insert(cats, "[[Category:" .. map5[prefix5] .. "]]")
		end

		local map6 = {
			["Cette"] = "Titres d'arguments commençant par \"Cette\"",
			["Elles"] = "Titres d'arguments commençant par \"Elles\""
		}
		if map6[prefix6] then
			table_insert(cats, "[[Category:" .. map6[prefix6] .. "]]")
		end

		local map10 = {
			["C'est faux"] = "Titres d'arguments commençant par \"C'est faux\"",
			["Hors-sujet"] = "Titres d'arguments commençant par \"Hors-sujet\""
		}
		if map10[prefix10] then
			table_insert(cats, "[[Category:" .. map10[prefix10] .. "]]")
		end

		if prefix13 == "Au contraire," then
			table_insert(cats, "[[Category:Titres d'arguments commençant par \"Au contraire\"]]")
		end
	end

	return table_concat(out)
end

----------------------------------------------------------------------
--	SMW : helpers (avec cache fin)
----------------------------------------------------------------------

local function uniqList(list)
	local out, seen = {}, {}
	for _, x in ipairs(list or {}) do
		x = t_trim(tostring(x or ""))
		if x ~= "" and not seen[x] then
			seen[x] = true
			table_insert(out, x)
		end
	end
	return out
end

local function smwAskTitles(conditions, limit)
	limit = tonumber(limit) or 200

	local q = {}
	for _, c in ipairs(conditions or {}) do
		table_insert(q, c)
	end
	table_insert(q, "limit=" .. tostring(limit))
	table_insert(q, "link=none")

	local ck = "TITLES\n" .. smwKeyFromArray(q)
	local hit = cacheGet(SMW_TITLES_CACHE, ck)
	if hit ~= nil then
		return hit
	end

	local res = smwAskSafe(q)
	if type(res) ~= "table" then
		cachePut(SMW_TITLES_CACHE, ck, {})
		return {}
	end

	local out, seen = {}, {}
	for _, row in ipairs(res) do
		local ft = row.fulltext or row.page or row[1]
		if type(ft) == "string" and ft ~= "" and not seen[ft] then
			seen[ft] = true
			table_insert(out, ft)
		end
	end

	cachePut(SMW_TITLES_CACHE, ck, out)
	return out
end

----------------------------------------------------------------------
--	(Parents) : ask unifié + robuste + fallback
----------------------------------------------------------------------

local function normTitle(s)
	s = tostring(s or "")
	s = s:gsub("_", " ")
	return t_trim(s)
end

canonicalTitle = function(s)
	s = t_trim(tostring(s or ""))
	if s == "" then return "" end
	s = s:gsub("^:%s*", "")
	s = s:gsub("^%[%[%s*", ""):gsub("%s*%]%]$", "")
	s = (s:match("^([^%]|]+)%|") or s)
	s = t_trim(s)

	local t = titleNewCached(s)
	return t and t.prefixedText or s
end

--	Patch #5 : sécuriser les titres utilisés dans les requêtes SMW
local function smwSafeTitle(s)
	s = canonicalTitle(s)
	if s == "" then
		return ""
	end

	s = s:gsub("#.*$", "")
	s = t_trim(s)

	if s == "" then
		return ""
	end

	if s:find("[%[%]{}|<>]") then
		return ""
	end

	return s
end

local function containsTitle(val, target)
	target = normTitle(target)
	if target == "" or val == nil then
		return false
	end

	local function checkOne(x)
		if x == nil then return false end

		if type(x) == "string" then
			return normTitle(x) == target
		end

		if type(x) == "table" then
			local ft = x.fulltext or x.page or x.name or x.title
			if type(ft) == "string" and normTitle(ft) == target then
				return true
			end
			for _, y in ipairs(x) do
				if checkOne(y) then
					return true
				end
			end
		end

		return false
	end

	return checkOne(val)
end

local function ensureDebateParents(pv, cache, PF)
	if not cache or cache.debatsPour ~= nil then
		return
	end

	local argTitle = smwSafeTitle(pv.rawTitle)

	local propPro = tostring(PF.prop_arg_pro or "")
	local propCon = tostring(PF.prop_arg_con or "")
	if propPro == "" or propCon == "" or argTitle == "" then
		cache.debatsPour = {}
		cache.debatsContre = {}
		return
	end

	local q = {
		"([[" .. propPro .. "::" .. argTitle .. "]] OR [[" .. propCon .. "::" .. argTitle .. "]] )",
		"?" .. propPro .. "=pro",
		"?" .. propCon .. "=con",
		"limit=200",
		"link=none"
	}

	local res = smwAskSafe(q)
	local debatsPour, debatsContre = {}, {}
	local seenP, seenC = {}, {}

	if type(res) == "table" then
		for _, row in ipairs(res) do
			local ft = row.fulltext or row.page or row[1]
			if type(ft) == "string" and ft ~= "" then
				if containsTitle(row.pro, argTitle) and not seenP[ft] then
					seenP[ft] = true
					table_insert(debatsPour, ft)
				end
				if containsTitle(row.con, argTitle) and not seenC[ft] then
					seenC[ft] = true
					table_insert(debatsContre, ft)
				end
			end
		end
	end

	if #debatsPour == 0 and #debatsContre == 0 then
		debatsPour = uniqList(smwAskTitles({ "[[" .. propPro .. "::" .. argTitle .. "]]" }, 200))
		debatsContre = uniqList(smwAskTitles({ "[[" .. propCon .. "::" .. argTitle .. "]]" }, 200))
	end

	cache.debatsPour = debatsPour
	cache.debatsContre = debatsContre
end

local function ensureArgumentParents(pv, cache, PF)
	if not cache or cache.justifs ~= nil then
		return
	end

	local argTitle = smwSafeTitle(pv.rawTitle)

	local propJustif = tostring(PF.prop_justif or "")
	local propObj = tostring(PF.prop_obj or "")
	if propJustif == "" or propObj == "" or argTitle == "" then
		cache.justifs = {}
		cache.objs = {}
		return
	end

	local q = {
		"([[" .. propJustif .. "::" .. argTitle .. "]] OR [[" .. propObj .. "::" .. argTitle .. "]] )",
		"?" .. propJustif .. "=justif",
		"?" .. propObj .. "=obj",
		"limit=200",
		"link=none"
	}

	local res = smwAskSafe(q)
	local justifs, objs = {}, {}
	local seenJ, seenO = {}, {}

	if type(res) == "table" then
		for _, row in ipairs(res) do
			local ft = row.fulltext or row.page or row[1]
			if type(ft) == "string" and ft ~= "" then
				if containsTitle(row.justif, argTitle) and not seenJ[ft] then
					seenJ[ft] = true
					table_insert(justifs, ft)
				end
				if containsTitle(row.obj, argTitle) and not seenO[ft] then
					seenO[ft] = true
					table_insert(objs, ft)
				end
			end
		end
	end

	if #justifs == 0 and #objs == 0 then
		justifs = uniqList(smwAskTitles({ "[[" .. propJustif .. "::" .. argTitle .. "]]" }, 200))
		objs = uniqList(smwAskTitles({ "[[" .. propObj .. "::" .. argTitle .. "]]" }, 200))
	end

	cache.justifs = justifs
	cache.objs = objs
end

----------------------------------------------------------------------
--	Fil d’Ariane : longueur (byte-safe, sans Unicode libs)
----------------------------------------------------------------------

local function breadcrumbLenByteSafe(ch)
	if type(ch) ~= "string" or ch == "" then
		return 0
	end

	local sep = "⟭"
	local seplen = 3
	local n = 0
	local i = 1
	local Ls = #ch

	local function trimAscii(s)
		return (tostring(s or ""):match("^%s*(.-)%s*$") or "")
	end

	while i <= Ls + 1 do
		local j = ch:find(sep, i, true)
		local part
		if j then
			part = ch:sub(i, j - 1)
			i = j + seplen
		else
			part = ch:sub(i)
			i = Ls + 2
		end

		if trimAscii(part) ~= "" then
			n = n + 1
			if n >= 6 then
				return n
			end
		end
	end

	return n
end

----------------------------------------------------------------------
--	Débats parents : calcul final
----------------------------------------------------------------------

local function ensureDebateParentsFinal(pv, cache, PF)
	if not cache or cache.debatsTousFinal ~= nil then
		return
	end

	local final = {}

	ensureDebateParents(pv, cache, PF)
	for _, d in ipairs(cache.debatsPour or {}) do
		table_insert(final, canonicalTitle(d))
	end
	for _, d in ipairs(cache.debatsContre or {}) do
		table_insert(final, canonicalTitle(d))
	end

	if hasSMW() then
		ensureArgumentParents(pv, cache, PF)

		local propBreadcrumb   = tostring(PF.prop_breadcrumb or "")
		local propParentDebate = tostring(PF.prop_parent_debate or "")

		if propBreadcrumb ~= "" and propParentDebate ~= "" then
			local parents = {}
			for _, a in ipairs(cache.justifs or {}) do table_insert(parents, canonicalTitle(a)) end
			for _, a in ipairs(cache.objs or {}) do table_insert(parents, canonicalTitle(a)) end
			parents = uniqList(parents)

			if #parents > 0 then
				local conds = {}
				for _, t in ipairs(parents) do
					local safe = smwSafeTitle(t)
					if safe ~= "" then
						table_insert(conds, "[[" .. safe .. "]]")
					end
				end

				if #conds > 0 then
					local q = {
						"(" .. table_concat(conds, " OR ") .. ")",
						"?" .. propBreadcrumb .. "=chain",
						"?" .. propParentDebate .. "=pdeb",
						"limit=200",
						"link=none"
					}

					local res = smwAskSafe(q)
					if type(res) == "table" then
						for _, row in ipairs(res) do
							local chain = row.chain
							local okLen = false

							if type(chain) == "string" then
								local n = breadcrumbLenByteSafe(chain)
								okLen = (n > 0 and n < 6)
							elseif type(chain) == "table" then
								for _, ch in ipairs(chain) do
									if type(ch) == "string" then
										local n = breadcrumbLenByteSafe(ch)
										if n > 0 and n < 6 then
											okLen = true
											break
										end
									end
								end
							end

							if okLen then
								local pdeb = row.pdeb
								if type(pdeb) == "string" and t_trim(pdeb) ~= "" then
									table_insert(final, canonicalTitle(pdeb))
								elseif type(pdeb) == "table" then
									for _, x in ipairs(pdeb) do
										if type(x) == "string" and t_trim(x) ~= "" then
											table_insert(final, canonicalTitle(x))
										elseif type(x) == "table" then
											local ft = x.fulltext or x.page or x[1]
											if type(ft) == "string" and t_trim(ft) ~= "" then
												table_insert(final, canonicalTitle(ft))
											end
										end
									end
								end
							end
						end
					end
				end
			end
		end
	end

	cache.debatsTousFinal = uniqList(final)
end

----------------------------------------------------------------------
--	Parents : cartes (normalisation + rendu 100% Lua) + cache (borné)
----------------------------------------------------------------------

local function normalizeTitleForCard(s)
	s = t_trim(tostring(s or ""))
	if s == "" then
		return ""
	end

	if s:byte(1) ~= 58 and not s:find("[%[<&]") then
		return s
	end

	s = s:gsub("^:%s*", "")

	do
		local target = s:match("^%[%[([^%]|]+)%|.-%]%]$")
			or s:match("^%[%[([^%]]+)%]%]$")
		if target and target ~= "" then
			s = target
		end
	end

	s = t_trim(s):gsub("^:%s*", "")

	if s:find("&", 1, true) then
		s = s
			:gsub("&nbsp;", " ")
			:gsub("&#160;", " ")
			:gsub("&amp;", "&")
			:gsub("&quot;", '"')
			:gsub("&#39;", "'")
			:gsub("&lt;", "<")
			:gsub("&gt;", ">")
	end

	return t_trim(s)
end

local URLENC_CACHE = cacheMake(2000)

local function mwUrlEncodePF(s)
	s = tostring(s or "")
	local hit = cacheGet(URLENC_CACHE, s)
	if hit ~= nil then return hit end

	local out = mw_uri_encode(s, "QUERY")
	out = out:gsub("%%20", "+")

	cachePut(URLENC_CACHE, s, out)
	return out
end

local function argumentMapCardLua(targetTitle, currentArgTitle, typeValue)
	local t = normalizeTitleForCard(targetTitle)
	local a = normalizeTitleForCard(currentArgTitle)

	local p2 = mwUrlEncodePF(a)

	local dataAttr = ''

	if typeValue and typeValue ~= '' then
		dataAttr = ' data-' .. typeValue .. '="' .. escapeAttr( p2 ) .. '"'
	end

	return
		'<span class="hover-map"'
		.. dataAttr
		.. '>'
		.. '[[' .. t .. ']]'
		.. '</span>'

end

local CARD_CACHE = cacheMake(600)

local function cardRendered(kind, targetTitle, currentArgTitle, typeValue)
	local key =
		kind .. "\n"
		.. tostring(targetTitle or "") .. "\n"
		.. tostring(currentArgTitle or "") .. "\n"
		.. tostring(typeValue or "")

	local cached = cacheGet(CARD_CACHE, key)
	if cached ~= nil then
		return cached
	end

	local rendered = argumentMapCardLua(targetTitle, currentArgTitle, typeValue)

	cachePut(CARD_CACHE, key, rendered)
	return rendered
end

local function cardDebateRendered(debateTitle, currentArgTitle, typeValue)
	return cardRendered("debate", debateTitle, currentArgTitle, typeValue)
end

local function cardArgumentRendered(argTitle, currentArgTitle, typeValue)
	return cardRendered("argument", argTitle, currentArgTitle, typeValue)
end

local function ensureDebateCards(pv, cache, lang, PF)
	if not cache or cache.debatCardsTous ~= nil then
		return
	end

	local argTitle = pv.rawTitle

	local function debateKey(d)
		return canonicalTitle(d)
	end

	local linked = {}

	for _, d in ipairs(cache.debatsPour or {}) do
		local k = debateKey(d)
		if k ~= "" then
			linked[k] = true
		end
	end
	for _, d in ipairs(cache.debatsContre or {}) do
		local k = debateKey(d)
		if k ~= "" then
			linked[k] = true
		end
	end

	local cardsTous = {}
	for _, d in ipairs(cache.parentDebatesFinal or {}) do
		local k = debateKey(d)
		local typeDebate = (k ~= "" and linked[k]) and "debate" or ""
		table_insert(cardsTous, cardDebateRendered(d, argTitle, typeDebate))
	end
	cache.debatCardsTous = cardsTous

	local cardsPour = {}
	for _, d in ipairs(cache.debatsPour or {}) do
		table_insert(cardsPour, cardDebateRendered(d, argTitle, "debate"))
	end
	cache.debatCardsPour = cardsPour

	local cardsContre = {}
	for _, d in ipairs(cache.debatsContre or {}) do
		table_insert(cardsContre, cardDebateRendered(d, argTitle, "debate"))
	end
	cache.debatCardsContre = cardsContre
end

local function ensureArgumentCards(pv, cache, PF)
	if not cache or cache.argCardsJustifs ~= nil then
		return
	end

	ensureArgumentParents(pv, cache, PF)

	local argTitle		= pv.rawTitle
	local typeArgument	= "argument"

	local cj = {}
	for _, a in ipairs(cache.justifs or {}) do
		table_insert(cj, cardArgumentRendered(a, argTitle, typeArgument))
	end
	cache.argCardsJustifs = cj

	local co = {}
	for _, a in ipairs(cache.objs or {}) do
		table_insert(co, cardArgumentRendered(a, argTitle, typeArgument))
	end
	cache.argCardsObjs = co
end

----------------------------------------------------------------------
--	Parents : bandeaux rendus en Lua
----------------------------------------------------------------------

local function ensureDot(s)
	s = tostring(s or "")
	s = t_trim(s)
	if s == "" then return s end
	if s:sub(-1) == "." then return s end
	return s .. "."
end

local function renderParentBanner(iconClass, texte)
	return
		'<div class="bandeau-en-tete searchaux wk-icon-before ' .. iconClass .. '">'
		.. ensureDot(texte)
		.. '</div>'
end

----------------------------------------------------------------------
--	Bandeaux : rendu natif + résolution via label i18n (aligné Debate)
----------------------------------------------------------------------

local function bannerMsg( lang, key, ... )
	return WD_I18N.msg( "Argument.banners", lang, key, ... ) or ""
end

local function renderMetaWarningHtml( params )
	local color	= tostring( params.color or "" )
	local icon	= tostring( params.icon or "" )
	local title	= tostring( params.title or "" )
	local text	= tostring( params.text or "" )

	return
		'<div class="bandeau bandeau-' .. color .. ' wk-icon-before ' .. icon .. ' navigation-not-searchable">'
		.. '<div class="bandeau-titre>' .. title .. '</div>'
		.. '<div class="bandeau-texte">' .. text .. '</div>'
		.. '</div>'
end

local function renderBannerByKey( pv, lang, key, cats )
	key = tostring( key or "" )
	if key == "" then
		return ""
	end

	local color	= bannerMsg( lang, key .. "_color" )
	local icon	= bannerMsg( lang, key .. "_icon" )
	local title	= bannerMsg( lang, key .. "_title", pv.rawTitle )
	local text	= bannerMsg( lang, key .. "_text", pv.rawTitle )

	if color == "" and icon == "" and title == "" and text == "" then
		return ""
	end

	local html = renderMetaWarningHtml{
		color	= color,
		icon	= icon,
		title	= title,
		text	= text
	}

	local cat = bannerMsg( lang, key .. "_category" )
	if cats and cat and cat ~= "" then
		table_insert( cats, "[[Category:" .. cat .. "]]" )
	end

	return html
end

local function buildBannerLabelLookup( lang, keys )
	local lookup = {}
	for _, key in ipairs( keys or {} ) do
		local lab = t_trim( bannerMsg( lang, key .. "_label" ) )
		if lab ~= "" then
			lookup[ lab ] = key
		end
	end
	return lookup
end

local function resolveBannerKeyFromLookup( raw, lookup )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return ""
	end
	return ( lookup and lookup[ raw ] ) or ""
end

local function renderBannersFromArgList( rawList, lookup, pv, lang, cats )
	local html = {}
	for _, x in ipairs( splitCSV( rawList, "," ) ) do
		local key = resolveBannerKeyFromLookup( x, lookup )
		if key ~= "" then
			table_insert( html, renderBannerByKey( pv, lang, key, cats ) )
		end
	end
	return table_concat( html )
end

local TITLE_WARNING_BANNER_KEYS = {
	"title_incomplete",
	"title_disadvantageous",
	"title_unclear",
	"title_too_long",
}

local ARGUMENT_WARNING_BANNER_KEYS = {
	"argument_sensitive",
	"argument_fanciful",
	"argument_potentially_illegal",
	"argument_generated_by_ai",
}

----------------------------------------------------------------------
--	Survol des cartes d'arguments
----------------------------------------------------------------------

local function joinCards(cards, lang)
	local n = (cards and #cards) or 0
	if n == 0 then
		return ""
	end
	if n == 1 then
		return cards[1] or ""
	end

	local conj = L(lang, "text", "list_conjunction_text")
	conj = tostring(conj or " et ")
	conj = conj:gsub("%s+", "&#32;")

	if n == 2 then
		return (cards[1] or "") .. conj .. (cards[2] or "")
	end

	local head = table_concat(cards, ", ", 1, n - 1)
	return head .. conj .. (cards[n] or "")
end

local function renderDebateParentsBoxes(pv, cats, lang, cache, PF)
	if not hasSMW() then return "" end

	ensureDebateParents(pv, cache, PF)
	ensureDebateCards(pv, cache, lang, PF)

	local out = {}

	local nTous = #(cache.parentDebatesFinal or {})
	if nTous > 0 then
		local msgKey = (nTous == 1) and "parent_used_in_debate_1" or "parent_used_in_debate_n"
		local texte = string.format(L(lang, "text", msgKey), ": " .. joinCards(cache.debatCardsTous, lang))
		table_insert(out, renderParentBanner("wk-icon-parent", texte))
	end

	if cache.debatsPour and #cache.debatsPour > 0 then
		local msgKey = (#cache.debatsPour == 1) and "parent_is_pro_in_1" or "parent_is_pro_in_n"
		local texte = string.format(L(lang, "text", msgKey), ": " .. joinCards(cache.debatCardsPour, lang))
		table_insert(out, renderParentBanner("wk-icon-argument is-pro", texte))
	end

	if cache.debatsContre and #cache.debatsContre > 0 then
		local msgKey = (#cache.debatsContre == 1) and "parent_is_con_in_1" or "parent_is_con_in_n"
		local texte = string.format(L(lang, "text", msgKey), ": " .. joinCards(cache.debatCardsContre, lang))
		table_insert(out, renderParentBanner("wk-icon-argument is-against", texte))
	end

	return table_concat(out)
end

local function renderArgumentParentsBoxes(pv, cats, lang, cache, PF)
	if not hasSMW() then return "" end

	ensureArgumentParents(pv, cache, PF)
	ensureArgumentCards(pv, cache, PF)

	local out = {}

	if cache.justifs and #cache.justifs > 0 then
		local texte = string.format(PF.parent_is_justification_of, ": " .. joinCards(cache.argCardsJustifs, lang))
		table_insert(out, renderParentBanner("wk-icon-argument is-pro", texte))
	end

	if cache.objs and #cache.objs > 0 then
		local texte = string.format(PF.parent_is_objection_to, ": " .. joinCards(cache.argCardsObjs, lang))
		table_insert(out, renderParentBanner("wk-icon-argument is-against", texte))
	end

	return table_concat(out)
end

local function renderDebatesParentsBottom(pv, lang, cache, PF)
	if not hasSMW() then return "" end

	ensureDebateCards(pv, cache, lang, PF)

	local n = #(cache.parentDebatesFinal or {})
	if n == 0 then
		return '<h2 id="Parent_debates" style="display:none;"></h2>'
	end

	local title = (n == 1) and PF.parent_debates_title_1 or PF.parent_debates_title_n

	local lis = {}
	for _, card in ipairs(cache.debatCardsTous or {}) do
		table_insert(lis,
			'<li style="position: relative;">'
			.. card
			.. '</li>'
		)
	end

	return
		'<h2 id="Parent_debates" class="wk-icon-before wk-icon-parent">' .. title .. '</h2>'
		.. '<ul class="hover-top navigation-not-searchable">'
		.. table_concat(lis)
		.. '</ul>'
end

----------------------------------------------------------------------
--	INITIALIZATION : bloc (si page “vide” / non initialisée)
----------------------------------------------------------------------

local computeArgumentMapsFromData

local function renderInitializationBlock(args, pv, cats, lang, cache, PF)

	local hasParents =
		(#(cache.parentDebatesFinal or {}) > 0)
		or (#(cache.justifs or {}) > 0)
		or (#(cache.objs or {}) > 0)

	if hasParents then
		return ""
	end

	local initRaw = args["initialization"]
	initRaw = (type(initRaw) == "string") and t_trim(initRaw) or ""
	local hasInit = (initRaw ~= "")

	local orphanBanner = ""

	orphanBanner = renderBannerByKey(pv, lang, "argument_orphan", cats) or ""

	if hasInit then

		local today = tostring(F:preprocess("{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}"))
		local creationDate = t_trim(tostring(args["creation-date"] or ""))

		if creationDate == today then
			return orphanBanner
		end

		table_insert(cats, "[[Category:" .. PF.cat_uninitialized .. "]]")

		local parts = splitByLiteral(initRaw, "@", 2)
		local initType = t_trim(parts[1] or "")
		local parentId = t_trim(parts[2] or "")

		local parentPage = ""
		do
			parentId = tostring(parentId or "")
			parentId = t_trim(parentId)
			parentId = parentId:match("(%d+)") or ""

			if parentId ~= "" and mw and mw.smw and type(mw.smw.ask) == "function" then
				PF.prop_debate_number = L(lang, "props", "debate_number")

				local ok, res = pcall(mw.smw.ask, {
					"[[" .. PF.prop_debate_number .. "::" .. parentId .. "]] OR [[" .. PF.prop_arg_number .. "::" .. parentId .. "]]",
					"limit=1",
					"link=none"
				})

				if ok and type(res) == "table" and res[1] then
					local row = res[1]
					local ft = row.fulltext or row.page or row[1]

					if type(ft) == "string" then
						ft = t_trim(ft)

						if ft ~= "" then
							parentPage = ft
						end
					end
				end
			end
		end

		parentPage = canonicalTitle(parentPage)

		local title = pv.rawTitle

		local t_arg_pro			= L(lang, "forms", "arg_pro")
		local t_arg_con			= L(lang, "forms", "arg_con")
		local t_justification	= L(lang, "params", "justification_type_label")
		local t_objection		= L(lang, "params", "objection_type_label")

		local formName = nil

		if initType == t_arg_pro then
			formName = L(lang, "forms", "edit_args_pro")

		elseif initType == t_arg_con then
			formName = L(lang, "forms", "edit_args_con")

		elseif initType == t_justification then
			formName = L(lang, "forms", "justifications")

		elseif initType == t_objection then
			formName = L(lang, "forms", "objections")
		end

		if not formName or formName == "" or not initType or initType == "" then
			return orphanBanner
		end

		local idx = 99
		local prefix = initType .. "[" .. tostring(idx) .. "]"

		local qsParts = {
			prefix .. "[" .. L(lang, "params", "page") .. "]=" .. title
		}

		local displayedTitleParam = t_trim(tostring(L(lang, "params", "displayed_title") or ""))

		if displayedTitleParam ~= "" then
			table_insert(
				qsParts,
				prefix .. "[" .. displayedTitleParam .. "]=" .. title
			)
		end

		local qs = table_concat(qsParts, "&")

		local warnTitle = string.format(PF.init_warning_title or "%s", formName)
		local warnIntro = PF.init_warning_intro

		local yesText = PF.init_yes_finish_label or PF.edit or ""
		local yesTooltip = string.format(PF.init_yes_tt or "%s", parentPage)
		local okText = string.format(PF.init_ok_text or "%s", parentPage)

		local summary

		if initType == t_arg_pro then
			summary = PF.init_summary_pro

		elseif initType == t_arg_con then
			summary = PF.init_summary_con

		elseif initType == t_justification then
			summary = PF.init_summary_justif

		elseif initType == t_objection then
			summary = PF.init_summary_obj

		else
			summary = ""
		end

		if summary ~= "" then
			summary = summary .. " : « " .. title .. " »"
		end

		local src =
			"{{#autoedit:"
			.. "| form = " .. formName
			.. "| target = " .. parentPage
			.. "| query string = " .. qs
			.. "| link text = " .. yesText
			.. "| link type = button"
			.. "| tooltip = " .. yesTooltip
			.. "| ok text = " .. okText
			.. (summary ~= "" and ("| summary = " .. summary) or "")
			.. "| reload"
			.. "}}"

		local auto = ""
		do
			local ok, r = pcall(F.preprocess, F, src)
			auto = (ok and r) and r or ""
		end

		return
			orphanBanner
			.. '<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\''
			.. warnTitle .. ' [[' .. parentPage .. ']].'
			.. '\'\'\'\n\n'
			.. warnIntro
			.. '\n'
			.. auto
			.. '</div><!-- Fin de warningbox -->'
	end

	return orphanBanner
end

----------------------------------------------------------------------
--	Fil d’Ariane : 1 seule requête SMW
----------------------------------------------------------------------

local function ensureBreadcrumbSelection(pv, cache, cats, lang, PF)
	if not cache or cache.breadcrumbChainsFinal ~= nil then
		return
	end

	cache.breadcrumbChainsFinal = {}
	cache.parentDebatesFinal = {}

	if not hasSMW() or type(mw.smw.ask) ~= "function" then
		return
	end

	lang = lang or "fr"

	local title = smwSafeTitle(pv.rawTitle)
	if title == "" then
		return
	end

	local propBreadcrumb = tostring(PF.prop_breadcrumb or "")
	local propDebateName = tostring(PF.prop_debate_name or "")
	local propPro = tostring(PF.prop_arg_pro or "")
	local propCon = tostring(PF.prop_arg_con or "")
	local propJustif = tostring(PF.prop_justif or "")
	local propObj = tostring(PF.prop_obj or "")

	if propBreadcrumb == "" or propPro == "" or propCon == "" or propJustif == "" or propObj == "" then
		return
	end

	local q = {
		"([[" .. propJustif .. "::" .. title .. "]] OR [[" .. propObj .. "::" .. title .. "]] OR [[" .. propPro .. "::" .. title .. "]] OR [[" .. propCon .. "::" .. title .. "]] )",
		"?" .. propBreadcrumb .. "=chain",
		(propDebateName ~= "" and ("?" .. propDebateName .. "=debateName") or nil),
		"limit=200",
		"link=none"
	}

	local qq = {}
	for _, x in ipairs(q) do
		if x then
			table_insert(qq, x)
		end
	end

	local res = smwAskSafe(qq)
	if type(res) ~= "table" or #res == 0 then
		return
	end

	local bestByDebate = {}

	local function consider(ch)
		if type(ch) ~= "string" or ch == "" then
			return
		end

		local parts = splitByLiteral(ch, "⟭")
		local clean = {}
		for _, part in ipairs(parts) do
			part = t_trim(part)
			if part ~= "" then
				clean[#clean + 1] = part
			end
		end

		local n = #clean
		if n == 0 then
			return
		end

		local root = canonicalTitle(clean[1] or "")
		if root == "" then
			return
		end

		local cur = bestByDebate[root]
		if not cur or n < cur.len then
			bestByDebate[root] = {
				root = root,
				chain = table_concat(clean, "⟭"),
				len = n
			}
		end
	end

	for _, row in ipairs(res) do
		local chain = row.chain

		if type(chain) == "string" then
			consider(chain)
		elseif type(chain) == "table" then
			for _, ch in ipairs(chain) do
				consider(ch)
			end
		end
	end

	local selected = {}
	for _, item in pairs(bestByDebate) do
		selected[#selected + 1] = item
	end

	if #selected == 0 then
		return
	end

	if #selected > 1 then
		local filtered = {}
		for _, item in ipairs(selected) do
			if item.len < 4 then
				filtered[#filtered + 1] = item
			end
		end
		selected = filtered
	end

	if #selected == 0 then
		return
	end

	local chains = {}
	local debates = {}
	local tooLong = false

	for _, item in ipairs(selected) do
		local newChain = item.chain .. "⟭" .. title

		if breadcrumbLenByteSafe(newChain) > 4 then
			tooLong = true
		end

		chains[#chains + 1] = newChain
		debates[#debates + 1] = item.root
	end

	if tooLong then
		table_insert(cats, "[[Category:" .. L(lang, "categories", "breadcrumb_too_long") .. "]]")
	end

	cache.breadcrumbChainsFinal = uniqList(chains)
	cache.parentDebatesFinal = uniqList(debates)
end

local function wkBuildArgumentData(items)
	items = items or {}

	------------------------------------------------------------------
	--	Liste texte (affichage)
	------------------------------------------------------------------

	local listLines = {}
	for _, it in ipairs(items) do
		table_insert(listLines, "* " .. tostring(it.title or ""))
	end
	local listText = table_concat(listLines, "\n")

	local argsVarParts = {}

	for _, it in ipairs(items) do
		local page = t_trim(tostring(it.page or ""))
		if page ~= "" then
			local title = t_trim(tostring(it.title or ""))

			if title == "" or title == page then
				table_insert(argsVarParts, page)
			else
				table_insert(
					argsVarParts,
					page
					.. FIELD_SEP
					.. title
				)
			end
		end
	end

	local argsVar = table_concat(argsVarParts, ITEM_SEP)

	------------------------------------------------------------------
	--	Carte hover (HTML)
	------------------------------------------------------------------

	local hoverLines = {}
	for _, it in ipairs(items) do
		table_insert(hoverLines,
			'<div class="argument-title--map wk-icon-before wk-icon-argument">' .. '[[' .. tostring(it.page or "") .. '|' .. tostring(it.title or "") .. ']]' .. '</div>'
		)
	end
	local mapHoverHtml = table_concat(hoverLines, "\n")

	return {
		items		= items,
		list		= listText,
		argsVar		= argsVar,
		mapHover	= mapHoverHtml
	}
end

computeArgumentMapsFromData = function(args)
	local rawJ = args["justifications"] or ""
	local rawO = args["objections"] or ""

	local justifItems = wkExtractItemsFromSerializedData( rawJ )
	local objItems = wkExtractItemsFromSerializedData( rawO )

	return {
		justif	= wkBuildArgumentData( justifItems ),
		obj		= wkBuildArgumentData( objItems )
	}
end

local function renderArgumentItemBanners( itemWarnings, pv, lang, cats, out )
	local warnsArg = t_trim( tostring( itemWarnings or "" ) )
	if warnsArg == "" then
		return
	end

	local lookup = buildSummaryLabelLookup( lang, DISPLAYED_TITLE_WARNING_BANNER_KEYS )
	local hasBanner = false

	for _, x in ipairs( splitCSV( warnsArg, "," ) ) do
		local key = resolveKeyFromLookup( x, lookup, DISPLAYED_TITLE_WARNING_BANNER_KEYS )
		if key ~= "" then
			table_insert( out, renderSubsectionBanner( pv, lang, key, nil, nil ) )
			hasBanner = true
		end
	end

	if hasBanner and cats then
		table_insert( cats, "[[Category:" .. L( lang, "categories", "displayed_titles_to_review" ) .. "]]" )
	end
end

local function wkRenderArgumentLi( pv, lang, it, kind, idx, cats )
	local isJustif = ( kind == "justif" )

	local idPrefix = isJustif and "Justification_" or "Objection_"

	local page = tostring( it.page or "" )
	local titleShown = tostring( it.title or "" )
	if titleShown == "" then
		titleShown = page
	end

	local aId = anchorId( titleShown )
	local warnings = tostring( it.warnings or "" )

	local banners = {}
	renderArgumentItemBanners( warnings, pv, lang, cats, banners )

	return
		'<li id="'
		.. idPrefix .. tostring( idx )
		.. '" class="argument level-1">'
		.. '<div id="'
		.. escapeAttr( aId )
		.. '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">'
		.. '[[' .. page .. '|' .. titleShown .. ']]'
		.. ( #banners > 0 and table_concat( banners ) or "" )
		.. '</div>'
		.. '</li>'
end

----------------------------------------------------------------------
--	Mots-clés : lien direct RunQuery (GET)
----------------------------------------------------------------------

local function renderKeywords(args, pv, cats, lang, PF)
	local out = {}
	table_insert(out, '<div style="font-size: 95%; margin-top: 1em;">' .. PF.keywords_label)

	local raw = args["keywords"]
	if raw and raw ~= "" then
		local extra = nil
		if PF.search_type_field ~= "" and PF.search_type_value ~= "" then
			extra = { [PF.search_type_field] = PF.search_type_value }
		end

		local rq_title = wkRunQueryPath(PF.form_search_by_keywords)
		local rq_qkey = PF.form_search_by_keywords .. "[" .. PF.keywords_field .. "]"

		local rendered = {}
		for _, k in ipairs(splitCSV(raw, ",")) do
			k = t_trim(tostring(k or ""))
			if k ~= "" then
				local chip = runQueryLink{
					form = PF.form_search_by_keywords,
					field = PF.keywords_field,
					value = k,
					label = k,
					tooltip = PF.search_by_keyword_tt,
					extra = extra,
					_title = rq_title,
					_qkey = rq_qkey
				}

				if chip and chip ~= "" then
					table_insert(rendered, chip)
				else
					table_insert(rendered, k)
				end
			end
		end

		if #rendered > 0 then
			table_insert(out, table_concat(rendered, ", "))
		else
			table_insert( out, "<strong>" .. PF.none .. "</strong>" )
			table_insert(cats, "[[Category:" .. PF.cat_no_keywords .. "]]")
		end
	else
		table_insert( out, "<strong>" .. PF.none .. "</strong>" )
		table_insert(cats, "[[Category:" .. PF.cat_no_keywords .. "]]")
	end

	table_insert(out,
		addDataEditLink(
			PF.form_edit_keywords,
			pv.rawTitle,
			"&nbsp;",
			PF.edit_keywords_tooltip
		)
		.. '<span style="display: none;">.</span></div>'
	)

	return table_concat(out)
end

local function renderSummary(args, pv, cats, lang, PF, summaryId)
	local edit = addDataEditLink(
		PF.form_edit_summary,
		pv.rawTitle,
		"&nbsp;",
		PF.edit_summary_tooltip
	)

	local out = {}

	table_insert(out,
		'<h2' .. summaryId .. ' class="section-modifiable wk-icon-before wk-icon-summary">'
		.. PF.summary
		.. edit
		.. '</h2>'
	)

	do
		local raw = args[ "summary-warnings" ]
		raw = ( type( raw ) == "string" ) and t_trim( raw ) or ""

		if raw ~= "" then
			local lookup = buildSummaryLabelLookup( lang, SUMMARY_WARNING_BANNER_KEYS )

			local aliases = SUMMARY_WARNING_ALIASES
			if not aliases or aliases._lang ~= lang then
				aliases = buildSummaryAliases(lang)
				aliases._lang = lang
				SUMMARY_WARNING_ALIASES = aliases
			end

			for _, x in ipairs( splitCSV( raw, "," ) ) do
				local key = resolveKeyFromLookup(
					x,
					lookup,
					SUMMARY_WARNING_BANNER_KEYS,
					aliases
				)

				if key ~= "" then
					table_insert( out, renderSubsectionBanner( pv, lang, key, nil, cats ) )
				end
			end
		end
	end

	if args["summary"] and args["summary"] ~= "" then
		table_insert(out, "<div class='argument-summary'>\n" .. args["summary"] .. "</div>")
	else
		table_insert(out, '<div class="aucun-contenu navigation-not-searchable">' .. PF.no_summary .. '</div>')
		table_insert(cats, "[[Category:" .. PF.cat_no_summary .. "]]")
	end

	return table_concat(out)
end

local function renderQuotes(args, pv, cats, lang, PF)
	local edit = addDataEditLink(
		PF.form_edit_quotes,
		pv.rawTitle,
		"&nbsp;",
		PF.edit_quotes_tooltip
	)

	local out = {}
	local idAttr = ""

	table_insert(out,
		'<h2 class="section-modifiable wk-icon-before wk-icon-quote"' .. idAttr .. '>'
		.. PF.quotes
		.. edit
		.. '</h2>'
	)

	local q = args["quotes"]
	if q and q ~= "" then
		table_insert(out, q)
	else
		table_insert(out, '<div class="aucun-contenu navigation-not-searchable">' .. PF.no_quotes .. '</div>')
		table_insert(cats, "[[Category:" .. PF.cat_no_quotes .. "]]")
	end

	return table_concat(out)
end

local function renderDebateDetailed(args, pv, cats, lang, PF)
	if not args["dedicated-debate"] or args["dedicated-debate"] == "" then
		return ""
	end

	table_insert(cats, "[[Category:" .. PF.cat_has_detailed_debate .. "]]")

	local contentToTransfer =
		tostring(args["justifications"] or "")
		.. tostring(args["objections"] or "")
		.. tostring(args["bibliography"] or "")
		.. tostring(args["webliography"] or "")
		.. tostring(args["videography"] or "")

	if t_trim(contentToTransfer) ~= "" then
		table_insert(cats, "[[Category:" .. PF.cat_content_to_transfer .. "]]")
	end

	local edit = addDataEditLink(
		PF.form_edit_detailed_debate,
		pv.rawTitle,
		"&nbsp;",
		string.format(PF.edit_detailed_debate_tooltip, pv.rawTitle)
	)

	local h2 =
		'<h2 class="section-modifiable wk-icon-before wk-icon-details">'
		.. PF.detailed_debate
		.. edit .. '</h2>'

	local debat = canonicalTitle(args["dedicated-debate"])

	local mapHtml = ""
	if hasSMW() and type(mw.smw.ask) == "function" and PF.prop_argument_map and PF.prop_argument_map ~= "" then
		local debatSafe = smwSafeTitle(debat)
		if debatSafe ~= "" then
			local res = smwAskSafe({
				"[[" .. debatSafe .. "]]",
				"?" .. PF.prop_argument_map .. "=map",
				"limit=1",
				"link=none"
			})

			if type(res) == "table" and res[1] and res[1].map then
				local v = res[1].map
				if type(v) == "string" then
					mapHtml = v
				elseif type(v) == "table" then
					for _, x in ipairs(v) do
						if type(x) == "string" and t_trim(x) ~= "" then
							mapHtml = x
							break
						elseif type(x) == "table" then
							local ft = x.fulltext or x.page or x[1]
							if type(ft) == "string" and t_trim(ft) ~= "" then
								mapHtml = ft
								break
							end
						end
					end
				end
			end
		end
	end

	local box =
		'<div class="bandeau-debat-detaille">'
		.. '<div style="font-weight: bold;">'
		.. string.format(PF.arg_is_debate, t_nowiki(pv.rawTitle))
		.. '</div>'
		.. '<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert navigation-not-searchable onglet-externe">'
		.. PF.detailed_debate_label .. '<span class="onglet-externe">[[ ' .. debat .. ' ]]</span>'
		.. '</div></div>'

	local mapBox = ""
	if mapHtml ~= "" then
		mapBox =
			'<div class="encart-debat-detaille onglet-externe">'
			.. '<div class="titre-debat-detaille">'
			.. debat
			.. '</div>'
			.. mapHtml
			.. '</div>'
	end

	return h2 .. box .. mapBox
end

----------------------------------------------------------------------
--	Sections
----------------------------------------------------------------------

local function safeRubriqueValue(s)
	s = t_trim(tostring(s or ""))
	if s == "" then
		return ""
	end
	if s:find("[%[%]{}|#<>]") then
		return ""
	end
	s = s:gsub("%s+", " ")
	s = t_trim(s)
	return s
end

local function renderRubriques(args, cats, lang, PF)
	if args["sections"] and args["sections"] ~= "" then
		local out = {}
		local nOk = 0
		for _, r in ipairs(splitCSV(args["sections"], ",")) do
			r = safeRubriqueValue(r)
			if r ~= "" then
				nOk = nOk + 1
				table_insert(out, "[[Category:" .. r .. "]]")
			end
		end
		if nOk > 0 then
			return table_concat(out)
		end
		return "[[Category:" .. PF.cat_no_sections .. "]]"
	else
		return "[[Category:" .. PF.cat_no_sections .. "]]"
	end
end

----------------------------------------------------------------------
--	CITATIONS
----------------------------------------------------------------------
local function wkParseQuoteMarkers(raw)
	raw = tostring(raw or "")
	raw = t_trim(raw)
	if raw == "" then
		return {}
	end

	--	Heuristique: si aucun séparateur de champ, pas notre format
	if not raw:find(QUOTE_FIELD_SEP, 1, true) then
		return {}
	end

	local items = {}
	local blocks

	--	Si séparateur d’items présent : split
	if raw:find(QUOTE_ITEM_SEP, 1, true) then
		blocks = splitByLiteral(raw, QUOTE_ITEM_SEP)
	else
		blocks = { raw }
	end

	for _, block in ipairs(blocks) do
		block = t_trim(block)
		if block ~= "" then
			local f = {}
			local parts = splitByLiteral(block, QUOTE_FIELD_SEP)

			for _, part in ipairs(parts) do
				f[#f + 1] = t_trim(tostring(part or ""))
			end

			for i = #f + 1, 13 do
				f[i] = ""
			end

			items[#items + 1] = {
				quote		= f[1],
				authors		= f[2],
				article		= f[3],
				work		= f[4],
				volume		= f[5],
				number		= f[6],
				location	= f[7],
				page		= f[8],
				publisher	= f[9],
				place		= f[10],
				date		= f[11],
				link		= f[12],
				warnings	= f[13]
			}
		end
	end

	return items
end

local function wkRenderOneQuoteHtml(item, idx, pv, lang, cats, PF)
	local quote		= tostring(item.quote or "")
	local authors	= t_trim(item.authors or "")
	local article	= t_trim(item.article or "")
	local work		= t_trim(item.work or "")
	local volume	= t_trim(item.volume or "")
	local number	= t_trim(item.number or "")
	local page		= t_trim(item.page or "")
	local location	= t_trim(item.location or "")
	local publisher	= t_trim(item.publisher or "")
	local place		= t_trim(item.place or "")
	local date		= t_trim(item.date or "")
	local link		= t_trim(item.link or "")
	local warnings	= t_trim(item.warnings or "")

	--	Accumulateurs VariablesLua (comme Module:Quote)
	vset("WD_QUOTES_MAX", tostring(idx))

	if authors ~= "" then
		local cur = t_trim(tostring(vget("WD_QUOTES_AUTHORS") or ""))
		vset("WD_QUOTES_AUTHORS", (cur == "" and authors) or (cur .. "," .. authors))
	end
	if article ~= "" then
		local cur = t_trim(tostring(vget("WD_QUOTES_ARTICLES") or ""))
		vset("WD_QUOTES_ARTICLES", (cur == "" and article) or (cur .. "," .. article))
	end
	if work ~= "" then
		local cur = t_trim(tostring(vget("WD_QUOTES_WORKS") or ""))
		vset("WD_QUOTES_WORKS", (cur == "" and work) or (cur .. "," .. work))
	end
	if publisher ~= "" then
		local cur = t_trim(tostring(vget("WD_QUOTES_PUBLISHERS") or ""))
		vset("WD_QUOTES_PUBLISHERS", (cur == "" and publisher) or (cur .. "," .. publisher))
	end
	if place ~= "" then
		local cur = t_trim(tostring(vget("WD_QUOTES_PLACES") or ""))
		vset("WD_QUOTES_PLACES", (cur == "" and place) or (cur .. "," .. place))
	end

	--	Blockquote
	local warnHtml = ""
	if warnings ~= "" then
		local lookup = buildSummaryLabelLookup( lang, QUOTE_WARNING_BANNER_KEYS )
		local b = {}

		for x in t_gsplit( warnings, ",", true ) do
			x = t_trim( x )
			if x ~= "" then
				local key = resolveKeyFromLookup( x, lookup, QUOTE_WARNING_BANNER_KEYS )
				if key ~= "" then
					local h = renderSubsectionBanner(
						pv,
						lang,
						key,
						nil,
						cats
					)
					if h ~= "" then
						b[ #b + 1 ] = h
					end
				end
			end
		end

		if #b > 0 then
			warnHtml = "<div>" .. table_concat( b, "" ) .. "</div>"
		end
	end

	local bq =
		'<blockquote data-wk-cite="' .. tostring(idx) .. '">'
		.. warnHtml
		.. '« ' .. quote .. ' »'
		.. '</blockquote>'

	--	Référence (sans fallback demandé => on n’invente pas d’auteur)
	local ref = {}
	table_insert(ref, '<div class="reference-citation" data-wk-cite="' .. tostring(idx) .. '">')

	if authors ~= "" then
		table_insert( ref, authors )
	else
		table_insert( ref, PF.unknown_author )
		table_insert( cats, "[[Category:" .. PF.cat_no_authors .. "]]" )
	end

	if article ~= "" then
		if link ~= "" then
			table_insert(ref, ', « [' .. link .. ' ' .. article .. '] »')
		else
			table_insert(ref, ', « ' .. article .. ' »')
		end
	end

	if work ~= "" then
		if article ~= "" then
			table_insert(ref, ", ''" .. work .. "''")
		else
			if link ~= "" then
				table_insert(ref, ", ''[" .. link .. " " .. work .. "]''")
			else
				table_insert(ref, ", ''" .. work .. "''")
			end
		end
	end

	if volume ~= "" then
		table_insert( ref, ", " .. PF.volume_label .. "&#160;" .. volume )
	end

	if number ~= "" then
		table_insert( ref, ", " .. PF.issue_label .. "&#160;" .. number )
	end

	if page ~= "" then
		table_insert(ref, ", p." .. page)
	end

	if location ~= "" then
		table_insert(ref, ", " .. location)
	end

	if publisher ~= "" then
		table_insert(ref, ", " .. publisher)
	end

	if place ~= "" then
		table_insert(ref, ", " .. place)
	end

	if date ~= "" then
		table_insert(ref, ", " .. date)
	end

	table_insert(ref, "</div>")

	local html = bq .. "\n" .. table_concat(ref) .. "\n"

	if idx >= 1 and idx <= 4 then
		vset("WD_QUOTE_" .. tostring(idx), html)
	end

	return html
end

local function wkRenderQuotesFromSerialized(raw, pv, lang, cats, PF)
	local items = wkParseQuoteMarkers(raw)
	if #items == 0 then
		return ""
	end

	local out = {}

	for i, it in ipairs( items ) do
		out[ #out + 1 ] = wkRenderOneQuoteHtml( it, i, pv, lang, cats, PF )
	end

	return table_concat(out, "")
end

----------------------------------------------------------------------
--	SMW : setSemanticData + argument_map/justif_list/obj_list
----------------------------------------------------------------------

local function setSemanticData(args, pv, lang, cache, PF, maps, rawB, rawW, rawV)
	local props = {}

	props[PF.prop_arg_name] = pv.rawTitle
	props[PF.prop_arg_number] = pv.pageId

	if hasSMW() then
		ensureDebateParentsFinal(pv, cache, PF)
		ensureArgumentParents(pv, cache, PF)

		do
			local chains = uniqList(cache.breadcrumbChainsFinal or {})

			if #chains > 0 and PF.prop_breadcrumb and PF.prop_breadcrumb ~= "" then
				props[PF.prop_breadcrumb] = chains
			end

			if PF.prop_parent_debate and PF.prop_parent_debate ~= "" then
				local dp = uniqList(cache.parentDebatesFinal or {})
				if #dp > 0 then
					props[PF.prop_parent_debate] = dp
				end
			end
		end

		do
			local parents = {}
			for _, x in ipairs(cache.justifs or {}) do
				table_insert(parents, canonicalTitle(x))
			end
			for _, x in ipairs(cache.objs or {}) do
				table_insert(parents, canonicalTitle(x))
			end
			parents = uniqList(parents)

			if #parents > 0 and PF.prop_parent_argument and PF.prop_parent_argument ~= "" then
				props[PF.prop_parent_argument] = parents
			end
		end
	end

	maps = maps or {}
	local justif = maps.justif or {}
	local obj = maps.obj or {}

	do
		local lst = {}
		for _, it in ipairs((justif.items or {})) do
			local t = canonicalTitle(it.page or "")
			if t ~= "" then
				table_insert(lst, t)
			end
		end
		lst = uniqList(lst)

		if #lst > 0 and PF.prop_justif and PF.prop_justif ~= "" then
			props[PF.prop_justif] = lst
		end
	end

	do
		local lst = {}
		for _, it in ipairs((obj.items or {})) do
			local t = canonicalTitle(it.page or "")
			if t ~= "" then
				table_insert(lst, t)
			end
		end
		lst = uniqList(lst)

		if #lst > 0 and PF.prop_obj and PF.prop_obj ~= "" then
			props[PF.prop_obj] = lst
		end
	end

	local justifSurvol = t_trim(justif.mapHover or "")
	local objSurvol = t_trim(obj.mapHover or "")

	if justifSurvol == "" then justifSurvol = '<div class="argument-title--map carte-vide">' .. PF.no_justifications_short .. '</div>' end
	if objSurvol == "" then objSurvol = '<div class="argument-title--map carte-vide">' .. PF.no_objections_short .. '</div>' end

	if PF.prop_argument_map and PF.prop_argument_map ~= "" then
		props[ PF.prop_argument_map ] =
			'<div class="wk-arg-map navigation-not-searchable">'
				.. '<div class="wk-arg-map__col is-pro">'
					.. '<div class="wk-arg-map__th">' .. PF.justifications .. '</div>'
					.. '<div class="wk-arg-map__td">' .. justifSurvol .. '</div>'
				.. '</div>'
				.. '<div class="wk-arg-map__col is-con">'
					.. '<div class="wk-arg-map__th">' .. PF.objections .. '</div>'
					.. '<div class="wk-arg-map__td">' .. objSurvol .. '</div>'
				.. '</div>'
			.. '</div>'
	end

	do
		local lst = t_trim(justif.argsVar or "")
		if lst ~= "" and PF.prop_justif_list and PF.prop_justif_list ~= "" then
			props[PF.prop_justif_list] = lst
		end
	end

	do
		local lst = t_trim(obj.argsVar or "")
		if lst ~= "" and PF.prop_obj_list and PF.prop_obj_list ~= "" then
			props[PF.prop_obj_list] = lst
		end
	end

	local mc = args["keywords"]
	if mc and mc ~= "" then
		local list = splitCSV(mc, ",")
		if #list > 0 then
			props[PF.prop_keyword] = list
		end
	end

	if args["dedicated-debate"] and args["dedicated-debate"] ~= "" then
		props[PF.prop_detailed_debate] = canonicalTitle(args["dedicated-debate"])
	end

	do
		local function uniqCSVVar( varName )
			local raw = tostring( vget( varName ) or "" )
			raw = t_trim( raw )
			if raw == "" then
				return nil
			end

			local lst = splitCSV( raw, "," )
			lst = uniqList( lst )
			return ( #lst > 0 ) and lst or nil
		end

		local authors = uniqCSVVar( "WD_QUOTES_AUTHORS" )
		if authors then
			local k = smwPropRef( PF, "authors" )
			if k ~= "" then
				smwQueueAdd( props, k, authors, true )
			end
		end

		local articles = uniqCSVVar( "WD_QUOTES_ARTICLES" )
		if articles then
			local k = smwPropRef( PF, "article_name" )
			if k ~= "" then
				smwQueueAdd( props, k, articles, true )
			end
		end

		local works = uniqCSVVar( "WD_QUOTES_WORKS" )
		if works then
			local k = smwPropRef( PF, "work_name" )
			if k ~= "" then
				smwQueueAdd( props, k, works, true )
			end
		end

		local pubs = uniqCSVVar( "WD_QUOTES_PUBLISHERS" )
		if pubs then
			local k = smwPropRef( PF, "publishing_house" )
			if k ~= "" then
				smwQueueAdd( props, k, pubs, true )
			end
		end

		local places = uniqCSVVar( "WD_QUOTES_PLACES" )
		if places then
			local k = smwPropRef( PF, "place_of_publication" )
			if k ~= "" then
				smwQueueAdd( props, k, places, true )
			end
		end
	end

	do
		if args["sections"] and args["sections"] ~= "" then
			local lst = {}
			for _, r in ipairs(splitCSV(args["sections"], ",")) do
				r = safeRubriqueValue(r)
				if r ~= "" then
					table_insert(lst, r)
				end
			end
			lst = uniqList(lst)
	
			if #lst > 0 and PF.prop_section and PF.prop_section ~= "" then
				props[PF.prop_section] = lst
			end
		end
	end

	smwSetSafe(props)
end

----------------------------------------------------------------------
--	SEO via Wikiseo (mw.ext.seo.set)
----------------------------------------------------------------------

local SEO_CACHE = cacheMake(60)

local function wkSeoHas()
	return mw.ext
		and mw.ext.seo
		and type( mw.ext.seo.set ) == "function"
end

local function wkSeoSet( data )
	if not wkSeoHas() then
		return
	end
	pcall( mw.ext.seo.set, data )
end

local function seoL( lang, key, ... )
	return WD_I18N.msg( "Argument.seo", lang, key, ... ) or ""
end

local function seoJoinList( items )
	local out = {}
	local seen = {}

	for _, v in ipairs( items or {} ) do
		v = t_trim( tostring( v or "" ) )
		if v ~= "" and not seen[ v ] then
			seen[ v ] = true
			out[ #out + 1 ] = v
		end
	end

	return table_concat( out, ", " )
end

local function buildSeoKeywords( args, lang )
	local items = {}

	for _, k in ipairs( splitCSV( args[ "keywords" ], "," ) ) do
		items[ #items + 1 ] = k
	end

	for _, s in ipairs( splitCSV( args[ "sections" ], "," ) ) do
		items[ #items + 1 ] = s
	end

	return seoJoinList( items )
end

local function seoKeyPart(s)
	s = tostring(s or "")
	if mw_hash then
		local ok, h = pcall(mw_hash.hashValue, "md5", s)
		if ok and type(h) == "string" and h ~= "" then
			return h
		end
	end
	return s
end

local function renderFullSEO( args, pv, lang )
	local titleText		= pv.rawTitle or ""

	local ck =
		"SEO\n" .. tostring( lang or "" ) .. "\n" .. tostring( pv.pageId or "" ) .. "\n"
		.. titleText .. "\n"
		.. seoKeyPart( args[ "keywords" ] or "" ) .. "\n"
		.. seoKeyPart( args[ "sections" ] or "" )

	local hit = cacheGet( SEO_CACHE, ck )
	if hit ~= nil then
		return ""
	end
	cachePut( SEO_CACHE, ck, true )

	local topic = t_trim( tostring( args[ "keywords" ] or "" ) )
	if topic == "" then
		topic = titleText
	end

	local kw = buildSeoKeywords( args, lang )

	wkSeoSet{
		title				= titleText .. L(lang, "seo", "space") .. ": " .. L(lang, "seo", "shortdesc_plain") .. " – " .. L(lang, "seo", "site_name"),
		title_mode			= titleText .. L(lang, "seo", "space") .. ": " .. L(lang, "seo", "shortdesc_plain"),
		title_separator		= string_format( seoL( lang, "title_separator" ), titleText ),
		description			= string_format( seoL( lang, "description" ), topic ),
		keywords			= kw,
		author				= seoL( lang, "author" ),
		image				= seoL( lang, "image" ),
		image_alt			= seoL( lang, "image_alt" ),
		type				= "article",
		section				= seoL( lang, "section" ),
		site_name			= seoL( lang, "site_name" ),
		twitter_site		= seoL( lang, "twitter_site" ),
		locale				= seoL( lang, "locale" ),
		robots				= "index,follow",
		googlebot			= "index,follow",
	}

	return ""
end

----------------------------------------------------------------------
--	JSON-LD : helpers Argument / Breadcrumb / WebSite
----------------------------------------------------------------------

local function wkBuildWikiUrl( lang, prefixKey, labelKey )
	return L( lang, "urls", "wiki_base" )
		.. mw_uri_encode(
			L( lang, "urls", prefixKey ) .. L( lang, "urls", labelKey ),
			"WIKI"
		)
end

local function wkBuildWikiUrlFromText( lang, prefixKey, text )
	text = t_trim( tostring( text or "" ) )
	if text == "" then
		return ""
	end

	return L( lang, "urls", "wiki_base" )
		.. mw_uri_encode(
			L( lang, "urls", prefixKey ) .. text,
			"WIKI"
		)
end

local function wkFullUrl( titleText )
	titleText = t_trim( tostring( titleText or "" ) )
	if titleText == "" then
		return ""
	end

	local ok, u = pcall( mw_uri_fullUrl, titleText )
	if ok and u then
		return tostring( u )
	end

	return ""
end

local function wkTakeFirst( items, limit )
	local out = {}
	limit = tonumber( limit ) or 0

	if limit <= 0 then
		return out
	end

	for i = 1, #items do
		if i > limit then
			break
		end
		out[ #out + 1 ] = items[ i ]
	end

	return out
end

local function wkBuildWebsiteNode( lang )
	local homeUrl = L( lang, "urls", "home" )
	local siteName = L( lang, "seo", "site_name" )

	return {
		[ "@type" ] = "WebSite",
		[ "@id" ] = homeUrl .. "#website",
		url = homeUrl,
		name = siteName
	}
end

local function wkBuildOrganizationNode( lang )
	local homeUrl = L( lang, "urls", "home" )
	local siteName = L( lang, "seo", "site_name" )

	return {
		[ "@type" ] = "Organization",
		[ "@id" ] = homeUrl .. "#organization",
		name = siteName,
		url = homeUrl
	}
end

local function wkBuildBreadcrumbNode( pv, lang )
	local homeLabel = L( lang, "urls", "home_label" )
	local homeUrl = L( lang, "urls", "home" )
	local argsLabel = L( lang, "urls", "arguments_label" )
	local argsUrl = wkBuildWikiUrl( lang, "portal_prefix", "arguments_label" )

	return {
		[ "@type" ] = "BreadcrumbList",
		[ "@id" ] = pv.pageUrl .. "#breadcrumb",
		itemListElement = {
			{
				[ "@type" ] = "ListItem",
				position = 1,
				item = {
					[ "@id" ] = homeUrl,
					name = homeLabel
				}
			},
			{
				[ "@type" ] = "ListItem",
				position = 2,
				item = {
					[ "@id" ] = argsUrl,
					name = argsLabel
				}
			},
			{
				[ "@type" ] = "ListItem",
				position = 3,
				item = {
					[ "@id" ] = pv.pageUrl,
					name = pv.rawTitle
				}
			}
		}
	}
end

local function wkBuildAboutAndMentions( args )
	local about = {}
	local mentions = {}
	local rawKeywords = splitCSV( args and args[ "keywords" ] or "", "," )

	for i = 1, #rawKeywords do
		local kw = t_trim( tostring( rawKeywords[ i ] or "" ) )
		if kw ~= "" then
			local node = {
				[ "@type" ] = "Thing",
				name = kw
			}

			if i <= 3 then
				about[ #about + 1 ] = node
			else
				mentions[ #mentions + 1 ] = node
			end
		end
	end

	return about, mentions
end

local function wkBuildKeywords( args, lang )
	local out = {}
	local sections = splitCSV( args and args[ "sections" ] or "", "," )
	local termSetId = wkBuildWikiUrl( lang, "category_prefix", "sections_label" )

	for i = 1, #sections do
		if i > 4 then
			break
		end

		local section = t_trim( tostring( sections[ i ] or "" ) )
		if section ~= "" then
			out[ #out + 1 ] = {
				[ "@type" ] = "DefinedTerm",
				[ "@id" ] = wkBuildWikiUrlFromText( lang, "category_prefix", section ),
				name = section,
				inDefinedTermSet = {
					[ "@id" ] = termSetId
				}
			}
		end
	end

	return out
end

local function wkBuildDiscussionUrl( pv, lang )
	return wkBuildWikiUrlFromText( lang, "discussion_prefix", pv.rawTitle )
end

local function wkAppendAllValidIds( out, titles, suffix, lang )
	local added = false

	for _, title in ipairs( titles or {} ) do
		title = canonicalTitle( t_trim( tostring( title or "" ) ) )

		if title ~= "" then
			local url = wkFullUrl( title, lang )

			if url ~= "" then
				out[ #out + 1 ] = {
					[ "@id" ] = url .. suffix
				}
				added = true
			end
		end
	end

	return added
end

local function wkAppendFirstValidId( out, titles, suffix, lang )
	for _, title in ipairs( titles or {} ) do
		title = canonicalTitle( t_trim( tostring( title or "" ) ) )

		if title ~= "" then
			local url = wkFullUrl( title, lang )

			if url ~= "" then
				out[ #out + 1 ] = {
					[ "@id" ] = url .. suffix
				}
				return true
			end
		end
	end

	return false
end

local function wkBuildClaimIsPartOf( lang, cache )
	local out = {}

	out[ #out + 1 ] = {
		[ "@id" ] = wkBuildWikiUrl( lang, "category_prefix", "arguments_label" )
	}

	local debats = {}

	for _, d in ipairs( cache and cache.debatsPour or {} ) do
		table_insert( debats, canonicalTitle( d ) )
	end
	for _, d in ipairs( cache and cache.debatsContre or {} ) do
		table_insert( debats, canonicalTitle( d ) )
	end

	debats = uniqList( debats )

	if wkAppendAllValidIds( out, debats, "#qa", lang ) then
		return out
	end

	if wkAppendFirstValidId( out, cache and cache.justifs or {}, "#claim", lang ) then
		return out
	end

	if wkAppendFirstValidId( out, cache and cache.objs or {}, "#claim", lang ) then
		return out
	end

	return out
end

local function wkBuildHasPart( args, maps, lang )
	local out = {}

	local detailedDebate = t_trim( tostring( args and args[ "dedicated-debate" ] or "" ) )
	detailedDebate = canonicalTitle( detailedDebate )

	if detailedDebate ~= "" then
		local url = wkFullUrl( detailedDebate, lang )

		if url ~= "" then
			out[ #out + 1 ] = {
				[ "@id" ] = url .. "#qa"
			}
			return out
		end
	end

	local justifItems = ( maps and maps.justif and maps.justif.items ) or {}
	local objItems = ( maps and maps.obj and maps.obj.items ) or {}

	for i = 1, math.min( #justifItems, 3 ) do
		local item = justifItems[ i ]
		local argTitle = t_trim( tostring( ( item and item.page ) or "" ) )
		argTitle = canonicalTitle( argTitle )

		local url = wkFullUrl( argTitle, lang )

		if url ~= "" then
			out[ #out + 1 ] = {
				[ "@type" ] = "Claim",
				[ "@id" ] = url .. "#claim",
				additionalType = wkBuildWikiUrl( lang, "property_prefix", "justification_label" )
			}
		end
	end

	for i = 1, math.min( #objItems, 1 ) do
		local item = objItems[ i ]
		local argTitle = t_trim( tostring( ( item and item.page ) or "" ) )
		argTitle = canonicalTitle( argTitle )

		local url = wkFullUrl( argTitle, lang )

		if url ~= "" then
			out[ #out + 1 ] = {
				[ "@type" ] = "Claim",
				[ "@id" ] = url .. "#claim",
				additionalType = wkBuildWikiUrl( lang, "property_prefix", "objection_label" )
			}
		end
	end

	return out
end

local function wkBuildQuoteAuthorValue( raw )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return nil
	end

	local authors = splitCSV( raw, "," )

	if #authors == 0 then
		return nil
	end

	if #authors == 1 then
		return {
			[ "@type" ] = "Person",
			name = authors[ 1 ]
		}
	end

	local out = {}

	for _, one in ipairs( authors ) do
		one = t_trim( tostring( one or "" ) )

		if one ~= "" then
			out[ #out + 1 ] = {
				[ "@type" ] = "Person",
				name = one
			}
		end
	end

	if #out == 0 then
		return nil
	end

	return out
end

local function wkBuildSingleNodeFromFirstItem( items, buildNode )
	if type( items ) ~= "table" or not items[ 1 ] then
		return {}
	end

	local node = buildNode( items[ 1 ] )

	if node then
		return { node }
	end

	return {}
end

local function wkBuildClaimCitationsFromQuotes( raw )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return {}
	end

	local items = wkParseQuoteMarkers( raw )

	return wkBuildSingleNodeFromFirstItem( items, function( item )
		local authors = t_trim( tostring( item.authors or "" ) )
		local article = t_trim( tostring( item.article or "" ) )
		local work = t_trim( tostring( item.work or "" ) )
		local volume = t_trim( tostring( item.volume or "" ) )
		local number = t_trim( tostring( item.number or "" ) )
		local publisher = t_trim( tostring( item.publisher or "" ) )
		local date = t_trim( tostring( item.date or "" ) )
		local link = t_trim( tostring( item.link or "" ) )

		local node = nil

		if number ~= "" and article ~= "" and work ~= "" then
			node = {
				[ "@type" ] = "Article",
				name = article,
				isPartOf = {
					[ "@type" ] = "Periodical",
					name = work
				}
			}

			if volume ~= "" then
				node.volumeNumber = volume
			end

			node.issueNumber = number

		elseif publisher ~= "" and work ~= "" then
			node = {
				[ "@type" ] = "Book",
				name = work
			}

			if article ~= "" then
				node.description = article
			end

			if volume ~= "" then
				node.volumeNumber = volume
			end

			if number ~= "" then
				node.issueNumber = number
			end

		elseif link ~= "" and article ~= "" then
			node = {
				[ "@type" ] = "WebPage",
				name = article
			}

			if work ~= "" then
				node.isPartOf = {
					[ "@type" ] = "WebSite",
					name = work
				}
			end
		end

		if not node then
			return nil
		end

		local authorValue = wkBuildQuoteAuthorValue( authors )
		if authorValue then
			node.author = authorValue
		end

		if publisher ~= "" then
			node.publisher = {
				[ "@type" ] = "Organization",
				name = publisher
			}
		end

		if date ~= "" then
			node.datePublished = date
		end

		if link ~= "" then
			node.url = link
		end

		return node
	end )
end

local function wkBuildClaimCitationsFromBibliography( raw )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return {}
	end

	local items = wkParseBiblioMarkers( raw )

	return wkBuildSingleNodeFromFirstItem( items, function( item )
		local authors = t_trim( tostring( item.authors or "" ) )
		local article = t_trim( tostring( item.article or "" ) )
		local work = t_trim( tostring( item.work or "" ) )
		local volume = t_trim( tostring( item.volume or "" ) )
		local number = t_trim( tostring( item.number or "" ) )
		local publisher = t_trim( tostring( item.publisher or "" ) )
		local date = t_trim( tostring( item.date or "" ) )
		local link = t_trim( tostring( item.link or "" ) )

		local node = nil

		if number ~= "" and article ~= "" and work ~= "" then
			node = {
				[ "@type" ] = "Article",
				name = article,
				isPartOf = {
					[ "@type" ] = "Periodical",
					name = work
				}
			}

			if volume ~= "" then
				node.volumeNumber = volume
			end

			node.issueNumber = number

		elseif publisher ~= "" and work ~= "" then
			node = {
				[ "@type" ] = "Book",
				name = work
			}

			if article ~= "" then
				node.description = article
			end

			if volume ~= "" then
				node.volumeNumber = volume
			end

			if number ~= "" then
				node.issueNumber = number
			end

		elseif link ~= "" and article ~= "" then
			node = {
				[ "@type" ] = "WebPage",
				name = article
			}

			if work ~= "" then
				node.isPartOf = {
					[ "@type" ] = "WebSite",
					name = work
				}
			end
		end

		if not node then
			return nil
		end

		local authorValue = wkBuildQuoteAuthorValue( authors )
		if authorValue then
			node.author = authorValue
		end

		if publisher ~= "" then
			node.publisher = {
				[ "@type" ] = "Organization",
				name = publisher
			}
		end

		if date ~= "" then
			node.datePublished = date
		end

		if link ~= "" then
			node.url = link
		end

		return node
	end )
end

local function wkBuildClaimCitationsFromWebliography( raw )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return {}
	end

	local items = wkParseWeblioMarkers( raw )

	return wkBuildSingleNodeFromFirstItem( items, function( item )
		local page = t_trim( tostring( item.page or "" ) )
		local site = t_trim( tostring( item.site or "" ) )
		local link = t_trim( tostring( item.link or "" ) )
		local date = t_trim( tostring( item.date or "" ) )

		if link == "" or page == "" then
			return nil
		end

		local node = {
			[ "@type" ] = "WebPage",
			name = page,
			url = link
		}

		if site ~= "" then
			node.isPartOf = {
				[ "@type" ] = "WebSite",
				name = site
			}
		end

		if date ~= "" then
			node.datePublished = date
		end

		return node
	end )
end

local function wkBuildClaimCitationsFromVideography( raw )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return {}
	end

	local items = wkParseVideoMarkers( raw )

	return wkBuildSingleNodeFromFirstItem( items, function( item )
		local title = t_trim( tostring( item.title or "" ) )
		local link = t_trim( tostring( item.link or "" ) )
		local authors = t_trim( tostring( item.authors or "" ) )

		if title == "" or link == "" then
			return nil
		end

		local node = {
			[ "@type" ] = "VideoObject",
			name = title,
			url = link
		}

		local authorValue = wkBuildQuoteAuthorValue( authors )
		if authorValue then
			node.author = authorValue
		end

		return node
	end )
end

local function wkProtectJsonLdForParser( json )
	if not json or json == "" then
		return ""
	end

	json = tostring( json )

	--	protéger les séparateurs de parser function
	json = json:gsub( "|", "&#124;" )
	json = json:gsub( "=", "&#61;" )
	json = json:gsub( "/", "\\/" )

	--	éviter de fermer prématurément {{#tag:...}}
	json = json:gsub( "}}", "} }" )

	return json
end

local function renderArgumentSchemaJSONLD( args, pv, lang, cache, PF, maps )
	ensureDebateParentsFinal( pv, cache, PF )
	ensureArgumentParents( pv, cache, PF )
	ensureBreadcrumbSelection( pv, cache, {}, lang, PF )

	local websiteNode = wkBuildWebsiteNode( lang )
	local breadcrumbNode = wkBuildBreadcrumbNode( pv, lang )
	local organizationNode = wkBuildOrganizationNode( lang )
	local about, mentions = wkBuildAboutAndMentions( args )
	local keywords = wkBuildKeywords( args, lang )
	local isPartOf = wkBuildClaimIsPartOf( lang, cache )
	local hasPart = wkBuildHasPart( args, maps, lang )
	local creationDate = getPageCreation(pv, args)

	local citations = {}
	
	local q = wkBuildClaimCitationsFromQuotes(
		args[ "_quotes_wikitext" ] or ""
	)
	if q[ 1 ] then
		citations[ #citations + 1 ] = q[ 1 ]
	end
	
	local b = wkBuildClaimCitationsFromBibliography(
		args[ "bibliography" ] or ""
	)
	if b[ 1 ] then
		citations[ #citations + 1 ] = b[ 1 ]
	end
	
	local w = wkBuildClaimCitationsFromWebliography(
		args[ "webliography" ] or ""
	)
	if w[ 1 ] then
		citations[ #citations + 1 ] = w[ 1 ]
	end
	
	local subjectOf = wkBuildClaimCitationsFromVideography(
		args[ "videography" ] or ""
	)

	local pageNode = {
		[ "@type" ] = "WebPage",
		[ "@id" ] = pv.pageUrl,
		url = pv.pageUrl,
		name = pv.rawTitle,
		inLanguage = lang,
		isPartOf = {
			[ "@id" ] = L( lang, "urls", "home" ) .. "#website"
		},
		breadcrumb = {
			[ "@id" ] = pv.pageUrl .. "#breadcrumb"
		},
		mainEntity = {
			[ "@id" ] = pv.pageUrl .. "#claim"
		}
	}

	local claimNode = {
		[ "@type" ] = "Claim",
		[ "@id" ] = pv.pageUrl .. "#claim",
		url = pv.pageUrl,
		name = pv.rawTitle,
		inLanguage = lang,
		learningResourceType = "argument",
		educationalUse = "analysis",
		educationalLevel = "higher education",
		audience = {
			[ "@type" ] = "EducationalAudience",
			educationalRole = "student"
		},
		isPartOf = isPartOf,
		breadcrumb = {
			[ "@id" ] = pv.pageUrl .. "#breadcrumb"
		},
		discussionUrl = wkBuildDiscussionUrl( pv, lang ),
		publisher = {
			[ "@id" ] = L( lang, "urls", "home" ) .. "#organization"
		}
	}

	if args["established-name"] and t_trim(args["established-name"]) ~= "" then
		claimNode.alternateName = args["established-name"]
	end

	do
		local summary = t_trim( tostring( args and args[ "summary" ] or "" ) )
		if summary ~= "" then
			claimNode.abstract = summary
		end
	end

	if creationDate then
		claimNode.datePublished = creationDate
	end

	if #about > 0 then
		claimNode.about = about
	end

	if #mentions > 0 then
		claimNode.mentions = mentions
	end

	if #keywords > 0 then
		claimNode.keywords = keywords
	end

	if #hasPart > 0 then
		claimNode.hasPart = hasPart
	end

	if #citations > 0 then
		claimNode.citation = citations
	end
	
	if #subjectOf > 0 then
		claimNode.subjectOf = subjectOf
	end

	local data = {
		[ "@context" ] = "https://schema.org",
		[ "@graph" ] = {
			websiteNode,
			organizationNode,
			breadcrumbNode,
			pageNode,
			claimNode
		}
	}

	local json = safeJsonEncode( data )
	
	return tagHtmlTag( "script", wkProtectJsonLdForParser( json ), {
		type = "application/ld+json",
		["class"] = "navigation-not-searchable"
	} )
end

----------------------------------------------------------------------
--	Rendu principal (i18n)
----------------------------------------------------------------------

function p.render(frame)
	vset( "WD_QUOTES_AUTHORS", "" )
	vset( "WD_QUOTES_ARTICLES", "" )
	vset( "WD_QUOTES_WORKS", "" )
	vset( "WD_QUOTES_PUBLISHERS", "" )
	vset( "WD_QUOTES_PLACES", "" )
	vset( "WD_QUOTES_MAX", "" )
	vset( "WD_QUOTE_1", "" )
	vset( "WD_QUOTE_2", "" )
	vset( "WD_QUOTE_3", "" )
	vset( "WD_QUOTE_4", "" )

	EXPAND_CACHE = cacheMake(250)
	CARD_CACHE = cacheMake(600)
	SMW_ASK_CACHE = cacheMake(250)
	SMW_TITLES_CACHE = cacheMake(250)
	I18N_DOMAIN = {}
	I18N_MSG = {}
	TITLE_CACHE = cacheMake(600)
	SEO_CACHE = cacheMake(60)

	local args = getArgs(frame)

	local function norm(k)
		local v = args[k]
		if type(v) ~= "string" then
			return
		end
		v = t_trim(v)
		if v == "" then
			args[k] = nil
		else
			args[k] = v
		end
	end

	norm("summary")
	norm("quotes")
	norm("bibliography")
	norm("webliography")
	norm("videography")
	norm("keywords")
	norm("sections")
	norm("interlanguage")
	norm("dedicated-debate")

	norm("title-warnings")
	norm("argument-warnings")
	norm("summary-warnings")
	norm("justification-warnings")
	norm("objection-warnings")

	local rawB = args["bibliography"] or ""
	local rawW = args["webliography"] or ""
	local rawV = args["videography"] or ""

	if type(args["justifications"]) ~= "string" then args["justifications"] = nil end
	if type(args["objections"]) ~= "string" then args["objections"] = nil end

	local lang	= detectLang(args)
	local pv		= computePageVars()
	pv.lang = lang

	local cache	= makeSMWCache()
	local pieces, cats = {}, {}

	local PF = {}

	PF.file_search						= L(lang, "files", "search")

	PF.parent_is_justification_of		= L(lang, "text", "parent_is_justification_of")
	PF.parent_is_objection_to			= L(lang, "text", "parent_is_objection_to")

	PF.keywords_label					= L(lang, "text", "keywords_label_text")
	PF.none								= L(lang, "text", "none_label")

	PF.keywords_field					= L(lang, "params", "keywords_field")
	PF.form_search_by_keywords			= L(lang, "forms", "search_by_keywords_title")
	PF.search_by_keyword_tt				= L(lang, "text", "search_by_keyword_tt")
	PF.search_type_field				= tostring(L(lang, "params", "search_type_field") or "")
	PF.search_type_value				= tostring(L(lang, "params", "search_type_value") or "")

	PF.form_edit_keywords				= L(lang, "forms", "keywords_edit_title")
	PF.edit_keywords_tooltip			= L(lang, "text", "keywords_edit_tt")

	PF.form_edit_summary				= L(lang, "forms", "summary_edit_title")
	PF.edit_summary_tooltip				= L(lang, "text", "summary_edit_tt")

	PF.form_edit_quotes					= L(lang, "forms", "quotes_edit_title")
	PF.edit_quotes_tooltip				= L(lang, "text", "quotes_edit_tt")

	PF.form_edit_references				= L(lang, "forms", "references_edit_title")
	PF.edit_references_tooltip			= L(lang, "text", "references_edit_tt")

	PF.form_edit_justifications			= L(lang, "forms", "justifications_form")
	PF.edit_justifications_tooltip		= L(lang, "text", "justifications_edit_tt")

	PF.form_edit_objections				= L(lang, "forms", "objections_form")
	PF.edit_objections_tooltip			= L(lang, "text", "objections_edit_tt")

	PF.form_new_arg_title				= L(lang, "forms", "new_arg_title")
	PF.arg_new_title_field_base			= L(lang, "text", "arg_new_title_field_base")
	PF.justification_add_label			= L(lang, "text", "justification_add_label")
	PF.justification_add_tt				= L(lang, "text", "justification_add_tt")
	PF.objection_add_label				= L(lang, "text", "objection_add_label")
	PF.objection_add_tt					= L(lang, "text", "objection_add_tt")

	PF.summary							= L(lang, "text", "summary_title")
	PF.no_summary						= L(lang, "text", "none_summary_msg")
	PF.quotes							= L(lang, "text", "quotes_title")
	PF.no_quotes						= L(lang, "text", "none_quotes_msg")
	PF.references						= L(lang, "text", "references_title")
	PF.no_references					= L(lang, "text", "none_references_msg")
	PF.justifications					= L(lang, "text", "justifications_title")
	PF.no_justifications				= L(lang, "text", "none_justifications_msg")
	PF.no_justifications_short			= L(lang, "text", "none_justifications_short_msg")
	PF.objections						= L(lang, "text", "objections_title")
	PF.no_objections					= L(lang, "text", "none_objections_msg")
	PF.no_objections_short				= L(lang, "text", "none_objections_short_msg")

	PF.form_edit_detailed_debate		= L(lang, "forms", "detailed_debate_edit_title")
	PF.edit_detailed_debate_tooltip		= L(lang, "text", "detailed_debate_edit_tt")
	PF.detailed_debate					= L(lang, "text", "detailed_debate_title")
	PF.detailed_debate_label			= L(lang, "text", "detailed_debate_label_text")
	PF.arg_is_debate					= L(lang, "text", "arg_is_debate_msg")

	PF.form_edit_sections				= L(lang, "forms", "sections_edit_title")
	PF.edit_sections_tooltip			= L(lang, "text", "sections_edit_tt")

	PF.form_edit_interlanguage			= L(lang, "forms", "interlanguage_edit_title")
	PF.edit_interlanguage_tooltip		= L(lang, "text", "interlanguage_edit_tt")

	PF.edit								= L(lang, "text", "edit_label")
	PF.rename_link_text					= L(lang, "text", "rename_link_label")

	PF.type_justification				= L(lang, "params", "justification_type_label")
	PF.type_objection					= L(lang, "params", "objection_type_label")

	PF.unknown_author					= L( lang, "text", "unknown_author" )
	PF.volume_label					= L( lang, "text", "volume_label" )
	PF.issue_label					= L( lang, "text", "issue_label" )
	PF.more_quotes_button				= L(lang, "text", "more_quotes_button")
	PF.more_quotes_note					= L(lang, "text", "more_quotes_note")

	PF.parent_debates_title_1			= L(lang, "text", "parent_debates_title_1")
	PF.parent_debates_title_n			= L(lang, "text", "parent_debates_title_n")

	PF.cat_arguments					= L(lang, "categories", "arguments")
	PF.cat_no_keywords					= L(lang, "categories", "keywords_missing")
	PF.cat_no_summary					= L(lang, "categories", "summary_missing")
	PF.cat_no_quotes					= L(lang, "categories", "quotes_missing")
	PF.cat_no_references				= L(lang, "categories", "references_missing")
	PF.cat_no_justifications			= L(lang, "categories", "justifications_missing")
	PF.cat_no_objections				= L(lang, "categories", "objections_missing")
	PF.cat_no_sections					= L(lang, "categories", "sections_missing")
	PF.cat_no_content					= L(lang, "categories", "content_missing")
	PF.cat_has_detailed_debate			= L(lang, "categories", "has_detailed_debate")
	PF.cat_content_to_transfer			= L(lang, "categories", "content_to_transfer")
	PF.cat_no_authors					= L(lang, "categories", "authors_missing")

	--	props “contenu”
	PF.prop_arg_name					= L(lang, "props", "arg_page_name")
	PF.prop_arg_number					= L(lang, "props", "arg_number")
	PF.prop_argument_map				= L(lang, "props", "argument_map")
	PF.prop_justif_list					= L(lang, "props", "justif_list")
	PF.prop_obj_list					= L(lang, "props", "obj_list")
	PF.prop_keyword						= L(lang, "props", "keyword")
	PF.prop_detailed_debate				= L(lang, "props", "detailed_debate")

	--	props refs (depuis Références, pas depuis citations)
	PF.prop_author						= L( lang, "props", "author" )
	PF.prop_article_name				= L( lang, "props", "article_name" )
	PF.prop_work_name					= L( lang, "props", "work_name" )
	PF.prop_publishing_house			= L( lang, "props", "publishing_house" )
	PF.prop_place_of_publication		= L( lang, "props", "place_of_publication" )

	--	props “ask/parents/breadcrumb”
	PF.prop_arg_pro						= L(lang, "props", "arg_pro")
	PF.prop_arg_con						= L(lang, "props", "arg_con")
	PF.prop_justif						= L(lang, "props", "justif")
	PF.prop_obj							= L(lang, "props", "obj")
	PF.prop_breadcrumb					= L(lang, "props", "breadcrumb")
	PF.prop_debate_name					= L(lang, "props", "debate_name")
	PF.prop_parent_debate				= L(lang, "props", "parent_debate")
	PF.prop_parent_argument				= L(lang, "props", "parent_argument")
	PF.prop_section						= L(lang, "props", "section")

	--	INITIALIZATION
	PF.cat_initialized					= L(lang, "categories", "initialized")
	PF.cat_uninitialized				= L(lang, "categories", "uninitialized")
	PF.init_warning_title				= L(lang, "text", "init_warning_title")
	PF.init_warning_intro				= L(lang, "text", "init_warning_intro")
	PF.init_yes_finish_label			= L(lang, "text", "init_yes_finish_label")
	PF.init_yes_tt						= L(lang, "text", "init_yes_tt")
	PF.init_ok_text						= L(lang, "text", "init_ok_text")
	PF.init_no_rename_label				= L(lang, "text", "init_no_rename_label")
	PF.init_summary_pro					= L(lang, "text", "init_summary_pro")
	PF.init_summary_con					= L(lang, "text", "init_summary_con")
	PF.init_summary_justif				= L(lang, "text", "init_summary_justif")
	PF.init_summary_obj					= L(lang, "text", "init_summary_obj")

	do
		local q = args["quotes"]
		if type(q) == "string" and q ~= "" then
			local ok, r = pcall(F.preprocess, F, q)
			if ok and type(r) == "string" then
				args["_quotes_wikitext"] = r
				args["quotes"] = wkRenderQuotesFromSerialized( r, pv, lang, cats, PF )
			else
				args["_quotes_wikitext"] = ""
				args["quotes"] = ""
			end
		end
	end

	local hasResume		= args["summary"] ~= nil
	local hasQuotes	= args["quotes"] ~= nil
	local hasRefs		= (args["bibliography"] ~= nil) or (args["webliography"] ~= nil) or (args["videography"] ~= nil)

	if args["established-name"] then
		table_insert(pieces, F:preprocess("{{SHORTDESC: " .. args["established-name"] .. "}}"))
		table_insert(cats, "[[Category:" .. L( lang, "categories", "has_alt_name" ) .. "]]")
	end

	table_insert(cats, "[[Category:" .. PF.cat_arguments .. "]]")

	table_insert(pieces, renderTitleWarnings(pv, cats, lang))

	do
		local titleLookup	= buildBannerLabelLookup( lang, TITLE_WARNING_BANNER_KEYS )
		local argLookup		= buildBannerLabelLookup( lang, ARGUMENT_WARNING_BANNER_KEYS )

		local out = {}

		if args[ "title-warnings" ] then
			table_insert( out, renderBannersFromArgList( args[ "title-warnings" ], titleLookup, pv, lang, cats ) )
		end

		if #out > 0 then
			table_insert( pieces, table_concat( out ) )
		end
	end

	local maps = computeArgumentMapsFromData(args)

	ensureBreadcrumbSelection(pv, cache, cats, lang, PF)

	table_insert(pieces, renderDebateParentsBoxes(pv, cats, lang, cache, PF))
	table_insert(pieces, renderArgumentParentsBoxes(pv, cats, lang, cache, PF))
	table_insert(pieces, renderInitializationBlock(args, pv, cats, lang, cache, PF, maps))

	table_insert(pieces, renderKeywords(args, pv, cats, lang, PF))

	if args[ "argument-warnings" ] then
		local argLookup = buildBannerLabelLookup( lang, ARGUMENT_WARNING_BANNER_KEYS )
		local out = {}

		local btnForm = addDataLink(
			L( lang, "forms", "banners_edit_title" ),
			pv.rawTitle,
			"",
			L( lang, "text", "argument_banners_edit_tt" ),
			{ editButton = true }
		)

		table_insert( out,
			'<h2 id="Summary" class="section-modifiable wk-icon-before wk-icon-flag">'
			.. L( lang, "text", "warnings_title" )
			.. btnForm
			.. '</h2>'
		)

		table_insert(
			out,
			renderBannersFromArgList( args[ "argument-warnings" ], argLookup, pv, lang, cats )
		)

		table_insert( pieces, table_concat( out ) )
	end

	local summaryId = ' id="Summary"'
	
	if args["argument-warnings"] and tostring(args["argument-warnings"]) ~= "" then
		summaryId = ""
	end

	table_insert(pieces, renderSummary(args, pv, cats, lang, PF, summaryId))
	table_insert(pieces, renderQuotes(args, pv, cats, lang, PF))

	local detailed = renderDebateDetailed(args, pv, cats, lang, PF)
	if detailed ~= "" then
		table_insert(pieces, detailed)
	else
		local out = {}

		local editRefs = addDataEditLink(
			PF.form_edit_references,
			pv.rawTitle,
			"&nbsp;",
			PF.edit_references_tooltip
		)

		table_insert(out,
			'<h2 class="section-modifiable wk-icon-before wk-icon-bibliography">'
			.. PF.references
			.. editRefs .. '</h2>'
		)

		do
			local refsHtml = wkRenderReferencesFromRaw( rawB, rawW, rawV, pv, lang, cats, PF, nil, args )

			if refsHtml ~= "" then
				table_insert( out, '<ul class="references-argument">' .. refsHtml .. '</ul>' )
			else
				table_insert( out, '<div class="aucun-contenu navigation-not-searchable">' .. PF.no_references .. '</div>' )
				table_insert( cats, "[[Category:" .. PF.cat_no_references .. "]]" )
			end
		end

		local justifItems = (maps.justif and maps.justif.items) or {}
		local objItems    = (maps.obj and maps.obj.items) or {}

		--	Justifications
		local editJust = addDataEditLink(
			PF.form_edit_justifications,
			pv.rawTitle,
			"&nbsp;",
			PF.edit_justifications_tooltip
		)

		table_insert(out,
			'<h2 class="section-modifiable wk-icon-before wk-icon-argument is-pro">'
			.. PF.justifications
			.. editJust .. '</h2>'
		)

	do
		local raw = args[ "justification-warnings" ]
		raw = ( type( raw ) == "string" ) and t_trim( raw ) or ""

		if raw ~= "" then
			local lookup = buildSummaryLabelLookup( lang, JUSTIFICATION_WARNING_BANNER_KEYS )

			for _, x in ipairs( splitCSV( raw, "," ) ) do
				local key = resolveKeyFromLookup( x, lookup, JUSTIFICATION_WARNING_BANNER_KEYS )
				if key ~= "" then
					table_insert(
						out,
						renderSubsectionBanner(
							pv,
							lang,
							key,
							{
								reorg	= true,
								form	= PF.form_edit_justifications,
								tooltip	= PF.edit_justifications_tooltip
							},
							cats
						)
					)
				end
			end
		end
	end

		if #justifItems > 0 then
			local ulJ = html_create("ul"):addClass("argument-list is-pro")
			local lis = {}
			for i, it in ipairs(justifItems) do
				table_insert(lis, wkRenderArgumentLi(pv, lang, it, "justif", i, cats))
			end
			ulJ:wikitext(table_concat(lis, "\n"))
			table_insert(out, tostring(ulJ))
		else
			table_insert(out, '<div class="aucun-argument navigation-not-searchable">' .. PF.no_justifications .. '</div>')
			table_insert(cats, "[[Category:" .. PF.cat_no_justifications .. "]]")
		end

		do
			local baseField = tostring( PF.arg_new_title_field_base or "" )

			local sideLabel = tostring( PF.side_pro_word or "pour" )
			local label = tostring( PF.justification_add_label or "" )
			local tooltip = string_format( PF.justification_add_tt, pv.rawTitle )

			local rqForm = tostring( PF.form_new_arg_title or "" )

			local rqQuery = {}
			rqQuery[ baseField .. "[type]" ] = tostring( PF.type_justification or "" )
			rqQuery[ baseField .. "[ID]" ]   = tostring( pv.pageId or "" )
			rqQuery[ "_run" ]                = "1"

			table_insert( out,
				'<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">'
				.. wkRunQueryHtmlTagButton( rqForm, label, tooltip, rqQuery )
				.. '</div>'
			)
		end

		--	Objections
		local editObj = addDataEditLink(
			PF.form_edit_objections,
			pv.rawTitle,
			"&nbsp;",
			PF.edit_objections_tooltip
		)

		table_insert(out,
			listBreaker()
			.. '<h2 class="section-modifiable wk-icon-before wk-icon-argument is-con">'
			.. PF.objections
			.. editObj .. '</h2>'
		)

	do
		local raw = args[ "objection-warnings" ]
		raw = ( type( raw ) == "string" ) and t_trim( raw ) or ""

		if raw ~= "" then
			local lookup = buildSummaryLabelLookup( lang, OBJECTION_WARNING_BANNER_KEYS )

			for _, x in ipairs( splitCSV( raw, "," ) ) do
				local key = resolveKeyFromLookup( x, lookup, OBJECTION_WARNING_BANNER_KEYS )
				if key ~= "" then
					table_insert(
						out,
						renderSubsectionBanner(
							pv,
							lang,
							key,
							{
								reorg	= true,
								form	= PF.form_edit_objections,
								tooltip	= PF.edit_objections_tooltip
							},
							cats
						)
					)
				end
			end
		end
	end

		if #objItems > 0 then
			local ulO = html_create("ul"):addClass("argument-list is-con")
			local lis = {}
			for i, it in ipairs(objItems) do
				table_insert(lis, wkRenderArgumentLi(pv, lang, it, "obj", i, cats))
			end
			ulO:wikitext(table_concat(lis, "\n"))
			table_insert(out, tostring(ulO))
		else
			table_insert(out, '<div class="aucun-argument navigation-not-searchable">' .. PF.no_objections .. '</div>')
			table_insert(cats, "[[Category:" .. PF.cat_no_objections .. "]]")
		end

		do
			local baseField = tostring( PF.arg_new_title_field_base or "" )

			local label = tostring( PF.objection_add_label or "" )
			local tooltip = string_format( PF.objection_add_tt, pv.rawTitle )

			local rqForm = tostring( PF.form_new_arg_title or "" )

			local rqQuery = {}
			rqQuery[ baseField .. "[type]" ] = tostring( PF.type_objection or "" )
			rqQuery[ baseField .. "[ID]" ]   = tostring( pv.pageId or "" )
			rqQuery[ "_run" ]                = "1"

			table_insert( out,
				'<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">'
				.. wkRunQueryHtmlTagButton( rqForm, label, tooltip, rqQuery )
				.. '</div>'
			)
		end

		--	Inject rendu
		table_insert(pieces, table_concat(out))
	end

	table_insert(pieces, renderDebatesParentsBottom(pv, lang, cache, PF))

	if args["interlanguage"] then
		table_insert(pieces, args["interlanguage"])
	end

	if not args["creation-date"] or t_trim(args["creation-date"]) == "" then
		table_insert(cats, "[[Category:" .. L(lang, "categories", "creation_date_missing") .. "]]")
	end

	table_insert(pieces, renderRubriques(args, cats, lang, PF))

	if (not hasResume) and (not hasQuotes) and (not hasRefs) then
		table_insert(cats, "[[Category:" .. PF.cat_no_content .. "]]")
	end

	setSemanticData(args, pv, lang, cache, PF, maps, rawB, rawW, rawV)

	renderFullSEO( args, pv, lang )

	table_insert(pieces, table_concat(cats, ""))

	table_insert(pieces,
		'<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[[Special:MovePage/'
		.. pv.encoded .. '| ' .. PF.rename_link_text .. ']]</span>'
	)

	table_insert(pieces, '<span id="bouton-modifier-categories" style="display: none;">'
		.. addDataEditLink(PF.form_edit_sections, pv.rawTitle, PF.edit, PF.edit_sections_tooltip)
		.. '</span>'
	)

	if args["interlanguage"] and t_trim( tostring( args["interlanguage"] or "" ) ) ~= "" then
		table_insert( pieces,
			'<span id="bouton-modifier-interlangue" style="display: none;"'
			.. ' data-wk-tooltip="' .. escapeAttr( PF.edit_interlanguage_tooltip ) .. '">'
			.. addDataEditLink( PF.form_edit_interlanguage, pv.rawTitle, PF.edit, PF.edit_interlanguage_tooltip )
			.. '</span>'
		)
	end

	table_insert( pieces, renderArgumentSchemaJSONLD( args, pv, lang, cache, PF, maps ) )

	return table_concat(pieces, "")
end

return p
