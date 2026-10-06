"""Per-language adapters: how each grammar's node types map onto IR 1.1.

A LanguageSpec is pure data plus a few small hooks. The walker
(app.parsing.walker) is language-independent; everything it knows about a
grammar comes from here. Node type names were taken from the pinned grammar
versions (app.parsing.grammars) and are exercised by the per-language
fixtures in tests/fixtures/parsing.

Mapping rules (docs/architecture/analyzer.md, "IR 1.1"):

- A *function* is a function-like node that has a body. Declarations
  without a body (interface/abstract methods, C prototypes, Rust trait
  signatures) are not functions.
- Its kind is ``method`` when the node type is always a method
  (``method_types``) or it is declared directly inside a type or a method
  scope such as a Rust ``impl`` block; ``anonymous`` for lambdas, closures
  and function expressions; otherwise ``function``.
- A *type* is a class-like declaration (``type_kinds``); for C/C++ only
  specifiers with a body count (``struct S;`` declares nothing new).
- Decision points for cyclomatic complexity: ``decision_types`` count 1;
  ``case_label_types`` count 1 when labelled ``case`` (not ``default``);
  ``arm_containers`` add (arms - 1) for pattern matches whose fallback is a
  wildcard pattern; ``logical_types`` count 1 when their operator is a
  short-circuit ``&&``/``||``/``and``/``or``.
- Nesting: ``nesting_types`` increase the nesting level of their contents,
  except an ``if`` that is the else-branch of another ``if`` (``else if``).
"""

from collections.abc import Callable, Mapping
from dataclasses import dataclass, field

from tree_sitter import Node

from app.parsing.text import keyword_text, name_text, target_text

LOGICAL_OPERATORS = frozenset({"&&", "||", "and", "or"})

COMMENT_TYPES = frozenset({"comment", "line_comment", "block_comment"})

VISIBILITY_KEYWORDS = ("public", "protected", "private", "internal")

# Hook signatures. They receive the node and the file's bytes and must not
# return source text other than through app.parsing.text.
NameHook = Callable[[Node, bytes], str | None]
ImportHook = Callable[[Node, bytes], list[str | None]]
BasesHook = Callable[[Node, bytes], list[str | None]]
VisibilityHook = Callable[[Node, bytes, str | None], str | None]
TypeKindHook = Callable[[Node], str | None]
ParametersHook = Callable[[Node], Node | None]


def _field_name(node: Node, source: bytes) -> str | None:
    return name_text(source, node.child_by_field_name("name"))


def _parameters_field(node: Node) -> Node | None:
    return node.child_by_field_name("parameters") or node.child_by_field_name("parameter")


def _no_visibility(node: Node, source: bytes, name: str | None) -> str | None:
    return None


def _no_bases(node: Node, source: bytes) -> list[str | None]:
    return []


def _kind_from_table(table: Mapping[str, str]) -> TypeKindHook:
    def kind(node: Node) -> str | None:
        return table.get(node.type)

    return kind


@dataclass(frozen=True)
class LanguageSpec:
    language: str
    grammar: str
    function_types: frozenset[str]
    method_types: frozenset[str]
    anonymous_types: frozenset[str]
    type_kinds: Mapping[str, str]
    decision_types: frozenset[str]
    nesting_types: frozenset[str]
    if_types: frozenset[str]
    import_types: frozenset[str]
    imports: ImportHook
    else_types: frozenset[str] = frozenset({"else_clause"})
    method_scopes: frozenset[str] = frozenset()
    case_label_types: frozenset[str] = frozenset()
    arm_containers: Mapping[str, str] = field(default_factory=dict)
    logical_types: frozenset[str] = frozenset({"binary_expression"})
    comment_types: frozenset[str] = COMMENT_TYPES
    # Parameter-list children that are not parameters.
    non_parameters: frozenset[str] = frozenset()
    # Names of an implicit receiver written as a first parameter (Python self/cls).
    implicit_receivers: frozenset[str] = frozenset()
    # Types with a body (C/C++ specifiers) are required for these node types.
    types_need_body: bool = False
    # Whether methods are declared lexically inside their type, so that a
    # type's method count is meaningful (false for Go and Rust).
    lexical_methods: bool = True
    supports_bases: bool = True
    supports_visibility: bool = True
    # C++: members take their visibility from the preceding access label.
    access_labels: bool = False
    type_kind: TypeKindHook | None = None
    function_name: NameHook = _field_name
    type_name: NameHook = _field_name
    parameters: ParametersHook = _parameters_field
    bases: BasesHook = _no_bases
    visibility: VisibilityHook = _no_visibility

    def kind_of_type(self, node: Node) -> str | None:
        if self.type_kind is not None:
            return self.type_kind(node)
        return self.type_kinds.get(node.type)

    @property
    def type_node_types(self) -> frozenset[str]:
        return frozenset(self.type_kinds)


# --- shared helpers -------------------------------------------------------


def _modifier_visibility(node: Node, source: bytes, modifier_types: frozenset[str]) -> str | None:
    """Explicit access keywords among a declaration's modifier children."""
    words: set[str] = set()
    for child in node.children:
        if child.type in modifier_types:
            text = keyword_text(source, child) or ""
            words.update(word for word in text.split() if word in VISIBILITY_KEYWORDS)
        elif child.type in VISIBILITY_KEYWORDS:
            words.add(child.type)
    if not words:
        return None
    if words == {"protected", "internal"}:
        return "protected internal"
    if words == {"private", "protected"}:
        return "private protected"
    for keyword in VISIBILITY_KEYWORDS:
        if keyword in words:
            return keyword
    return None


def _named_texts(source: bytes, nodes: list[Node], skip: frozenset[str] = frozenset()) -> list[str | None]:
    return [name_text(source, node) for node in nodes if node.is_named and node.type not in skip and node.type not in COMMENT_TYPES]


def _string_content(node: Node | None, source: bytes, content_types: frozenset[str]) -> str | None:
    if node is None:
        return None
    for child in node.named_children:
        if child.type in content_types:
            return target_text(source, child)
    return None


# --- Python ---------------------------------------------------------------


def _python_imports(node: Node, source: bytes) -> list[str | None]:
    if node.type == "import_from_statement":
        return [target_text(source, node.child_by_field_name("module_name"))]
    if node.type == "future_import_statement":
        return ["__future__"]
    targets: list[str | None] = []
    for child in node.children_by_field_name("name"):
        target = child.child_by_field_name("name") if child.type == "aliased_import" else child
        targets.append(target_text(source, target))
    return targets


def _python_bases(node: Node, source: bytes) -> list[str | None]:
    superclasses = node.child_by_field_name("superclasses")
    if superclasses is None:
        return []
    return _named_texts(source, superclasses.named_children, frozenset({"keyword_argument", "dictionary_splat", "list_splat"}))


PYTHON = LanguageSpec(
    language="python",
    grammar="python",
    function_types=frozenset({"function_definition", "lambda"}),
    method_types=frozenset(),
    anonymous_types=frozenset({"lambda"}),
    type_kinds={"class_definition": "class"},
    decision_types=frozenset(
        {
            "if_statement",
            "elif_clause",
            "for_statement",
            "while_statement",
            "except_clause",
            "conditional_expression",
            "for_in_clause",
            "if_clause",
        }
    ),
    arm_containers={"match_statement": "case_clause"},
    logical_types=frozenset({"boolean_operator"}),
    nesting_types=frozenset({"if_statement", "for_statement", "while_statement", "try_statement", "match_statement"}),
    if_types=frozenset({"if_statement"}),
    else_types=frozenset(),
    import_types=frozenset({"import_statement", "import_from_statement", "future_import_statement"}),
    imports=_python_imports,
    non_parameters=frozenset({"positional_separator", "keyword_separator"}),
    implicit_receivers=frozenset({"self", "cls"}),
    supports_visibility=False,
    bases=_python_bases,
)


# --- PHP ------------------------------------------------------------------


def _php_imports(node: Node, source: bytes) -> list[str | None]:
    for child in node.named_children:
        if child.type in ("qualified_name", "name"):
            return [target_text(source, child)]
    return [None]


def _php_bases(node: Node, source: bytes) -> list[str | None]:
    names: list[str | None] = []
    for child in node.named_children:
        if child.type in ("base_clause", "class_interface_clause"):
            names += _named_texts(source, child.named_children)
    return names


def _php_visibility(node: Node, source: bytes, name: str | None) -> str | None:
    return _modifier_visibility(node, source, frozenset({"visibility_modifier"}))


PHP = LanguageSpec(
    language="php",
    grammar="php",
    function_types=frozenset({"function_definition", "method_declaration", "anonymous_function", "arrow_function"}),
    method_types=frozenset({"method_declaration"}),
    anonymous_types=frozenset({"anonymous_function", "arrow_function"}),
    type_kinds={
        "class_declaration": "class",
        "interface_declaration": "interface",
        "trait_declaration": "trait",
        "enum_declaration": "enum",
    },
    decision_types=frozenset(
        {
            "if_statement",
            "else_if_clause",
            "for_statement",
            "foreach_statement",
            "while_statement",
            "do_statement",
            "catch_clause",
            "conditional_expression",
            "case_statement",
            "match_conditional_expression",
        }
    ),
    nesting_types=frozenset(
        {
            "if_statement",
            "for_statement",
            "foreach_statement",
            "while_statement",
            "do_statement",
            "switch_statement",
            "try_statement",
            "match_expression",
        }
    ),
    if_types=frozenset({"if_statement"}),
    import_types=frozenset({"namespace_use_clause"}),
    imports=_php_imports,
    bases=_php_bases,
    visibility=_php_visibility,
)


# --- JavaScript / TypeScript ----------------------------------------------


def _js_imports(node: Node, source: bytes) -> list[str | None]:
    return [_string_content(node.child_by_field_name("source"), source, frozenset({"string_fragment"}))]


def _js_bases(node: Node, source: bytes) -> list[str | None]:
    names: list[str | None] = []
    for child in node.named_children:
        if child.type == "class_heritage":
            for part in child.named_children:
                if part.type == "extends_clause":
                    names.append(name_text(source, part.child_by_field_name("value")))
                elif part.type == "implements_clause":
                    names += _named_texts(source, part.named_children)
                elif part.type not in COMMENT_TYPES:
                    # JavaScript: class_heritage holds the extended expression directly.
                    names.append(name_text(source, part))
        elif child.type == "extends_type_clause":
            names += [name_text(source, base) for base in child.children_by_field_name("type")]
    return names


def _js_visibility(node: Node, source: bytes, name: str | None) -> str | None:
    explicit = _modifier_visibility(node, source, frozenset({"accessibility_modifier"}))
    if explicit is not None:
        return explicit
    name_node = node.child_by_field_name("name")
    if name_node is not None and name_node.type == "private_property_identifier":
        return "private"
    return None


_JS_FUNCTIONS = frozenset(
    {
        "function_declaration",
        "generator_function_declaration",
        "function_expression",
        "generator_function",
        "arrow_function",
        "method_definition",
    }
)
_JS_DECISIONS = frozenset(
    {
        "if_statement",
        "for_statement",
        "for_in_statement",
        "while_statement",
        "do_statement",
        "catch_clause",
        "ternary_expression",
        "switch_case",
    }
)
_JS_NESTING = frozenset(
    {"if_statement", "for_statement", "for_in_statement", "while_statement", "do_statement", "switch_statement", "try_statement"}
)

JAVASCRIPT = LanguageSpec(
    language="javascript",
    grammar="javascript",
    function_types=_JS_FUNCTIONS,
    method_types=frozenset({"method_definition"}),
    anonymous_types=frozenset({"function_expression", "generator_function", "arrow_function"}),
    type_kinds={"class_declaration": "class", "class": "class"},
    decision_types=_JS_DECISIONS,
    nesting_types=_JS_NESTING,
    if_types=frozenset({"if_statement"}),
    import_types=frozenset({"import_statement"}),
    imports=_js_imports,
    bases=_js_bases,
    visibility=_js_visibility,
)


def _typescript_non_parameter_this(node: Node) -> bool:
    pattern = node.child_by_field_name("pattern")
    return node.type == "required_parameter" and pattern is not None and pattern.type == "this"


TYPESCRIPT = LanguageSpec(
    language="typescript",
    grammar="typescript",
    function_types=_JS_FUNCTIONS,
    method_types=frozenset({"method_definition"}),
    anonymous_types=frozenset({"function_expression", "generator_function", "arrow_function"}),
    type_kinds={
        "class_declaration": "class",
        "abstract_class_declaration": "class",
        "class": "class",
        "interface_declaration": "interface",
        "enum_declaration": "enum",
    },
    decision_types=_JS_DECISIONS,
    nesting_types=_JS_NESTING,
    if_types=frozenset({"if_statement"}),
    import_types=frozenset({"import_statement"}),
    imports=_js_imports,
    bases=_js_bases,
    visibility=_js_visibility,
)


# --- Go -------------------------------------------------------------------


def _go_imports(node: Node, source: bytes) -> list[str | None]:
    return [
        _string_content(
            node.child_by_field_name("path"),
            source,
            frozenset({"interpreted_string_literal_content", "raw_string_literal_content"}),
        )
    ]


def _go_type_kind(node: Node) -> str | None:
    if node.type != "type_spec":
        return None
    definition = node.child_by_field_name("type")
    if definition is None:
        return None
    return {"struct_type": "struct", "interface_type": "interface"}.get(definition.type)


def _go_visibility(node: Node, source: bytes, name: str | None) -> str | None:
    # The Go specification exports an identifier whose first character is an
    # upper-case letter (Unicode class Lu); everything else is package-private.
    if not name:
        return None
    return "public" if name[0].isupper() else "private"


GO = LanguageSpec(
    language="go",
    grammar="go",
    function_types=frozenset({"function_declaration", "method_declaration", "func_literal"}),
    method_types=frozenset({"method_declaration"}),
    anonymous_types=frozenset({"func_literal"}),
    type_kinds={"type_spec": "struct"},
    type_kind=_go_type_kind,
    decision_types=frozenset({"if_statement", "for_statement", "expression_case", "type_case", "communication_case"}),
    nesting_types=frozenset({"if_statement", "for_statement", "expression_switch_statement", "type_switch_statement", "select_statement"}),
    if_types=frozenset({"if_statement"}),
    else_types=frozenset(),
    import_types=frozenset({"import_spec"}),
    imports=_go_imports,
    lexical_methods=False,
    supports_bases=False,
    visibility=_go_visibility,
)


# --- Java -----------------------------------------------------------------


def _java_imports(node: Node, source: bytes) -> list[str | None]:
    target: str | None = None
    wildcard = False
    for child in node.named_children:
        if child.type in ("scoped_identifier", "identifier"):
            target = target_text(source, child)
        elif child.type == "asterisk":
            wildcard = True
    if target is not None and wildcard:
        target += ".*"
    return [target]


def _java_bases(node: Node, source: bytes) -> list[str | None]:
    names: list[str | None] = []
    for child in node.named_children:
        if child.type == "superclass":
            names += _named_texts(source, child.named_children)
        elif child.type in ("super_interfaces", "extends_interfaces"):
            for type_list in child.named_children:
                names += _named_texts(source, type_list.named_children)
    return names


def _java_visibility(node: Node, source: bytes, name: str | None) -> str | None:
    return _modifier_visibility(node, source, frozenset({"modifiers"}))


JAVA = LanguageSpec(
    language="java",
    grammar="java",
    function_types=frozenset({"method_declaration", "constructor_declaration", "compact_constructor_declaration", "lambda_expression"}),
    method_types=frozenset({"method_declaration", "constructor_declaration", "compact_constructor_declaration"}),
    anonymous_types=frozenset({"lambda_expression"}),
    type_kinds={
        "class_declaration": "class",
        "interface_declaration": "interface",
        "enum_declaration": "enum",
        "record_declaration": "record",
        "annotation_type_declaration": "annotation",
    },
    decision_types=frozenset(
        {"if_statement", "for_statement", "enhanced_for_statement", "while_statement", "do_statement", "catch_clause", "ternary_expression"}
    ),
    case_label_types=frozenset({"switch_label"}),
    nesting_types=frozenset(
        {
            "if_statement",
            "for_statement",
            "enhanced_for_statement",
            "while_statement",
            "do_statement",
            "switch_expression",
            "try_statement",
            "try_with_resources_statement",
        }
    ),
    if_types=frozenset({"if_statement"}),
    else_types=frozenset(),
    import_types=frozenset({"import_declaration"}),
    imports=_java_imports,
    non_parameters=frozenset({"receiver_parameter"}),
    bases=_java_bases,
    visibility=_java_visibility,
)


# --- C# -------------------------------------------------------------------


def _csharp_imports(node: Node, source: bytes) -> list[str | None]:
    alias = node.child_by_field_name("name")
    for child in node.named_children:
        if child.type in ("qualified_name", "identifier", "alias_qualified_name") and (alias is None or child.id != alias.id):
            return [target_text(source, child)]
    return [None]


def _csharp_bases(node: Node, source: bytes) -> list[str | None]:
    for child in node.named_children:
        if child.type == "base_list":
            return _named_texts(source, child.named_children, frozenset({"argument_list"}))
    return []


def _csharp_visibility(node: Node, source: bytes, name: str | None) -> str | None:
    return _modifier_visibility(node, source, frozenset({"modifier"}))


CSHARP = LanguageSpec(
    language="csharp",
    grammar="csharp",
    function_types=frozenset(
        {
            "method_declaration",
            "constructor_declaration",
            "destructor_declaration",
            "operator_declaration",
            "conversion_operator_declaration",
            "accessor_declaration",
            "local_function_statement",
            "lambda_expression",
            "anonymous_method_expression",
        }
    ),
    method_types=frozenset(
        {
            "method_declaration",
            "constructor_declaration",
            "destructor_declaration",
            "operator_declaration",
            "conversion_operator_declaration",
            "accessor_declaration",
        }
    ),
    anonymous_types=frozenset({"lambda_expression", "anonymous_method_expression"}),
    type_kinds={
        "class_declaration": "class",
        "interface_declaration": "interface",
        "struct_declaration": "struct",
        "enum_declaration": "enum",
        "record_declaration": "record",
    },
    decision_types=frozenset(
        {"if_statement", "for_statement", "foreach_statement", "while_statement", "do_statement", "catch_clause", "conditional_expression"}
    ),
    case_label_types=frozenset({"switch_section"}),
    arm_containers={"switch_expression": "switch_expression_arm"},
    nesting_types=frozenset(
        {
            "if_statement",
            "for_statement",
            "foreach_statement",
            "while_statement",
            "do_statement",
            "switch_statement",
            "switch_expression",
            "try_statement",
        }
    ),
    if_types=frozenset({"if_statement"}),
    else_types=frozenset(),
    import_types=frozenset({"using_directive"}),
    imports=_csharp_imports,
    bases=_csharp_bases,
    visibility=_csharp_visibility,
)


# --- Rust -----------------------------------------------------------------


def _rust_imports(node: Node, source: bytes) -> list[str | None]:
    argument = node.child_by_field_name("argument")
    if argument is not None and argument.type in ("scoped_use_list", "use_as_clause"):
        argument = argument.child_by_field_name("path")
    elif argument is not None and argument.type == "use_wildcard":
        inner = argument.named_children[0] if argument.named_children else None
        path = target_text(source, inner)
        return [None if path is None else path + "::*"]
    return [target_text(source, argument)]


def _rust_visibility(node: Node, source: bytes, name: str | None) -> str | None:
    for child in node.named_children:
        if child.type == "visibility_modifier":
            return "public" if keyword_text(source, child) == "pub" else "restricted"
    return None


RUST = LanguageSpec(
    language="rust",
    grammar="rust",
    function_types=frozenset({"function_item", "closure_expression"}),
    method_types=frozenset(),
    anonymous_types=frozenset({"closure_expression"}),
    method_scopes=frozenset({"impl_item"}),
    type_kinds={"struct_item": "struct", "enum_item": "enum", "trait_item": "trait", "union_item": "union"},
    decision_types=frozenset({"if_expression", "for_expression", "while_expression"}),
    arm_containers={"match_expression": "match_arm"},
    nesting_types=frozenset({"if_expression", "for_expression", "while_expression", "loop_expression", "match_expression"}),
    if_types=frozenset({"if_expression"}),
    import_types=frozenset({"use_declaration"}),
    imports=_rust_imports,
    non_parameters=frozenset({"self_parameter", "attribute_item"}),
    lexical_methods=False,
    supports_bases=False,
    visibility=_rust_visibility,
)


# --- C / C++ --------------------------------------------------------------

_C_DECLARATOR_NAMES = frozenset(
    {"identifier", "field_identifier", "qualified_identifier", "destructor_name", "operator_name", "template_function", "template_method"}
)


def _c_function_declarator(node: Node) -> Node | None:
    """Follows a function_definition's declarator chain (pointers, references) to its function_declarator."""
    current = node.child_by_field_name("declarator")
    for _ in range(64):
        if current is None or current.type == "function_declarator":
            return current
        current = current.child_by_field_name("declarator")
    return None


def _c_function_name(node: Node, source: bytes) -> str | None:
    if node.type == "lambda_expression":
        return None
    declarator = _c_function_declarator(node)
    inner = None if declarator is None else declarator.child_by_field_name("declarator")
    if inner is None or inner.type not in _C_DECLARATOR_NAMES:
        return None
    return name_text(source, inner)


def _c_parameters(node: Node) -> Node | None:
    if node.type == "lambda_expression":
        declarator = node.child_by_field_name("declarator")
        return None if declarator is None else declarator.child_by_field_name("parameters")
    declarator = _c_function_declarator(node)
    return None if declarator is None else declarator.child_by_field_name("parameters")


def _c_imports(node: Node, source: bytes) -> list[str | None]:
    path = node.child_by_field_name("path")
    if path is None:
        return [None]
    if path.type == "system_lib_string":
        return [target_text(source, path, strip="<>")]
    return [_string_content(path, source, frozenset({"string_content"}))]


def _cpp_bases(node: Node, source: bytes) -> list[str | None]:
    for child in node.named_children:
        if child.type == "base_class_clause":
            return _named_texts(source, child.named_children, frozenset({"access_specifier", "virtual"}))
    return []


_C_DECISIONS = frozenset({"if_statement", "for_statement", "while_statement", "do_statement", "conditional_expression"})
_C_NESTING = frozenset({"if_statement", "for_statement", "while_statement", "do_statement", "switch_statement"})

C = LanguageSpec(
    language="c",
    grammar="c",
    function_types=frozenset({"function_definition"}),
    method_types=frozenset(),
    anonymous_types=frozenset(),
    type_kinds={"struct_specifier": "struct", "union_specifier": "union", "enum_specifier": "enum"},
    types_need_body=True,
    decision_types=_C_DECISIONS,
    case_label_types=frozenset({"case_statement"}),
    nesting_types=_C_NESTING,
    if_types=frozenset({"if_statement"}),
    import_types=frozenset({"preproc_include"}),
    imports=_c_imports,
    supports_bases=False,
    supports_visibility=False,
    function_name=_c_function_name,
    parameters=_c_parameters,
)

CPP = LanguageSpec(
    language="cpp",
    grammar="cpp",
    function_types=frozenset({"function_definition", "lambda_expression"}),
    method_types=frozenset(),
    anonymous_types=frozenset({"lambda_expression"}),
    type_kinds={"class_specifier": "class", "struct_specifier": "struct", "union_specifier": "union", "enum_specifier": "enum"},
    types_need_body=True,
    decision_types=_C_DECISIONS | {"for_range_loop", "catch_clause"},
    case_label_types=frozenset({"case_statement"}),
    nesting_types=_C_NESTING | {"for_range_loop", "try_statement"},
    if_types=frozenset({"if_statement"}),
    import_types=frozenset({"preproc_include"}),
    imports=_c_imports,
    access_labels=True,
    function_name=_c_function_name,
    parameters=_c_parameters,
    bases=_cpp_bases,
)

SPECS: dict[str, LanguageSpec] = {spec.language: spec for spec in (C, CPP, CSHARP, GO, JAVA, JAVASCRIPT, PHP, PYTHON, RUST, TYPESCRIPT)}


def grammar_for(language: str, extension: str | None) -> str | None:
    """Grammar key for a file, or None when no parser is registered for its language."""
    spec = SPECS.get(language)
    if spec is None:
        return None
    if language == "typescript" and extension == ".tsx":
        return "tsx"
    return spec.grammar
