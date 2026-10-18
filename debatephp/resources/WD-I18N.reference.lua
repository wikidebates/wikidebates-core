-- Module:WD/I18N

local p = {}

local DATA = mw.loadData('Module:WD/I18N/Data')

local function trim(s)
	if type(s) ~= "string" then
		return s
	end
	return s:match("^%s*(.-)%s*$")
end

local function normalizeLang(code)
	code = trim(code or "")
	if code == "" then
		return ""
	end
	local base = code:match("^([a-zA-Z]+)") or code
	return mw.ustring.lower(base)
end

function p.getLangFromArgs(args)
	local param = normalizeLang(args and (args.lang or args.language) or "")
	if param ~= "" then
		if param:match("^fr") then return "fr" end
		return "en"
	end
	local contentLang = ""
	if mw.getContentLanguage then
		local obj = mw.getContentLanguage()
		if obj and obj.getCode then
			contentLang = normalizeLang(obj:getCode())
		end
	end
	if contentLang:match("^fr") then
		return "fr"
	end
	return "en"
end

local function getByPath(root, path)
	local cur = root
	for part in tostring(path or ""):gmatch("[^%.]+") do
		if type(cur) ~= "table" then
			return nil
		end
		cur = cur[part]
	end
	return cur
end

--	Cache locaux (par parse)
local DOMAIN_CACHE = {}
local ALIAS_CACHE = {}

local function resolveAlias(value, depth)
	if type(value) ~= "string" then
		return value
	end
	if value:sub(1, 1) ~= "@" then
		return value
	end

	local cached = ALIAS_CACHE[value]
	if cached ~= nil then
		return cached
	end

	depth = depth or 0
	if depth > 12 then
		ALIAS_CACHE[value] = value
		return value
	end

	local ref = value:sub(2)
	local d, k = ref:match("^([^%.]+)%.(.+)$")
	if not d or not k then
		ALIAS_CACHE[value] = value
		return value
	end

	local dom = DATA[d]
	if type(dom) ~= "table" then
		ALIAS_CACHE[value] = value
		return value
	end

	local entry = getByPath(dom, k)
	if entry == nil then
		ALIAS_CACHE[value] = value
		return value
	end

	local resolved = resolveAlias(entry, depth + 1)
	ALIAS_CACHE[value] = resolved
	return resolved
end

local function pickText(entry, lang)
	if type(entry) == "string" then
		return entry
	end
	if type(entry) ~= "table" then
		return nil
	end
	return entry[lang] or entry.en or entry.fr
end

function p.msg(domain, lang, key, ...)
	lang = normalizeLang(lang or "en")
	if lang:match("^fr") then lang = "fr" else lang = "en" end

	local dom = DOMAIN_CACHE[domain]
	if dom == nil then
		dom = getByPath(DATA, domain)
		if dom == nil then
			DOMAIN_CACHE[domain] = false
		else
			DOMAIN_CACHE[domain] = dom
		end
	end
	if dom == false then
		dom = nil
	end

	local entry = (type(dom) == "table") and dom[key] or nil

	if entry == nil and DATA.Common then
		entry = DATA.Common[key]
	end

	entry = resolveAlias(entry)

	local text = pickText(entry, lang) or key

	if select("#", ...) > 0 then
		return string.format(text, ...)
	end
	return text
end

return p