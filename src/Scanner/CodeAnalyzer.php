<?php

namespace LaravelArchitect\Architect\Scanner;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class CodeAnalyzer
{
    public const FAT_METHOD_LINES = 25;

    private Parser $parser;

    private NodeFinder $finder;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    public function declaredClass(string $code): ?string
    {
        $class = $this->firstClass($this->parse($code));

        if ($class === null) {
            return null;
        }

        return ($class->namespacedName ?? $class->name)?->toString();
    }

    public function analyzeController(string $code, array $modelClasses = []): array
    {
        $result = [
            'class' => null,
            'methods' => 0,
            'lines' => 0,
            'fat_methods' => [],
            'inline_validation' => false,
            'model_calls' => [],
            'db_facade' => false,
            'returns_json' => false,
            'returns_view' => false,
        ];

        $class = $this->firstClass($this->parse($code));

        if ($class === null) {
            return $result;
        }

        $result['class'] = ($class->namespacedName ?? $class->name)?->toString();
        $result['lines'] = $class->getEndLine() - $class->getStartLine() + 1;

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();

            if (! $method->isPublic() || $name === '__construct') {
                continue;
            }

            $result['methods']++;

            $lines = $method->getEndLine() - $method->getStartLine() + 1;
            if ($lines > self::FAT_METHOD_LINES) {
                $result['fat_methods'][] = $name;
            }
        }

        foreach ($this->finder->findInstanceOf($class, StaticCall::class) as $call) {
            if (! $call->class instanceof Name) {
                continue;
            }

            $fqcn = $call->class->toString();
            $last = $call->class->getLast();
            $method = $call->name instanceof Identifier ? $call->name->toString() : '?';

            if (in_array($fqcn, $modelClasses, true)) {
                $result['model_calls'][] = $last.'::'.$method;
            } elseif ($last === 'DB') {
                $result['db_facade'] = true;
            } elseif ($last === 'Validator' && $method === 'make') {
                $result['inline_validation'] = true;
            } elseif ($last === 'Inertia' && $method === 'render') {
                $result['returns_view'] = true;
            } elseif (str_ends_with($last, 'Resource') && $method === 'collection') {
                $result['returns_json'] = true;
            }
        }

        foreach ($this->finder->findInstanceOf($class, MethodCall::class) as $call) {
            $name = $call->name instanceof Identifier ? $call->name->toString() : null;

            if ($name === 'validate' || $name === 'validateWith') {
                $result['inline_validation'] = true;
            }

            if ($name === 'json'
                && $call->var instanceof FuncCall
                && $call->var->name instanceof Name
                && $call->var->name->getLast() === 'response') {
                $result['returns_json'] = true;
            }
        }

        foreach ($this->finder->findInstanceOf($class, FuncCall::class) as $call) {
            if (! $call->name instanceof Name) {
                continue;
            }

            $function = $call->name->getLast();

            if ($function === 'view' || $function === 'inertia') {
                $result['returns_view'] = true;
            }
        }

        foreach ($this->finder->findInstanceOf($class, New_::class) as $new) {
            if ($new->class instanceof Name && str_ends_with($new->class->getLast(), 'Resource')) {
                $result['returns_json'] = true;
            }
        }

        $result['model_calls'] = array_values(array_unique($result['model_calls']));

        return $result;
    }

    public function analyzeModel(string $code): array
    {
        $class = $this->firstClass($this->parse($code));

        if ($class === null) {
            return ['class' => null, 'status_constants' => 0];
        }

        $count = 0;

        foreach ($this->finder->findInstanceOf($class, ClassConst::class) as $constant) {
            foreach ($constant->consts as $item) {
                if (preg_match('/^(STATUS|TYPE|ROLE|STATE)_/', $item->name->toString())) {
                    $count++;
                }
            }
        }

        return [
            'class' => ($class->namespacedName ?? $class->name)?->toString(),
            'status_constants' => $count,
        ];
    }

    /**
     * Describe what a repository/service/action actually does, so reasons can be specific.
     *
     * @return array{methods: string[], models: string[], tables: string[], facades: string[], hosts: string[]}
     */
    public function describeClass(string $code): array
    {
        $result = ['methods' => [], 'models' => [], 'tables' => [], 'facades' => [], 'hosts' => []];

        try {
            $statements = $this->parse($code);
        } catch (\PhpParser\Error) {
            return $result;
        }

        $class = $this->firstClass($statements);

        if ($class === null) {
            return $result;
        }

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();

            if ($method->isPublic() && ! str_starts_with($name, '__')) {
                $result['methods'][] = $name;
            }
        }

        foreach ($this->finder->findInstanceOf($class, Name::class) as $name) {
            $segments = explode('\\', $name->toString());

            if (count($segments) > 1 && in_array('Models', array_slice($segments, 0, -1), true)) {
                $result['models'][] = end($segments);
            }
        }

        foreach ($this->finder->findInstanceOf($class, StaticCall::class) as $call) {
            if (! $call->class instanceof Name) {
                continue;
            }

            $last = $call->class->getLast();

            if (in_array($last, ['DB', 'Http', 'Cache', 'Storage', 'Mail', 'Queue', 'Redis'], true)) {
                $result['facades'][] = $last;
            }

            if ($last === 'DB' && $call->name instanceof Identifier && $call->name->toString() === 'table'
                && isset($call->args[0]) && $call->args[0]->value instanceof String_) {
                $result['tables'][] = $call->args[0]->value->value;
            }
        }

        foreach ($this->finder->findInstanceOf($class, MethodCall::class) as $call) {
            if ($call->name instanceof Identifier && $call->name->toString() === 'table'
                && isset($call->args[0]) && $call->args[0]->value instanceof String_) {
                $result['tables'][] = $call->args[0]->value->value;
            }
        }

        foreach ($this->finder->findInstanceOf($class, Property::class) as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'table' && $prop->default instanceof String_) {
                    $result['tables'][] = $prop->default->value;
                }
            }
        }

        foreach ($this->finder->findInstanceOf($class, String_::class) as $string) {
            if (preg_match('~^https?://([a-z0-9.-]+)~i', $string->value, $m)) {
                $result['hosts'][] = strtolower($m[1]);
            }
        }

        foreach ($result as $key => $values) {
            $result[$key] = array_values(array_unique($values));
        }

        return $result;
    }

    /**
     * Structural facts about the first class in a file, for the architecture report.
     * Throws PhpParser\Error when the file cannot be parsed.
     *
     * "references" lists every fully qualified class named in the file (imports, type hints,
     * ::class, new, static calls) plus string literals that look like class names (config, bindings),
     * minus the file's own class and namespace.
     *
     * @return array{class: ?string, short: ?string, abstract: bool, lines: int, references: string[], response_methods: string[]}
     */
    public function inspectClass(string $code): array
    {
        $statements = $this->parse($code);
        $class = $this->firstClass($statements);
        $own = $class !== null && $class->name !== null ? ($class->namespacedName ?? $class->name)->toString() : null;

        $namespace = $this->finder->findFirstInstanceOf($statements, Namespace_::class);
        $namespaceName = $namespace instanceof Namespace_ ? $namespace->name?->toString() : null;

        $references = [];

        foreach ($this->finder->findInstanceOf($statements, Name::class) as $name) {
            if ($name->isSpecialClassName()) {
                continue;
            }

            $fqcn = $name->toString();

            if (str_contains($fqcn, '\\') && $fqcn !== $namespaceName && $fqcn !== $own) {
                $references[$fqcn] = true;
            }
        }

        foreach ($this->finder->findInstanceOf($statements, String_::class) as $string) {
            $value = ltrim(str_replace('\\\\', '\\', $string->value), '\\');

            if ($value !== $own && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $value)) {
                $references[$value] = true;
            }
        }

        $result = [
            'class' => $own,
            'short' => $own !== null ? $class->name->toString() : null,
            'abstract' => $class !== null && $class->isAbstract(),
            'lines' => $class !== null ? $class->getEndLine() - $class->getStartLine() + 1 : 0,
            'references' => array_keys($references),
            'response_methods' => [],
        ];

        if ($own === null) {
            return $result;
        }

        foreach ($class->getMethods() as $method) {
            if ($this->returnsHttpResponse($method)) {
                $result['response_methods'][] = $method->name->toString();
            }
        }

        return $result;
    }

    private function returnsHttpResponse(ClassMethod $method): bool
    {
        if ($method->returnType !== null) {
            foreach ($this->finder->findInstanceOf($method->returnType, Name::class) as $name) {
                if ($this->isResponseType($name->toString())) {
                    return true;
                }
            }
        }

        foreach ($this->finder->findInstanceOf($method, Return_::class) as $return) {
            if ($return->expr !== null && $this->isResponseExpression($return->expr)) {
                return true;
            }
        }

        return false;
    }

    private function isResponseType(string $fqcn): bool
    {
        if (str_starts_with($fqcn, 'Illuminate\\Http\\Client\\')) {
            return false;
        }

        $framework = str_starts_with($fqcn, 'Illuminate\\') || str_starts_with($fqcn, 'Symfony\\') || str_starts_with($fqcn, 'Inertia\\');
        $last = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

        return $framework && in_array($last, ['Response', 'JsonResponse', 'RedirectResponse', 'StreamedResponse', 'BinaryFileResponse', 'View', 'Responsable'], true);
    }

    private function isResponseExpression(Expr $expression): bool
    {
        while ($expression instanceof MethodCall || $expression instanceof NullsafeMethodCall) {
            $expression = $expression->var;
        }

        if ($expression instanceof FuncCall && $expression->name instanceof Name) {
            return in_array($expression->name->getLast(), ['response', 'redirect', 'back', 'to_route', 'inertia'], true);
        }

        if ($expression instanceof StaticCall && $expression->class instanceof Name) {
            return in_array($expression->class->getLast(), ['Response', 'Redirect', 'Inertia'], true);
        }

        if ($expression instanceof New_ && $expression->class instanceof Name) {
            return $this->isResponseType($expression->class->toString());
        }

        return false;
    }

    private function parse(string $code): array
    {
        $statements = $this->parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        return $traverser->traverse($statements);
    }

    private function firstClass(array $statements): ?Class_
    {
        $class = $this->finder->findFirstInstanceOf($statements, Class_::class);

        return $class instanceof Class_ ? $class : null;
    }
}