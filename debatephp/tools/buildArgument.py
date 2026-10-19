from luaparser import ast,astnodes as n
from pathlib import Path
import json
from phpConditions import condition, is_boolean
from closureCaptures import free_variables
root = Path(__file__).resolve().parent.parent
source=(root/'resources/Module-Argument.reference.lua').read_text()
x=ast.parse(source)
funcs={v.name.id:v for v in x.body.body if isinstance(v,n.LocalFunction)}
for v in x.body.body:
	if isinstance(v,n.Assign) and v.values and isinstance(v.targets[0],n.Name) and isinstance(v.values[0],n.AnonymousFunction):
		funcs[v.targets[0].id]=v.values[0]
funcs['render']=next(v for v in x.body.body if isinstance(v,n.Function))
skip={'vset','vget','hasSMW','smwSetSafe','smwAskSafe','wkSeoHas','wkSeoSet','detectLang','L','getArgs','computePageVars','wkDbKey','titleNewCached','safeJsonEncode','absUrlFromTitle','seoKeyPart','expandCached'}
props={}
for v in x.body.body:
	if isinstance(v,n.LocalAssign) and v.targets[0].id.isupper() and v.targets[0].id not in ['F','V','WD_I18N']:
		props[v.targets[0].id]=v.values[0] if v.values else n.Nil()
seq=0
scope=[]
def quote(s):
	return "'"+s.replace('\\','\\\\').replace("'","\\'")+"'"
def e(v,multi=False):
	global seq
	if isinstance(v,n.Name):
		if v.id in props:return '$this->'+v.id
		if v.id=='F':return '$this->context'
		if v.id=='mw':return 'true'
		native={'t_jsonEncode':'jsonEncode','mw_uri_fullUrl':'fullUrl','t_nowiki':'nowiki'}
		if v.id in native:return '[$this->context, '+quote(native[v.id])+']'
		if v.id in funcs:return "[$this, "+quote(v.id)+"]"
		if v.id=='wkParseBiblioMarkers' or v.id=='wkParseWeblioMarkers' or v.id=='wkParseVideoMarkers':return "[$this, "+quote(v.id)+"]"
		return '$'+v.id
	if isinstance(v,n.String):return quote(v.s.decode('utf8'))
	if isinstance(v,n.Number):return str(v.n)
	if isinstance(v,n.Nil):return 'null'
	if isinstance(v,n.TrueExpr):return 'true'
	if isinstance(v,n.FalseExpr):return 'false'
	if isinstance(v,n.Dots):return '...$rest'
	if isinstance(v,n.Index):
		path=ast.to_lua_source(v)
		if path=='F.preprocess':return 'function($_frame, $text) { return $this->context->preprocess($text); }'
		if path=='mw.smw':return '$this->context->smwAvailable()'
		if path=='mw.smw.ask':return '($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)'
		key=quote(v.idx.id) if v.notation==n.IndexNotation.DOT else e(v.idx)
		return e(v.value)+'['+key+']'
	if isinstance(v,n.Table):
		i=0;fields=[]
		for f in v.fields:
			if f.key is None:i+=1;key=str(i)
			elif isinstance(f.key,n.Name) and not f.between_brackets:key=quote(f.key.id)
			else:key=e(f.key)
			fields.append(key+' => '+e(f.value))
		return 'new Table(['+', '.join(fields)+'])'
	if isinstance(v,n.Concat):
		def parts(z): return parts(z.left)+parts(z.right) if isinstance(z,n.Concat) else [e(z)]
		return '('+' . '.join(parts(v))+')'
	if isinstance(v,(n.OrLoOp,n.AndLoOp)):
		if is_boolean(v):return condition(v,e)
		seq+=1;t='$v'+str(seq)
		# assignment evaluated once; Lua false/nil semantics, including empty strings/tables
		return '(Lua::truth('+t+' = '+e(v.left)+') ? '+(t+' : '+e(v.right) if isinstance(v,n.OrLoOp) else e(v.right)+' : '+t)+')'
	ops={n.LessThanOp:'<',n.GreaterOrEqThanOp:'>=',n.Concat:'.',n.AddOp:'+',n.SubOp:'-',n.EqToOp:'===',n.NotEqToOp:'!==',n.GreaterThanOp:'>',n.LessOrEqThanOp:'<='}
	for cls,op in ops.items():
		if isinstance(v,cls):return '('+e(v.left)+' '+op+' '+e(v.right)+')'
	if isinstance(v,n.UMinusOp):return '(-'+e(v.operand)+')'
	if isinstance(v,n.ULengthOP):return 'Lua::len('+e(v.operand)+')'
	if isinstance(v,n.ULNotOp):return condition(v,e)
	if isinstance(v,n.Invoke):
		name=v.func.id;args=[e(a) for a in v.args]
		if name in ['find','sub','gsub','match','byte']:
			return 'Lua::'+name+'('+', '.join([e(v.source)]+args)+')'
		return '('+e(v.source)+')->'+name+'('+', '.join(args)+')'
	if isinstance(v,n.Call):
		a=[e(z) for z in v.args];f=v.func
		if isinstance(f,n.Index):
			path=ast.to_lua_source(f)
			direct={'string.format':'Lua::format','math.min':'min','mw.smw.ask':'$this->context->smwAsk'}
			if path in direct:return direct[path]+'('+', '.join(a)+')'
		if isinstance(f,n.Name):
			name=f.id
			bridge={'pcall':'Lua::pcall','table_remove':'Lua::remove','mw_uri_encode':'$this->context->uriEncode','t_trim':'Lua::trim','tostring':'Lua::str','type':'Lua::type','table_concat':'Lua::concat','table_insert':'Lua::insert','table_sort':'Lua::sort','t_gsplit':'Lua::split','gsub':'Lua::gsub','tonumber':'Lua::number','string_format':'Lua::format','t_nowiki':'$this->context->nowiki','t_jsonEncode':'$this->context->jsonEncode','uri_encode':'$this->context->uriEncode','uri_fullUrl':'$this->context->fullUrl','uri_localUrl':'$this->context->localUrl','html_create':'HtmlBuilder::create'}
			if name in bridge:return bridge[name]+'('+', '.join(a)+')'
			if name in funcs:return '$this->'+name+'('+', '.join(a)+')'
			return '$'+name+'('+', '.join(a)+')'
		if isinstance(f,n.Index) and isinstance(f.value,n.Name) and f.value.id=='WD_I18N':return '$this->context->message('+', '.join(a)+')'
		raise Exception('call '+ast.to_pretty_str(f))
	if isinstance(v,n.AnonymousFunction):
	# Capture locals by reference as in Lua lexical closures; PHP arguments remain local.
		names=scope[-1] if scope else []
		params=[z.id for z in v.args if isinstance(z,n.Name)]
		caps=[z for z in names if z in free_variables(v)]
		use=(' use ('+', '.join('&$'+z for z in caps)+')') if caps else ''
		scope.append(list(dict.fromkeys(names+params+namesin(v.body))))
		body=block(v.body,2)
		scope.pop()
		return 'function('+', '.join('$'+z+' = null' for z in params)+')'+use+' {\n'+body+'\n\t\t}'
	raise Exception('EXPR '+type(v).__name__)
def namesin(v):
	out=[]
	def walk(v):
		if isinstance(v,(n.LocalAssign,n.Assign)):
			for t in v.targets:
				if isinstance(t,n.Name) and t.id not in out:out.append(t.id)
		if isinstance(v,n.LocalFunction) and v.name.id not in out:out.append(v.name.id)
		if isinstance(v,(n.Fornum,n.Forin)):
			for t in ([v.target] if isinstance(v,n.Fornum) else v.targets):
				if t.id not in out:out.append(t.id)
		if isinstance(v,n.Node):
			for k,z in vars(v).items():
				if k not in ['comments'] and not k.startswith('_'):walk(z)
		elif isinstance(v,list):
			for z in v:walk(z)
	walk(v);return out
def block(b,indent=2):
	lines=[]
	def add(s):lines.append('\t'*indent+s)
	for v in b.body:
		if isinstance(v,(n.LocalAssign,n.Assign)):
			if len(v.targets)>1 and len(v.values)==1:
				add('['+', '.join(e(t) for t in v.targets)+'] = '+e(v.values[0],True)+';')
			else:
			# Simultaneous multiple assignment: list unpack prevents alias overwrite.
				if len(v.targets)>1:add('['+', '.join(e(t) for t in v.targets)+'] = ['+', '.join(e(z) for z in v.values)+'];')
				else:add(e(v.targets[0])+' = '+(e(v.values[0]) if v.values else 'null')+';')
		elif isinstance(v,n.Return):
			add('return '+(('['+', '.join(e(z) for z in v.values)+']') if len(v.values)>1 else e(v.values[0]) if v.values else 'null')+';')
		elif isinstance(v,(n.Call,n.Invoke)):add(e(v)+';')
		elif isinstance(v,(n.If,n.ElseIf)):
			add('if ('+condition(v.test,e)+') {');lines.append(block(v.body,indent+1));add('}')
			o=v.orelse
			if isinstance(o,n.ElseIf):
				add('else {');lines.append(block(n.Block(body=[o]),indent+1));add('}')
			elif o is not None:add('else {');lines.append(block(o,indent+1));add('}')
		elif isinstance(v,n.Do):
			add('{');lines.append(block(v.body,indent+1));add('}')
		elif isinstance(v,n.Forin):
			it=v.iter[0]
			if isinstance(it,n.Call) and isinstance(it.func,n.Name) and it.func.id in ['pairs','ipairs']:
				val=e(it.args[0]); val='Lua::'+it.func.id+'('+val+')'
				targ=('$'+v.targets[0].id+' => $'+v.targets[1].id) if len(v.targets)>1 else '$'+v.targets[0].id+' => $_value'
			else:
				val=e(it);targ='$'+v.targets[0].id
			add('foreach ('+val+' as '+targ+') {');lines.append(block(v.body,indent+1));add('}')
		elif isinstance(v,n.Fornum):
			t=e(v.target);seqname='$stop'+str(len(lines));add(seqname+' = '+e(v.stop)+';')
			add('for ('+t+' = '+e(v.start)+'; '+t+' <= '+seqname+'; '+t+'++) {');lines.append(block(v.body,indent+1));add('}')
		elif isinstance(v,n.LocalFunction):
			fun=n.AnonymousFunction(args=v.args,body=v.body);add('$'+v.name.id+' = '+e(fun)+';')
		elif isinstance(v,n.While):
			add('while ('+condition(v.test,e)+') {');lines.append(block(v.body,indent+1));add('}')
		elif isinstance(v,n.Break):add('break;')
		else:raise Exception('STMT '+type(v).__name__)
	return '\n'.join(lines)
header='''<?php
namespace MediaWiki\\Extension\\DebatePHP;

/** Native PHP port of the supplied Module:Argument. See docs/argument.md.
 * No Lua interpreter is used. Table preserves reference and 1-based semantics.
 * Generated mechanically, then checked against the reference source.
 */
class ArgumentReferenceRenderer {
\tprotected ArgumentContext $context;
'''
for k in props:header+='\tprotected $'+k+';\n'
header+='\tpublic function __construct(ArgumentContext $context) {\n\t\t$this->context = $context;\n'
for k,v in props.items():header+='\t\t$this->'+k+' = '+e(v)+';\n'
header+='\t}\n'
for name,v in funcs.items():
	if name in skip:continue
	params=[z.id for z in v.args if isinstance(z,n.Name)]
	scope.append(list(dict.fromkeys(params+namesin(v.body))))
	args=', '.join('$'+z+' = null' for z in params)
	if any(isinstance(z,n.Varargs) for z in v.args):args+=(', ' if args else '')+'...$rest'
	body=block(v.body)
	scope.pop()
	header+='\n\t'+('public' if name=='render' else 'protected')+' function '+name+'('+args+') {\n'+body+'\n\t}\n'
header+='''
\tprotected function hasSMW() { return $this->context->smwAvailable(); }
\tprotected function wkSeoHas() { return true; }
\tprotected function vset($name, $value) { $this->context->variableSet($name, Lua::str($value ?? '')); }
\tprotected function vget($name) { return $this->context->variableGet($name); }
\tprotected function titleNewCached($text) { return $this->context->titleInfo($text); }
\tprotected function safeJsonEncode($data) { return $this->context->jsonEncode($data); }
\tprotected function seoKeyPart($text) { return md5(Lua::str($text ?? '')); }
\tprotected function absUrlFromTitle($title, $query) { return $this->context->fullUrl($title, $query) ?? $this->context->localUrl($title, $query) ?? ''; }
\tprotected function smwAskSafe($query) {
\t\tif (!$this->hasSMW()) { return null; }
\t\t$key = $this->smwKeyFromArray($query);
\t\t$hit = $this->cacheGet($this->SMW_ASK_CACHE, $key);
\t\tif ($hit !== null) { return $hit === false ? null : $hit; }
\t\t$result = $this->context->smwAsk($query);
\t\t$this->cachePut($this->SMW_ASK_CACHE, $key, $result ?? false);
\t\treturn $result;
\t}
\tprotected function getArgs($frame = null) { return $this->context->arguments(); }
\tprotected function detectLang($args = null) { return $this->context->language($args); }
\tprotected function L($lang = null, $section = null, $key = null, ...$rest) { return $this->context->message('Argument.' . $section, $lang, $key, ...$rest); }
\tprotected function computePageVars() { return $this->context->pageVars(); }
\tprotected function wkDbKey($page = null) { return $this->context->dbKey($page); }
\tprotected function wkSeoSet($data = null) { $this->context->seoSet($data); }
\tprotected function smwSetSafe($data = null) { $this->context->smwSet($data); }
}
'''
(root/'src/ArgumentReferenceRenderer.php').write_text(header)
print('Wrote',len(header),'bytes',len(funcs)-len(skip),'functions')

exec((root / "tools/applyArgumentNative.py").read_text(), {"__file__": str(root / "tools/applyArgumentNative.py")})
