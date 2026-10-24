<?php

declare(strict_types=1);

namespace test\winterframework\scim;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use dev\winterframework\scim\filter\AttributePath;
use dev\winterframework\scim\filter\Comparison;
use dev\winterframework\scim\filter\CompareOperator;
use dev\winterframework\scim\filter\FilterNode;
use dev\winterframework\scim\filter\FilterParser;
use dev\winterframework\scim\filter\Logical;
use dev\winterframework\scim\filter\LogicalOperator;
use dev\winterframework\scim\filter\Negation;
use dev\winterframework\scim\filter\Presence;
use dev\winterframework\scim\filter\ValuePath;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;

class FilterParserTest extends TestCase {
    /** The filter examples of RFC 7644 §3.4.2.2, in a compact notation. */
    public static function rfcExamples(): array {
        return [
            ['userName eq "bjensen"', 'userName eq "bjensen"'],
            ['name.familyName co "O\'Malley"', 'name.familyName co "O\'Malley"'],
            ['userName sw "J"', 'userName sw "J"'],
            ['urn:ietf:params:scim:schemas:core:2.0:User:userName sw "J"', 'urn:ietf:params:scim:schemas:core:2.0:User:userName sw "J"'],
            ['title pr', 'title pr'],
            ['meta.lastModified gt "2011-05-13T04:42:34Z"', 'meta.lastModified gt "2011-05-13T04:42:34Z"'],
            ['meta.lastModified ge "2011-05-13T04:42:34Z"', 'meta.lastModified ge "2011-05-13T04:42:34Z"'],
            ['meta.lastModified lt "2011-05-13T04:42:34Z"', 'meta.lastModified lt "2011-05-13T04:42:34Z"'],
            ['meta.lastModified le "2011-05-13T04:42:34Z"', 'meta.lastModified le "2011-05-13T04:42:34Z"'],
            ['title pr and userType eq "Employee"', '(title pr and userType eq "Employee")'],
            ['title pr or userType eq "Intern"', '(title pr or userType eq "Intern")'],
            ['schemas eq "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User"', 'schemas eq "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User"'],
            ['userType eq "Employee" and (emails co "example.com" or emails.value co "example.org")',
                '(userType eq "Employee" and (emails co "example.com" or emails.value co "example.org"))'],
            ['userType ne "Employee" and not (emails co "example.com" or emails.value co "example.org")',
                '(userType ne "Employee" and not((emails co "example.com" or emails.value co "example.org")))'],
            ['userType eq "Employee" and (emails.type eq "work")', '(userType eq "Employee" and emails.type eq "work")'],
            ['userType eq "Employee" and emails[type eq "work" and value co "@example.com"]',
                '(userType eq "Employee" and emails[(type eq "work" and value co "@example.com")])'],
            ['emails[type eq "work" and value co "@example.com"] or ims[type eq "xmpp" and value co "@foo.com"]',
                '(emails[(type eq "work" and value co "@example.com")] or ims[(type eq "xmpp" and value co "@foo.com")])'],
        ];
    }

    #[DataProvider('rfcExamples')]
    public function test_rfc_7644_examples(string $filter, string $expected): void {
        $this->assertSame($expected, self::show(FilterParser::parse($filter)));
    }

    public function test_the_scim2_sdk_test_input(): void {
        $node = FilterParser::parse('userType eq "Empl\"oyee" and xxxxxx pr and emails[type eq "woo rk" and value co "true"]');

        $this->assertSame('((userType eq "Empl\"oyee" and xxxxxx pr) and emails[(type eq "woo rk" and value co "true")])', self::show($node));
    }

    public function test_and_binds_tighter_than_or(): void {
        $this->assertSame('((a pr and b pr) or c pr)', self::show(FilterParser::parse('a pr and b pr or c pr')));
        $this->assertSame('(a pr or (b pr and c pr))', self::show(FilterParser::parse('a pr or b pr and c pr')));
    }

    public function test_keywords_are_case_insensitive(): void {
        $this->assertSame('(userName eq "x" and not(title pr))', self::show(FilterParser::parse('userName EQ "x" AND NOT (title PR)')));
        $this->assertSame('active eq true', self::show(FilterParser::parse('active Eq TRUE')));
    }

    public function test_keywords_are_only_keywords_where_the_grammar_expects_them(): void {
        $this->assertSame('pr pr', self::show(FilterParser::parse('pr pr')));
        $this->assertSame('eq eq "x"', self::show(FilterParser::parse('eq eq "x"')));
        $this->assertSame('a eq 1', self::show(FilterParser::parse('a eq 1')));
    }

    public function test_whitespace_is_free(): void {
        $this->assertSame('not(a pr)', self::show(FilterParser::parse('not(a pr)')));
        $this->assertSame('not(a pr)', self::show(FilterParser::parse('not ( a pr )')));
        $this->assertSame('(a pr and b pr)', self::show(FilterParser::parse("  ( a pr )\tand  b pr ")));
    }

    public function test_numbers(): void {
        $node = FilterParser::parse('x eq -12');
        $this->assertSame(-12, $node->value);
        $this->assertSame(1.5, FilterParser::parse('x gt 1.5')->value);
        $this->assertSame(2000.0, FilterParser::parse('x lt 2e3')->value);
        $this->assertSame(9.223372036854776E+19, FilterParser::parse('x eq 92233720368547758070')->value, 'too big for an int');
    }

    public function test_json_string_escapes(): void {
        $this->assertSame("a\\b\"c\nd\u{e9}", FilterParser::parse('x eq "a\\\\b\\"c\\nd\\u00e9"')->value);
    }

    public function test_enterprise_urn_with_dots_and_a_sub_attribute(): void {
        $node = FilterParser::parse('urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:manager.value eq "26118915"');

        $this->assertInstanceOf(Comparison::class, $node);
        $this->assertSame('urn:ietf:params:scim:schemas:extension:enterprise:2.0:User', $node->path->schema);
        $this->assertSame('manager', $node->path->name);
        $this->assertSame('value', $node->path->subAttribute);
    }

    public function test_entra_value_path_with_a_trailing_sub_attribute(): void {
        $node = FilterParser::parse('emails[type eq "work"].value eq "user@example.com"');

        $this->assertSame('emails[(type eq "work" and value eq "user@example.com")]', self::show($node));
    }

    public function test_one_letter_and_ref_names(): void {
        $this->assertSame('members.$ref pr', self::show(FilterParser::parse('members.$ref pr')));
        $this->assertSame('x eq null', self::show(FilterParser::parse('x eq null')));
    }

    public static function invalidFilters(): array {
        return [
            'trailing text' => ['userName eq "x" junk'],
            'unknown operator' => ['userName like "x"'],
            'missing value' => ['userName eq'],
            'unterminated string' => ['userName eq "x'],
            'unbalanced paren' => ['(userName pr'],
            'nested value path' => ['emails[type[value pr]]'],
            'gt on a boolean' => ['active gt true'],
            'co on a number' => ['x co 5'],
            'three levels' => ['a.b.c pr'],
            'empty' => [''],
            'bare word' => ['userName'],
            'not without parens' => ['not userName pr'],
            'bad urn' => ['foo:bar pr'],
            'bad character' => ['userName eq "x" & y pr'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_400_invalid_filter(string $filter): void {
        try {
            FilterParser::parse($filter);
            $this->fail("parsed '$filter'");
        } catch (ScimException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame(ScimErrorType::INVALID_FILTER, $e->scimType);
        }
    }

    public function test_patch_paths(): void {
        $path = FilterParser::parsePath('members[value eq "2819c223-7f76-453a-919d-413861904646"]');
        $this->assertSame('members', $path->attribute->name);
        $this->assertSame('value eq "2819c223-7f76-453a-919d-413861904646"', self::show($path->filter));
        $this->assertNull($path->subAttribute);

        $path = FilterParser::parsePath('emails[type eq "work"].value');
        $this->assertSame('value', $path->subAttribute);

        $path = FilterParser::parsePath('name.givenName');
        $this->assertSame('givenName', $path->attribute->subAttribute);
        $this->assertNull($path->filter);

        $path = FilterParser::parsePath('urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeNumber');
        $this->assertSame('employeeNumber', $path->attribute->name);
    }

    public function test_invalid_patch_paths_are_400_invalid_path(): void {
        foreach (['', 'members[', 'members[value eq "x"] junk', 'name.givenName[value pr]', 'a.b.c', 'members[value eq "x"].'] as $path) {
            try {
                FilterParser::parsePath($path);
                $this->fail("parsed '$path'");
            } catch (ScimException $e) {
                $this->assertSame(ScimErrorType::INVALID_PATH, $e->scimType, $path);
            }
        }
    }

    private static function show(FilterNode $node): string {
        return match (true) {
            $node instanceof Logical => '(' . self::show($node->left) . ' ' . $node->operator->value . ' ' . self::show($node->right) . ')',
            $node instanceof Negation => 'not(' . self::show($node->filter) . ')',
            $node instanceof ValuePath => $node->path . '[' . self::show($node->filter) . ']',
            $node instanceof Presence => $node->path . ' pr',
            $node instanceof Comparison => $node->path . ' ' . $node->operator->value . ' ' . json_encode($node->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
