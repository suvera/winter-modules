<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;

/**
 * Parses SCIM filters and PATCH paths (RFC 7644 §3.4.2.2, §3.5.2) into the
 * {@see FilterNode} tree. A hand-written recursive descent port of the
 * SCIMFilter.g4 of suvera/scim2-sdk and suvera/goScim2, with these
 * differences from that grammar:
 *
 *  - keywords (`and`, `or`, `not`, `pr`, the operators, `true`/`false`/`null`)
 *    are case-insensitive, and a word is a keyword only where the grammar
 *    expects one, so attributes may be called `pr`, `eq` or `a`;
 *  - `and` binds tighter than `or`; whitespace is free (`not (…)`, `( a pr )`);
 *  - numbers may be negative, decimal or carry an exponent; strings use JSON
 *    escapes;
 *  - attribute paths may carry a schema URN with dots (`…:enterprise:2.0:User:x`);
 *  - the whole input must be consumed;
 *  - `emails[type eq "work"].value eq "x"` (Entra ID's form) is accepted and
 *    read as `emails[type eq "work" and value eq "x"]`.
 */
final class FilterParser {
    private const string LPAREN = '(';
    private const string RPAREN = ')';
    private const string LBRACKET = '[';
    private const string RBRACKET = ']';
    private const string DOT = '.';
    private const string WORD = 'word';
    private const string STRING = 'string';
    private const string NUMBER = 'number';
    private const string END = 'end';

    /** @var list<array{0: string, 1: mixed, 2: int}> type, value, offset */
    private array $tokens = [];
    private int $pos = 0;

    private function __construct(
        private readonly string $input,
        private readonly ScimErrorType $error,
    ) {
        $this->tokenize();
    }

    public static function parse(string $filter): FilterNode {
        $parser = new self($filter, ScimErrorType::INVALID_FILTER);
        $node = $parser->orExpression(false);
        $parser->expect(self::END);
        return $node;
    }

    public static function parsePath(string $path): PatchPath {
        $parser = new self($path, ScimErrorType::INVALID_PATH);
        $attribute = $parser->attributePath();
        $filter = null;
        $sub = null;
        if ($parser->peek() === self::LBRACKET) {
            if ($attribute->subAttribute !== null) {
                $parser->fail("A value filter needs a plain attribute, not '$attribute'");
            }
            $parser->pos++;
            $filter = $parser->orExpression(true);
            $parser->expect(self::RBRACKET);
            if ($parser->peek() === self::DOT) {
                $parser->pos++;
                $sub = $parser->simpleName();
            }
        }
        $parser->expect(self::END);
        return new PatchPath($attribute, $filter, $sub);
    }

    private function orExpression(bool $inValuePath): FilterNode {
        $node = $this->andExpression($inValuePath);
        while ($this->isKeyword('or')) {
            $this->pos++;
            $node = new Logical(LogicalOperator::OR, $node, $this->andExpression($inValuePath));
        }
        return $node;
    }

    private function andExpression(bool $inValuePath): FilterNode {
        $node = $this->unary($inValuePath);
        while ($this->isKeyword('and')) {
            $this->pos++;
            $node = new Logical(LogicalOperator::AND, $node, $this->unary($inValuePath));
        }
        return $node;
    }

    private function unary(bool $inValuePath): FilterNode {
        if ($this->isKeyword('not') && $this->peek(1) === self::LPAREN) {
            $this->pos += 2;
            $node = $this->orExpression($inValuePath);
            $this->expect(self::RPAREN);
            return new Negation($node);
        }
        if ($this->peek() === self::LPAREN) {
            $this->pos++;
            $node = $this->orExpression($inValuePath);
            $this->expect(self::RPAREN);
            return $node;
        }
        return $this->attributeExpression($inValuePath);
    }

    private function attributeExpression(bool $inValuePath): FilterNode {
        $path = $this->attributePath();
        if ($this->peek() === self::LBRACKET) {
            if ($inValuePath) {
                $this->fail('Value filters cannot be nested');
            }
            if ($path->subAttribute !== null) {
                $this->fail("A value filter needs a plain attribute, not '$path'");
            }
            $this->pos++;
            $inner = $this->orExpression(true);
            $this->expect(self::RBRACKET);
            if ($this->peek() === self::DOT) {
                $this->pos++;
                $sub = new AttributePath(null, $this->simpleName());
                $inner = new Logical(LogicalOperator::AND, $inner, $this->comparisonOn($sub));
            }
            return new ValuePath($path, $inner);
        }
        if ($inValuePath && ($path->schema !== null || $path->subAttribute !== null)) {
            $this->fail("Inside a value filter, '$path' must be a plain sub-attribute name");
        }
        return $this->comparisonOn($path);
    }

    private function comparisonOn(AttributePath $path): FilterNode {
        [$type, $word] = $this->token();
        if ($type !== self::WORD) {
            $this->fail("Expected an operator after '$path'");
        }
        $this->pos++;
        $op = strtolower($word);
        if ($op === 'pr') {
            return new Presence($path);
        }
        $operator = CompareOperator::tryFrom($op) ?? $this->fail("Unknown operator '$word'");
        $value = $this->compareValue();
        if (in_array($operator, [CompareOperator::CO, CompareOperator::SW, CompareOperator::EW], true) && !is_string($value)) {
            $this->fail("'$op' needs a string value");
        }
        if (in_array($operator, [CompareOperator::GT, CompareOperator::LT, CompareOperator::GE, CompareOperator::LE], true)
            && (is_bool($value) || $value === null)) {
            $this->fail("'$op' cannot compare with a boolean or null");
        }
        return new Comparison($path, $operator, $value);
    }

    private function compareValue(): string|int|float|bool|null {
        [$type, $value] = $this->token();
        $this->pos++;
        if ($type === self::STRING || $type === self::NUMBER) {
            return $value;
        }
        if ($type === self::WORD) {
            switch (strtolower($value)) {
                case 'true':
                    return true;
                case 'false':
                    return false;
                case 'null':
                    return null;
            }
        }
        $this->pos--;
        $this->fail('Expected a value (a string, number, true, false or null)');
    }

    private function attributePath(): AttributePath {
        [$type, $word] = $this->token();
        if ($type !== self::WORD) {
            $this->fail('Expected an attribute name');
        }
        $this->pos++;
        return AttributePath::parse($word, $this->error);
    }

    private function simpleName(): string {
        [$type, $word] = $this->token();
        if ($type !== self::WORD || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $word)) {
            $this->fail('Expected a sub-attribute name');
        }
        $this->pos++;
        return $word;
    }

    private function isKeyword(string $keyword): bool {
        [$type, $word] = $this->token();
        return $type === self::WORD && strcasecmp($word, $keyword) === 0;
    }

    private function peek(int $ahead = 0): string {
        return $this->tokens[min($this->pos + $ahead, count($this->tokens) - 1)][0];
    }

    /** @return array{0: string, 1: mixed, 2: int} */
    private function token(): array {
        return $this->tokens[$this->pos];
    }

    private function expect(string $type): void {
        if ($this->peek() !== $type) {
            $this->fail($type === self::END ? 'Unexpected text' : "Expected '$type'");
        }
        $this->pos++;
    }

    private function fail(string $message): never {
        $offset = $this->tokens[$this->pos][2] ?? strlen($this->input);
        throw new ScimException(400, $this->error, "$message at offset $offset of '{$this->input}'");
    }

    private function tokenize(): void {
        $input = $this->input;
        $length = strlen($input);
        $i = 0;
        while ($i < $length) {
            $c = $input[$i];
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $i++;
                continue;
            }
            if (str_contains('()[].', $c)) {
                $this->tokens[] = [$c, $c, $i];
                $i++;
                continue;
            }
            if ($c === '"') {
                $end = $i + 1;
                while ($end < $length && $input[$end] !== '"') {
                    $end += $input[$end] === '\\' ? 2 : 1;
                }
                if ($end >= $length) {
                    $this->tokens[] = [self::END, null, $i];
                    $this->pos = count($this->tokens) - 1;
                    $this->fail('Unterminated string');
                }
                $value = json_decode(substr($input, $i, $end - $i + 1));
                if (!is_string($value)) {
                    $this->tokens[] = [self::END, null, $i];
                    $this->pos = count($this->tokens) - 1;
                    $this->fail('Invalid string escape');
                }
                $this->tokens[] = [self::STRING, $value, $i];
                $i = $end + 1;
                continue;
            }
            if (preg_match('/\G-?\d+(\.\d+)?([eE][+-]?\d+)?/', $input, $m, 0, $i)) {
                $number = $m[0];
                $isInt = ($m[1] ?? '') === '' && ($m[2] ?? '') === '' && filter_var($number, FILTER_VALIDATE_INT) !== false;
                $this->tokens[] = [self::NUMBER, $isInt ? (int)$number : (float)$number, $i];
                $i += strlen($number);
                continue;
            }
            if (preg_match('/\G[A-Za-z$][A-Za-z0-9_\-:.$]*/', $input, $m, 0, $i)) {
                $this->tokens[] = [self::WORD, $m[0], $i];
                $i += strlen($m[0]);
                continue;
            }
            $this->tokens[] = [self::END, null, $i];
            $this->pos = count($this->tokens) - 1;
            $this->fail("Unexpected character '$c'");
        }
        $this->tokens[] = [self::END, null, $length];
    }
}
