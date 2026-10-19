"""Lexical free variables of generated Lua closures (captures remain by reference)."""
from luaparser import astnodes as n


def free_variables(function):
	free = set()

	def visit(node, bound):
		if isinstance(node, n.Name):
			if node.id not in bound:
				free.add(node.id)
		elif isinstance(node, n.Block):
			local = set(bound)
			for statement in node.body:
				if isinstance(statement, n.LocalAssign):
					visit(statement.values, local)
					local.update(target.id for target in statement.targets)
				elif isinstance(statement, n.LocalFunction):
					local.add(statement.name.id)
					visit(statement, local)
				else:
					visit(statement, local)
		elif isinstance(node, (n.AnonymousFunction, n.LocalFunction, n.Function)):
			visit(node.body, bound | {arg.id for arg in node.args if isinstance(arg, n.Name)})
		elif isinstance(node, (n.Forin, n.Fornum)):
			if isinstance(node, n.Forin):
				visit(node.iter, bound)
				targets = node.targets
			else:
				visit([node.start, node.stop, node.step], bound)
				targets = [node.target]
			visit(node.body, bound | {target.id for target in targets})
		elif isinstance(node, n.Index):
			visit(node.value, bound)
			if node.notation != n.IndexNotation.DOT:
				visit(node.idx, bound)
		elif isinstance(node, n.Field):
			visit(node.value, bound)
			if node.between_brackets:
				visit(node.key, bound)
		elif isinstance(node, n.Invoke):
			visit(node.source, bound)
			visit(node.args, bound)
		elif isinstance(node, n.Node):
			for key, value in vars(node).items():
				if key != 'comments' and not key.startswith('_'):
					visit(value, bound)
		elif isinstance(node, list):
			for value in node:
				visit(value, bound)

	visit(function, set())
	return free
