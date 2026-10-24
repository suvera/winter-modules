<?php

declare(strict_types=1);

namespace test\winterframework\scim;

use PHPUnit\Framework\TestCase;
use dev\winterframework\scim\protocol\PatchApplier;
use dev\winterframework\scim\protocol\PatchRequest;
use dev\winterframework\scim\protocol\ResourceValidator;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;
use dev\winterframework\scim\schema\CoreSchemas;
use dev\winterframework\scim\schema\ResourceType;

class PatchApplierTest extends TestCase {
    private const string ENTERPRISE = CoreSchemas::ENTERPRISE_USER;

    private function user(): array {
        return [
            'schemas' => [CoreSchemas::USER],
            'id' => '7',
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
            'active' => true,
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true], ['value' => 'babs@home.org', 'type' => 'home']],
        ];
    }

    private function group(): array {
        return ['schemas' => [CoreSchemas::GROUP], 'id' => 'g1', 'displayName' => 'Staff', 'members' => [['value' => '1'], ['value' => '2']]];
    }

    private function patch(array $resource, array $operations, ?ResourceType $type = null): array {
        $type ??= ResourceType::user();
        $patched = new PatchApplier($type)->apply($resource, PatchRequest::fromArray(['schemas' => [PatchRequest::SCHEMA], 'Operations' => $operations]));
        return new ResourceValidator($type)->normalize($patched, $resource);
    }

    public function test_entra_capitalised_ops_and_string_booleans(): void {
        $out = $this->patch($this->user(), [['op' => 'Replace', 'path' => 'active', 'value' => 'False']]);

        $this->assertFalse($out['active']);
    }

    public function test_pathless_merge_with_dotted_and_extension_keys(): void {
        $out = $this->patch($this->user(), [
            ['op' => 'replace', 'value' => ['active' => false, 'name.givenName' => 'Babs', 'displayName' => 'Babs J']],
            ['op' => 'add', 'value' => [self::ENTERPRISE => ['employeeNumber' => '42'], 'name' => ['middleName' => 'Q']]],
        ]);

        $this->assertFalse($out['active']);
        $this->assertSame(['familyName' => 'Jensen', 'givenName' => 'Babs', 'middleName' => 'Q'], $out['name'], 'in schema order');
        $this->assertSame('Babs J', $out['displayName']);
        $this->assertSame(['employeeNumber' => '42'], $out[self::ENTERPRISE]);
        $this->assertSame([CoreSchemas::USER, self::ENTERPRISE], $out['schemas']);
    }

    public function test_full_urn_paths(): void {
        $out = $this->patch($this->user(), [['op' => 'add', 'path' => self::ENTERPRISE . ':manager.value', 'value' => '99']]);
        $this->assertSame(['manager' => ['value' => '99']], $out[self::ENTERPRISE]);

        $out = $this->patch($out, [['op' => 'remove', 'path' => self::ENTERPRISE . ':manager']]);
        $this->assertArrayNotHasKey(self::ENTERPRISE, $out);
    }

    public function test_value_filter_with_a_sub_attribute(): void {
        $out = $this->patch($this->user(), [['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'new@example.com']]);

        $this->assertSame('new@example.com', $out['emails'][0]['value']);
        $this->assertSame('babs@home.org', $out['emails'][1]['value']);
    }

    public function test_replace_with_a_filter_that_matches_nothing_is_no_target(): void {
        try {
            $this->patch($this->user(), [['op' => 'replace', 'path' => 'emails[type eq "other"].value', 'value' => 'x@y.z']]);
            $this->fail('patched');
        } catch (ScimException $e) {
            $this->assertSame(ScimErrorType::NO_TARGET, $e->scimType);
        }
    }

    public function test_group_member_ops(): void {
        $type = ResourceType::group();
        $out = $this->patch($this->group(), [['op' => 'add', 'path' => 'members', 'value' => [['value' => '2'], ['value' => '3']]]], $type);
        $this->assertSame([['value' => '1'], ['value' => '2'], ['value' => '3']], $out['members'], 'add does not duplicate');

        $out = $this->patch($out, [['op' => 'remove', 'path' => 'members[value eq "1"]']], $type);
        $this->assertSame([['value' => '2'], ['value' => '3']], $out['members']);

        $out = $this->patch($out, [['op' => 'remove', 'path' => 'members[value eq "gone"]']], $type);
        $this->assertCount(2, $out['members'], 'removing an absent member is a no-op');

        $out = $this->patch($out, [['op' => 'Remove', 'path' => 'members', 'value' => [['value' => '3']]]], $type);
        $this->assertSame([['value' => '2']], $out['members'], "Entra's remove with a value list");

        $out = $this->patch($out, [['op' => 'replace', 'path' => 'members', 'value' => []]], $type);
        $this->assertArrayNotHasKey('members', $out);
    }

    public function test_read_only_and_unknown_paths(): void {
        $cases = [
            [['op' => 'replace', 'path' => 'id', 'value' => '8'], ScimErrorType::MUTABILITY],
            [['op' => 'add', 'path' => 'groups', 'value' => [['value' => 'g']]], ScimErrorType::MUTABILITY],
            [['op' => 'add', 'path' => 'nope', 'value' => 'x'], ScimErrorType::INVALID_PATH],
            [['op' => 'add', 'path' => 'urn:x:y:z:a', 'value' => 'x'], ScimErrorType::INVALID_PATH],
            [['op' => 'replace', 'path' => 'userName[value pr]', 'value' => 'x'], ScimErrorType::INVALID_PATH],
            [['op' => 'remove'], ScimErrorType::NO_TARGET],
            [['op' => 'move', 'path' => 'userName'], ScimErrorType::INVALID_SYNTAX],
            [['op' => 'remove', 'path' => 'userName'], ScimErrorType::INVALID_VALUE],
            [['op' => 'replace', 'path' => 'active', 'value' => 'maybe'], ScimErrorType::INVALID_VALUE],
        ];
        foreach ($cases as [$operation, $type]) {
            try {
                $this->patch($this->user(), [$operation]);
                $this->fail(json_encode($operation));
            } catch (ScimException $e) {
                $this->assertSame($type, $e->scimType, json_encode($operation));
            }
        }
    }

    public function test_validator_drops_read_only_echoes_and_checks_immutable(): void {
        $validator = new ResourceValidator(ResourceType::group());
        $out = $validator->normalize([
            'schemas' => [CoreSchemas::GROUP],
            'id' => 'client-chosen',
            'meta' => ['created' => 'x'],
            'displayName' => 'Staff',
            'members' => ['value' => '5', 'display' => 'Five'],
            'unknown' => 1,
        ]);
        $this->assertSame(['schemas' => [CoreSchemas::GROUP], 'displayName' => 'Staff', 'members' => [['value' => '5']]], $out);

        try {
            $validator->normalize(['displayName' => 'x']);
            $this->fail('no schemas');
        } catch (ScimException $e) {
            $this->assertSame(ScimErrorType::INVALID_SYNTAX, $e->scimType);
        }
    }
}
