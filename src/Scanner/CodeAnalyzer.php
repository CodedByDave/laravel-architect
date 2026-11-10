<?php

namespace LaravelArchitect\Architect\Scanner;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
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