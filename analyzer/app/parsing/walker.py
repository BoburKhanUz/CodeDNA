"""Single-pass, iterative extraction of IR 1.1 structure from a syntax tree.

The walk uses a TreeCursor and explicit stacks, never recursion, so a tree
of any depth (e.g. 50,000 nested parentheses) cannot exhaust the Python
stack. It visits every node once and records only structure: positions,
counts, kinds, sanitized names (app.parsing.text). It never returns or
stores source text, comment text or literal values.

Cyclomatic complexity (McCabe) of a function = 1 + its decision points
(see app.parsing.specs for what counts per language). Decision points and
nesting inside a nested function, lambda or closure belong to that nested
function only. Code outside any function is not attributed to a function.
"""

from dataclasses import dataclass, field
from typing import Any

from tree_sitter import Node, Tree

from app.deadline import Deadline
from app.parsing.specs import LOGICAL_OPERATORS, LanguageSpec
from app.parsing.text import keyword_text

# How often (in nodes) the walk checks the run deadline.
DEADLINE_CHECK_INTERVAL = 32_768

_SCOPE = 1
_NEST = 2

_CODE = 1
_COMMENT = 2

_ACCESS_LABELS = frozenset({"public", "protected", "private"})

# A parameters "list" that is in fact one parameter (x => x, q => q + 1).
_SINGLE_PARAMETER_TYPES = frozenset({"identifier", "implicit_parameter"})


@dataclass
class _Function:
    record: dict[str, Any]
    decisions: int = 0
    nesting: int = 0
    max_nesting: int = 0


@dataclass
class _Type:
    index: int
    record: dict[str, Any]
    access: str | None = None


@dataclass
class _Container:
    """A method scope that is not a type (a Rust impl block)."""


_Scope = _Function | _Type | _Container


@dataclass
class WalkResult:
    ast_nodes: int
    ast_max_depth: int
    error_nodes: int
    first_error: tuple[int, int] | None
    lines: dict[str, int]
    imports: list[dict[str, Any]] = field(default_factory=list)
    types: list[dict[str, Any]] = field(default_factory=list)
    functions: list[dict[str, Any]] = field(default_factory=list)

    def structure(self) -> dict[str, Any]:
        return {"lines": self.lines, "imports": self.imports, "types": self.types, "functions": self.functions}


def walk(tree: Tree, source: bytes, spec: LanguageSpec, total_lines: int, deadline: Deadline) -> WalkResult:
    walker = _Walker(source, spec, total_lines, deadline)
    walker.run(tree)
    return walker.result()


class _Walker:
    def __init__(self, source: bytes, spec: LanguageSpec, total_lines: int, deadline: Deadline) -> None:
        self.source = source
        self.spec = spec
        self.total_lines = total_lines
        self.deadline = deadline
        self.line_flags = bytearray(total_lines)
        self.node_types: list[str] = []
        self.flags: list[int] = []
        self.scopes: list[_Scope] = []
        self.functions: list[_Function] = []
        self.imports: list[dict[str, Any]] = []
        self.types: list[dict[str, Any]] = []
        self.function_records: list[dict[str, Any]] = []
        self.visited = 0
        self.max_depth = 0
        self.error_nodes = 0
        self.first_error: tuple[int, int] | None = None
        self.type_node_types = spec.type_node_types

    # -- traversal ---------------------------------------------------------

    def run(self, tree: Tree) -> None:
        cursor = tree.walk()
        descend = self.enter(cursor.node, None)
        while True:
            if descend and cursor.goto_first_child():
                descend = self.enter(cursor.node, cursor.field_name)
                continue
            while True:
                self.leave()
                if cursor.goto_next_sibling():
                    descend = self.enter(cursor.node, cursor.field_name)
                    break
                if not cursor.goto_parent():
                    return

    def enter(self, node: Node | None, field_name: str | None) -> bool:
        assert node is not None
        self.visited += 1
        if self.visited % DEADLINE_CHECK_INTERVAL == 0:
            self.deadline.check()
        depth = len(self.flags) + 1
        if depth > self.max_depth:
            self.max_depth = depth

        node_type = node.type
        if node.is_error or node.is_missing:
            self.error_nodes += 1
            if self.first_error is None:
                row, column = node.start_point
                self.first_error = (row + 1, column)

        spec = self.spec
        flags = 0
        descend = True
        if node_type in spec.comment_types:
            self.mark_lines(node, _COMMENT)
            descend = False
        elif node.child_count == 0:
            self.mark_lines(node, _CODE)

        # Keyword tokens can share a node type's name (TypeScript "class",
        # Python "lambda"); only named nodes are syntax constructs.
        if node.is_named:
            flags = self.construct(node, node_type, field_name)

        if spec.access_labels and node_type == "access_specifier":
            self.access_label(node)

        self.node_types.append(node_type)
        self.flags.append(flags)
        return descend

    def construct(self, node: Node, node_type: str, field_name: str | None) -> int:
        """Records imports, declarations, decisions and nesting; returns the node's stack flags."""
        spec = self.spec
        flags = 0
        if node_type in spec.import_types:
            line = node.start_point[0] + 1
            self.imports.extend({"line": line, "target": target} for target in spec.imports(node, self.source))

        if node_type in self.type_node_types and self.open_type(node):
            flags |= _SCOPE
        elif node_type in spec.method_scopes:
            self.scopes.append(_Container())
            flags |= _SCOPE
        elif node_type in spec.function_types and node.child_by_field_name("body") is not None:
            self.open_function(node)
            flags |= _SCOPE
        elif self.functions:
            function = self.functions[-1]
            function.decisions += self.decisions(node, node_type)
            if node_type in spec.nesting_types and not self.is_else_if(node_type, field_name):
                function.nesting += 1
                function.max_nesting = max(function.max_nesting, function.nesting)
                flags |= _NEST
        return flags

    def access_label(self, node: Node) -> None:
        """C++ "public:"-style labels set the access of the members that follow them."""
        scope = self.scopes[-1] if self.scopes else None
        if isinstance(scope, _Type) and self.node_types and self.node_types[-1] == "field_declaration_list":
            label = keyword_text(self.source, node)
            scope.access = label if label in _ACCESS_LABELS else None

    def leave(self) -> None:
        self.node_types.pop()
        flags = self.flags.pop()
        if flags & _NEST:
            self.functions[-1].nesting -= 1
        if flags & _SCOPE:
            scope = self.scopes.pop()
            if isinstance(scope, _Function):
                self.functions.pop()
                scope.record["complexity"] = 1 + scope.decisions
                scope.record["max_nesting"] = scope.max_nesting

    # -- declarations ------------------------------------------------------

    def open_type(self, node: Node) -> bool:
        spec = self.spec
        kind = spec.kind_of_type(node)
        if kind is None or (spec.types_need_body and node.child_by_field_name("body") is None):
            return False
        name = spec.type_name(node, self.source)
        record: dict[str, Any] = {
            "kind": kind,
            "name": name,
            "line": node.start_point[0] + 1,
            "column": node.start_point[1],
            "end_line": node.end_point[0] + 1,
            "visibility": self.visibility(node, name),
            "bases": spec.bases(node, self.source) if spec.supports_bases else None,
            "methods": 0 if spec.lexical_methods else None,
        }
        self.scopes.append(_Type(len(self.types), record))
        self.types.append(record)
        return True

    def open_function(self, node: Node) -> None:
        spec = self.spec
        parent = self.scopes[-1] if self.scopes else None
        node_type = node.type
        if node_type in spec.anonymous_types:
            kind = "anonymous"
        elif node_type in spec.method_types or isinstance(parent, _Type | _Container):
            kind = "method"
        else:
            kind = "function"

        parent_type = parent.index if kind == "method" and isinstance(parent, _Type) else None
        if parent_type is not None and isinstance(parent, _Type) and parent.record["methods"] is not None:
            parent.record["methods"] += 1

        name = None if kind == "anonymous" else spec.function_name(node, self.source)
        record: dict[str, Any] = {
            "kind": kind,
            "name": name,
            "line": node.start_point[0] + 1,
            "column": node.start_point[1],
            "end_line": node.end_point[0] + 1,
            "visibility": None if kind == "anonymous" else self.visibility(node, name),
            "parameters": self.parameter_count(node, kind),
            "parent_type": parent_type,
            "complexity": 1,
            "max_nesting": 0,
        }
        self.function_records.append(record)
        function = _Function(record)
        self.functions.append(function)
        self.scopes.append(function)

    def visibility(self, node: Node, name: str | None) -> str | None:
        spec = self.spec
        if not spec.supports_visibility:
            return None
        if spec.access_labels:
            scope = self.scopes[-1] if self.scopes else None
            direct_member = bool(self.node_types) and (
                self.node_types[-1] == "field_declaration_list"
                or (
                    self.node_types[-1] == "template_declaration"
                    and len(self.node_types) > 1
                    and self.node_types[-2] == "field_declaration_list"
                )
            )
            return scope.access if isinstance(scope, _Type) and direct_member else None
        return spec.visibility(node, self.source, name)

    def parameter_count(self, node: Node, kind: str) -> int:
        spec = self.spec
        parameters = spec.parameters(node)
        if parameters is None:
            return 0
        if parameters.type in _SINGLE_PARAMETER_TYPES:
            return 1
        count = 0
        first = True
        named = [child for child in parameters.children if child.is_named and child.type not in spec.comment_types]
        for child in parameters.children:
            if not child.is_named:
                # C#: "params T[] name" is not wrapped in a parameter node.
                if spec.language == "csharp" and child.type == "params":
                    count += 1
                continue
            child_type = child.type
            if child_type in spec.comment_types or child_type in spec.non_parameters:
                continue
            is_first, first = first, False
            if spec.language == "csharp" and child_type != "parameter":
                continue
            if spec.language == "go" and child_type == "parameter_declaration":
                count += max(len(child.children_by_field_name("name")), 1)
                continue
            if spec.language in ("c", "cpp") and len(named) == 1 and self.is_void_parameter(child):
                continue
            if spec.language == "typescript" and child_type == "required_parameter":
                pattern = child.child_by_field_name("pattern")
                if pattern is not None and pattern.type == "this":
                    continue
            if is_first and kind == "method" and child_type == "identifier" and self.is_implicit_receiver(child):
                continue
            count += 1
        return count

    def is_implicit_receiver(self, node: Node) -> bool:
        receivers = self.spec.implicit_receivers
        return bool(receivers) and self.source[node.start_byte : node.end_byte].decode("utf-8", "replace") in receivers

    def is_void_parameter(self, node: Node) -> bool:
        if node.type != "parameter_declaration" or node.child_by_field_name("declarator") is not None:
            return False
        parameter_type = node.child_by_field_name("type")
        return (
            parameter_type is not None and parameter_type.type == "primitive_type" and keyword_text(self.source, parameter_type) == "void"
        )

    # -- control flow ------------------------------------------------------

    def decisions(self, node: Node, node_type: str) -> int:
        spec = self.spec
        count = 0
        if node_type in spec.decision_types:
            count += 1
        elif node_type in spec.case_label_types:
            count += sum(1 for child in node.children if child.type == "case")
        elif node_type in spec.logical_types:
            operator = node.child_by_field_name("operator")
            if operator is not None and operator.type in LOGICAL_OPERATORS:
                count += 1
        arm_type = spec.arm_containers.get(node_type)
        if arm_type is not None:
            body = node.child_by_field_name("body") or node
            arms = sum(1 for child in body.named_children if child.type == arm_type)
            count += max(arms - 1, 0)
        return count

    def is_else_if(self, node_type: str, field_name: str | None) -> bool:
        if node_type not in self.spec.if_types:
            return False
        if field_name == "alternative":
            return True
        return bool(self.node_types) and self.node_types[-1] in self.spec.else_types

    # -- lines -------------------------------------------------------------

    def mark_lines(self, node: Node, flag: int) -> None:
        if node.end_byte <= node.start_byte or self.total_lines == 0:
            return
        start_row = node.start_point[0]
        end_row, end_column = node.end_point
        if end_column == 0 and end_row > start_row:
            end_row -= 1
        last = min(end_row, self.total_lines - 1)
        line_flags = self.line_flags
        for row in range(start_row, last + 1):
            line_flags[row] |= flag

    def result(self) -> WalkResult:
        code = comment = 0
        for value in self.line_flags:
            if value & _CODE:
                code += 1
            elif value & _COMMENT:
                comment += 1
        return WalkResult(
            ast_nodes=self.visited,
            ast_max_depth=self.max_depth,
            error_nodes=self.error_nodes,
            first_error=self.first_error,
            lines={"total": self.total_lines, "code": code, "comment": comment, "blank": self.total_lines - code - comment},
            imports=self.imports,
            types=self.types,
            functions=self.function_records,
        )
