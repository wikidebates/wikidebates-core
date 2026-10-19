"""PHP boolean contexts for Lua ASTs, without temporary operand-value variables.

Lua and/or return operands in value contexts. In if/while/not contexts only their
truth matters, so PHP short-circuit operators are exact once leaves use Lua truth.
"""
from luaparser import astnodes as n

BOOLEAN = (n.TrueExpr, n.FalseExpr, n.ULNotOp, n.EqToOp, n.NotEqToOp,
	n.LessThanOp, n.GreaterThanOp, n.LessOrEqThanOp, n.GreaterOrEqThanOp)

def is_boolean(node):
	if isinstance(node, BOOLEAN):
		return True
	return isinstance(node, (n.AndLoOp, n.OrLoOp)) and is_boolean(node.left) and is_boolean(node.right)

def condition(node, expression):
	if isinstance(node, n.AndLoOp):
		return '(' + condition(node.left, expression) + ' && ' + condition(node.right, expression) + ')'
	if isinstance(node, n.OrLoOp):
		return '(' + condition(node.left, expression) + ' || ' + condition(node.right, expression) + ')'
	if isinstance(node, n.ULNotOp):
		return '!' + '(' + condition(node.operand, expression) + ')'
	if isinstance(node, (n.FalseExpr, n.Nil)):
		return 'false'
	if isinstance(node, (n.TrueExpr, n.Number, n.String)):
		return 'true'
	value = expression(node)
	return value if is_boolean(node) else 'Lua::truth(' + value + ')'
