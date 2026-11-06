<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Php;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** کد PHP را parse می‌کند و برای هر متد (و هر تست Pest) واقعیت‌های قابل مقایسه بیرون می‌کشد. */
final class PhpInspector
{
    private const EXPECTATIONS = ['expectexception', 'expectexceptionmessage', 'expectexceptioncode', 'expectexceptionobject', 'expectexceptionmessagematches', 'fail'];
    private const UNARY = ['asserttrue', 'assertfalse', 'assertnull', 'assertnotnull', 'assertempty', 'assertnotempty'];
    private const BINARY = ['assertsame', 'assertequals'];
    private const EXPECTED = ['assertsame', 'assertequals', 'assertequalscanonicalizing'];
    /** assertهایی که یک مقدار دقیق را می‌سنجند؛ جایگزینی‌شان با چک ضعیف‌تر، تست را سست می‌کند */
    private const EXACT = ['assertsame', 'assertequals', 'assertequalscanonicalizing', 'assertequalsignoringcase', 'assertjsonstringequalsjsonstring'];
    private const PEST_EXACT = ['tobe', 'toequal', 'tostrictequal'];

    private Standard $printer;

    private NodeFinder $finder;

    public function __construct()
    {
        $this->printer = new Standard();
        $this->finder = new NodeFinder();
    }

    /** @return array<string, MethodFacts>|null null یعنی کد parse نشد */
    public function methods(string $code): ?array
    {
        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        } catch (Error) {
            return null;
        }

        $facts = [];
        foreach ($this->finder->findInstanceOf($ast, Class_::class) as $class) {
            $className = $class->name?->toString() ?? 'anonymous';
            $isTestClass = str_ends_with($className, 'Test')
                || ($class->extends !== null && str_ends_with($class->extends->toString(), 'TestCase'));

            foreach ($class->getMethods() as $method) {
                $key = $className . '::' . $method->name->toString();
                $isTest = $isTestClass && $method->isPublic() && $this->isTestMethod($method);
                $facts[$key] = $this->analyze($key, $method->getStartLine(), $isTest, $method->stmts ?? []);
            }
        }

        foreach ($this->pestTests($ast) as [$key, $line, $stmts, $skips, $exact]) {
            $facts[$key] = $this->analyze($key, $line, true, $stmts, $skips, $exact);
        }

        return $facts;
    }

    public function suppressions(string $code): int
    {
        return (int) preg_match_all('/@(phpstan-ignore[\w-]*|psalm-suppress|codeCoverageIgnore\w*)/', $code);
    }

    private function isTestMethod(ClassMethod $method): bool
    {
        if (str_starts_with($method->name->toString(), 'test')) {
            return true;
        }
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'Test') {
                    return true;
                }
            }
        }

        return str_contains((string) $method->getDocComment()?->getText(), '@test');
    }

    /** @return list<array{0: string, 1: int, 2: array, 3: int, 4: int}> */
    private function pestTests(array $ast): array
    {
        $statements = [];
        foreach ($ast as $stmt) {
            $statements = $stmt instanceof Stmt\Namespace_ ? [...$statements, ...$stmt->stmts] : [...$statements, $stmt];
        }

        $tests = [];
        foreach ($statements as $stmt) {
            if (! $stmt instanceof Stmt\Expression) {
                continue;
            }
            $expr = $stmt->expr;
            $chain = [];
            while ($expr instanceof MethodCall) {
                if ($expr->name instanceof Identifier) {
                    $chain[] = strtolower($expr->name->toString());
                }
                $expr = $expr->var;
            }
            if (! $expr instanceof FuncCall || ! $expr->name instanceof Name || ! in_array(strtolower($expr->name->toString()), ['test', 'it'], true)) {
                continue;
            }
            $args = $this->argValues($expr);
            if (! isset($args[0]) || ! $args[0] instanceof String_) {
                continue;
            }
            $callback = $args[1] ?? null;
            $stmts = match (true) {
                $callback instanceof Closure => $callback->stmts,
                $callback instanceof ArrowFunction => [new Stmt\Return_($callback->expr)],
                default => [],
            };
            $tests[] = ['pest:' . $args[0]->value, $expr->getStartLine(), $stmts, count(array_intersect($chain, ['skip', 'todo'])), 0];
        }

        return $tests;
    }

    private function analyze(string $key, int $line, bool $isTest, array $stmts, int $extraSkips = 0, int $extraExact = 0): MethodFacts
    {
        $assertions = 0;
        $exact = $extraExact;
        $skips = $extraSkips;
        $tautologies = [];
        $expected = [];

        $calls = $this->finder->find($stmts, fn (Node $n) => $n instanceof MethodCall || $n instanceof StaticCall || $n instanceof FuncCall);
        foreach ($calls as $call) {
            $name = $this->callName($call);
            if ($name === null) {
                continue;
            }
            $values = $this->argValues($call);

            if ($call instanceof FuncCall) {
                if ($name === 'expect') {
                    $assertions++;
                    if (isset($values[0]) && $this->isLiteral($values[0])) {
                        $tautologies[] = $call->getStartLine();
                    }
                }

                continue;
            }

            if ($call instanceof MethodCall && in_array($name, self::PEST_EXACT, true)) {
                $exact++;

                continue;
            }
            if (in_array($name, ['marktestskipped', 'marktestincomplete'], true)) {
                $skips++;

                continue;
            }
            if (! str_starts_with($name, 'assert') && ! in_array($name, self::EXPECTATIONS, true)) {
                continue;
            }

            $assertions++;
            if (in_array($name, self::EXACT, true)) {
                $exact++;
            }
            if (in_array($name, self::UNARY, true) && isset($values[0]) && $this->isLiteral($values[0])) {
                $tautologies[] = $call->getStartLine();
            }
            if (in_array($name, self::BINARY, true) && count($values) >= 2 && $this->print($values[0]) === $this->print($values[1])) {
                $tautologies[] = $call->getStartLine();
            }
            if (in_array($name, self::EXPECTED, true) && isset($values[0]) && $this->isLiteral($values[0])) {
                $expected[] = $this->print($values[0]);
            }
        }

        $swallowed = 0;
        $unguarded = 0;
        foreach ($this->finder->findInstanceOf($stmts, Stmt\TryCatch::class) as $try) {
            foreach ($try->catches as $catch) {
                $body = array_values(array_filter($catch->stmts, fn ($s) => ! $s instanceof Stmt\Nop));
                if ($body === [] || (count($body) === 1 && $body[0] instanceof Stmt\Return_ && ($body[0]->expr === null || $this->isLiteral($body[0]->expr)))) {
                    $swallowed++;
                }
                $checks = $this->finder->find($catch->stmts, fn (Node $n) => $n instanceof Expr\Throw_
                    || (($n instanceof MethodCall || $n instanceof StaticCall) && ($this->callName($n) === 'fail' || str_starts_with((string) $this->callName($n), 'assert'))));
                if ($checks === []) {
                    $unguarded++;
                }
            }
        }

        $special = 0;
        foreach ($this->finder->findInstanceOf($stmts, Stmt\If_::class) as $if) {
            $cond = $if->cond;
            if (! $cond instanceof Identical && ! $cond instanceof Equal) {
                continue;
            }
            // شکل تقلب: یک متغیر با یک مقدار مشخص مقایسه می‌شود و یک جواب مشخص برمی‌گردد
            $literal = $this->isLiteral($cond->left) ? $cond->left : ($this->isLiteral($cond->right) ? $cond->right : null);
            $other = $literal === $cond->left ? $cond->right : $cond->left;
            if ($literal === null || ! $other instanceof Expr\Variable || $this->isTrivial($literal)) {
                continue;
            }
            $body = array_values(array_filter($if->stmts, fn ($s) => ! $s instanceof Stmt\Nop));
            if (count($body) === 1 && $body[0] instanceof Stmt\Return_ && $body[0]->expr !== null
                && $this->isLiteral($body[0]->expr) && ! $this->isNullOrBool($body[0]->expr)) {
                $special++;
            }
        }

        return new MethodFacts(
            $key,
            $line,
            $isTest,
            sha1($this->printer->prettyPrint($stmts)),
            $assertions,
            $skips,
            $tautologies,
            $expected,
            count($this->finder->findInstanceOf($stmts, Expr\Throw_::class)),
            $swallowed,
            $special,
            $isTest ? $unguarded : 0,
            $exact,
        );
    }

    private function callName(Node $call): ?string
    {
        if (($call instanceof MethodCall || $call instanceof StaticCall) && $call->name instanceof Identifier) {
            return strtolower($call->name->toString());
        }
        if ($call instanceof FuncCall && $call->name instanceof Name) {
            return strtolower($call->name->getLast());
        }

        return null;
    }

    /** @return list<Expr> */
    private function argValues(Node $call): array
    {
        $values = [];
        foreach ($call->args ?? [] as $arg) {
            if ($arg instanceof Arg) {
                $values[] = $arg->value;
            }
        }

        return $values;
    }

    private function isLiteral(Node $node): bool
    {
        return match (true) {
            $node instanceof Scalar\InterpolatedString, $node instanceof Scalar\MagicConst => false,
            $node instanceof Scalar, $node instanceof ConstFetch => true,
            $node instanceof UnaryMinus => $node->expr instanceof Scalar,
            $node instanceof Expr\Array_ => array_reduce($node->items, fn ($ok, $item) => $ok && $item !== null && $this->isLiteral($item->value), true),
            default => false,
        };
    }

    private function isNullOrBool(Node $node): bool
    {
        return $node instanceof ConstFetch && in_array($node->name->toLowerString(), ['null', 'true', 'false'], true);
    }

    /** null، true/false، رشته‌ی خالی و آرایه‌ی خالی: مقایسه با این‌ها نگهبان عادی است، نه حالت ویژه */
    private function isTrivial(Node $node): bool
    {
        return $this->isNullOrBool($node)
            || ($node instanceof String_ && $node->value === '')
            || ($node instanceof Expr\Array_ && $node->items === []);
    }

    private function print(Expr $expr): string
    {
        return $this->printer->prettyPrintExpr($expr);
    }
}
