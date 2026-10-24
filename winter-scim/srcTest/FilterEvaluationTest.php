<?php

declare(strict_types=1);

namespace test\winterframework\scim;

use PHPUnit\Framework\TestCase;
use dev\winterframework\scim\filter\DqlFilterConverter;
use dev\winterframework\scim\filter\FilterMatcher;
use dev\winterframework\scim\filter\FilterParser;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;
use dev\winterframework\scim\schema\ResourceType;

class FilterEvaluationTest extends TestCase {
    private const array FIELDS = [
        'username' => 'u.email',
        'externalid' => 'u.externalId',
        'title' => 'u.title',
        'active' => 'u.active',
        'emails.value' => 'u.email',
        'emails.type' => 'u.emailType',
        'meta.lastmodified' => 'u.updatedAt',
        'urn:ietf:params:scim:schemas:extension:enterprise:2.0:user:employeenumber' => 'u.employeeNumber',
    ];

    private function dql(string $filter): array {
        $where = new DqlFilterConverter(ResourceType::user(), self::FIELDS)->convert(FilterParser::parse($filter));
        return [$where->dql, $where->parameters];
    }

    public function test_eq_binds_the_value_as_given(): void {
        $this->assertSame(['u.email = :scim_1', ['scim_1' => 'BJensen@Example.com']], $this->dql('userName eq "BJensen@Example.com"'));
    }

    public function test_sw_ew_co_put_the_wildcard_on_the_right_side_and_escape(): void {
        $this->assertSame(["u.email LIKE :scim_1 ESCAPE '!'", ['scim_1' => 'J%']], $this->dql('userName sw "J"'));
        $this->assertSame(["u.email LIKE :scim_1 ESCAPE '!'", ['scim_1' => '%.com']], $this->dql('userName ew ".com"'));
        $this->assertSame(["u.email LIKE :scim_1 ESCAPE '!'", ['scim_1' => '%a!%b!_c!!d%']], $this->dql('userName co "a%b_c!d"'));
    }

    public function test_pr_is_a_real_presence_check(): void {
        $this->assertSame(["(u.title IS NOT NULL AND u.title <> '')", []], $this->dql('title pr'));
        $this->assertSame(['u.active IS NOT NULL', []], $this->dql('active pr'));
    }

    public function test_ne_includes_missing_values_and_null_comparisons(): void {
        $this->assertSame(['(u.title <> :scim_1 OR u.title IS NULL)', ['scim_1' => 'x']], $this->dql('title ne "x"'));
        $this->assertSame(['u.title IS NULL', []], $this->dql('title eq null'));
        $this->assertSame(['u.title IS NOT NULL', []], $this->dql('title ne null'));
    }

    public function test_date_times_compare_as_utc_instants(): void {
        $this->assertSame(['u.updatedAt > :scim_1', ['scim_1' => '2011-05-13 02:42:34']], $this->dql('meta.lastModified gt "2011-05-13T04:42:34+02:00"'));
    }

    public function test_logic_value_paths_and_extensions(): void {
        [$dql, $params] = $this->dql('not (active eq false) and (emails[type eq "work" and value co "@x"] or urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeNumber eq "7")');

        $this->assertSame("(NOT (u.active = :scim_1) AND ((u.emailType = :scim_2 AND u.email LIKE :scim_3 ESCAPE '!') OR u.employeeNumber = :scim_4))", $dql);
        $this->assertSame(['scim_1' => false, 'scim_2' => 'work', 'scim_3' => '%@x%', 'scim_4' => '7'], $params);
    }

    public function test_a_multi_valued_attribute_alone_means_its_value(): void {
        $this->assertSame(["u.email LIKE :scim_1 ESCAPE '!'", ['scim_1' => '%example.com%']], $this->dql('emails co "example.com"'));
    }

    public function test_unmapped_unknown_and_mistyped_attributes_are_rejected(): void {
        foreach (['nickName eq "x"', 'nope eq "x"', 'active eq "yes"', 'meta.lastModified gt "yesterday"', 'name pr', 'urn:x:y:z eq "1"'] as $filter) {
            try {
                $this->dql($filter);
                $this->fail("converted '$filter'");
            } catch (ScimException $e) {
                $this->assertSame(ScimErrorType::INVALID_FILTER, $e->scimType, $filter);
            }
        }
    }

    public function test_matcher(): void {
        $user = [
            'userName' => 'BJensen',
            'active' => true,
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work'], ['value' => 'babs@jensen.org', 'type' => 'home']],
            'meta' => ['lastModified' => '2011-05-13T04:42:34Z'],
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User' => ['employeeNumber' => '701984'],
        ];
        $matcher = new FilterMatcher(ResourceType::user());
        $yes = [
            'username eq "bjensen"',
            'emails[type eq "work" and value ew "example.com"]',
            'emails co "jensen.org"',
            'emails.type eq "home"',
            'meta.lastModified gt "2011-05-13T04:00:00Z"',
            'active eq true and not (title pr)',
            'title ne "x"',
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeNumber sw "70"',
            'id eq null',
        ];
        $no = [
            'emails[type eq "home" and value ew "example.com"]',
            'active eq false',
            'meta.lastModified lt "2011-05-13T04:42:34Z"',
            'title pr',
            'externalId eq "x"',
        ];
        foreach ($yes as $filter) {
            $this->assertTrue($matcher->matches(FilterParser::parse($filter), $user), $filter);
        }
        foreach ($no as $filter) {
            $this->assertFalse($matcher->matches(FilterParser::parse($filter), $user), $filter);
        }
    }

    public function test_case_exact_attributes_compare_exactly_in_memory(): void {
        $matcher = new FilterMatcher(ResourceType::user());

        $this->assertFalse($matcher->matches(FilterParser::parse('externalId eq "ABC"'), ['externalId' => 'abc']));
        $this->assertTrue($matcher->matches(FilterParser::parse('externalId eq "abc"'), ['externalId' => 'abc']));
    }
}
