<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

use dev\winterframework\scim\schema\AttributeType as T;

/**
 * The RFC 7643 schemas: the common attributes (§3.1), User and Group (§4,
 * §8.7.1) and the Enterprise User extension (§4.3), as the RFC defines them.
 */
final class CoreSchemas {
    public const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    public const string ENTERPRISE_USER = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /** @return list<AttributeDefinition> `id`, `externalId` and `meta`, which every resource has */
    public static function commonAttributes(): array {
        return [
            new AttributeDefinition('id', description: 'Unique identifier for the SCIM resource as defined by the service provider.',
                caseExact: true, mutability: Mutability::READ_ONLY, returned: Returned::ALWAYS, uniqueness: Uniqueness::SERVER),
            new AttributeDefinition('externalId', description: 'Identifier for the resource as defined by the provisioning client.',
                caseExact: true),
            new AttributeDefinition('meta', T::COMPLEX, description: 'Resource metadata.', mutability: Mutability::READ_ONLY,
                subAttributes: [
                    self::readOnly('resourceType', 'The name of the resource type of the resource.', caseExact: true),
                    self::readOnly('created', 'The date and time the resource was added.', T::DATE_TIME),
                    self::readOnly('lastModified', 'The most recent date and time the resource was modified.', T::DATE_TIME),
                    self::readOnly('location', 'The URI of the resource being returned.', T::REFERENCE, caseExact: true, referenceTypes: ['uri']),
                    self::readOnly('version', 'The version of the resource being returned.', caseExact: true),
                ]),
        ];
    }

    public static function user(): ResourceSchema {
        return new ResourceSchema(self::USER, 'User', 'User Account', [
            new AttributeDefinition('userName', description: 'Unique identifier for the User, typically used by the user to directly authenticate to the service provider.',
                required: true, uniqueness: Uniqueness::SERVER),
            new AttributeDefinition('name', T::COMPLEX, description: "The components of the user's real name.", subAttributes: [
                self::text('formatted', 'The full name, including all middle names, titles, and suffixes as appropriate, formatted for display.'),
                self::text('familyName', 'The family name of the User.'),
                self::text('givenName', 'The given name of the User.'),
                self::text('middleName', 'The middle name(s) of the User.'),
                self::text('honorificPrefix', 'The honorific prefix(es) of the User.'),
                self::text('honorificSuffix', 'The honorific suffix(es) of the User.'),
            ]),
            self::text('displayName', 'The name of the User, suitable for display to end-users.'),
            self::text('nickName', 'The casual way to address the user in real life.'),
            new AttributeDefinition('profileUrl', T::REFERENCE, description: "A fully qualified URL pointing to a page representing the User's online profile.", referenceTypes: ['external']),
            self::text('title', "The user's title, such as \"Vice President.\""),
            self::text('userType', 'Used to identify the relationship between the organization and the user.'),
            self::text('preferredLanguage', "Indicates the User's preferred written or spoken language."),
            self::text('locale', "Used to indicate the User's default location for purposes of localizing items such as currency, date time format, or numerical representations."),
            self::text('timezone', "The User's time zone in the 'Olson' time zone database format, e.g., 'America/Los_Angeles'."),
            new AttributeDefinition('active', T::BOOLEAN, description: "A Boolean value indicating the User's administrative status."),
            new AttributeDefinition('password', description: "The User's cleartext password.", mutability: Mutability::WRITE_ONLY, returned: Returned::NEVER),
            self::multi('emails', 'Email addresses for the user.', ['work', 'home', 'other']),
            self::multi('phoneNumbers', 'Phone numbers for the User.', ['work', 'home', 'mobile', 'fax', 'pager', 'other']),
            self::multi('ims', 'Instant messaging addresses for the User.', ['aim', 'gtalk', 'icq', 'xmpp', 'msn', 'skype', 'qq', 'yahoo']),
            self::multi('photos', 'URLs of photos of the User.', ['photo', 'thumbnail'], T::REFERENCE),
            new AttributeDefinition('addresses', T::COMPLEX, multiValued: true, description: 'A physical mailing address for this User.', subAttributes: [
                self::text('formatted', 'The full mailing address, formatted for display or use with a mailing label.'),
                self::text('streetAddress', 'The full street address component.'),
                self::text('locality', 'The city or locality component.'),
                self::text('region', 'The state or region component.'),
                self::text('postalCode', 'The zip code or postal code component.'),
                self::text('country', 'The country name component.'),
                new AttributeDefinition('type', description: "A label indicating the attribute's function, e.g., 'work' or 'home'.", canonicalValues: ['work', 'home', 'other']),
                new AttributeDefinition('primary', T::BOOLEAN, description: "A Boolean value indicating the 'primary' or preferred attribute value for this attribute."),
            ]),
            new AttributeDefinition('groups', T::COMPLEX, multiValued: true, description: 'A list of groups to which the user belongs.',
                mutability: Mutability::READ_ONLY, subAttributes: [
                    self::readOnly('value', "The identifier of the User's group."),
                    self::readOnly('$ref', 'The URI of the corresponding Group resource.', T::REFERENCE, referenceTypes: ['User', 'Group']),
                    self::readOnly('display', 'A human-readable name, primarily used for display purposes.'),
                    new AttributeDefinition('type', description: "A label indicating the attribute's function.", mutability: Mutability::READ_ONLY,
                        canonicalValues: ['direct', 'indirect']),
                ]),
            self::multi('entitlements', 'A list of entitlements for the User that represent a thing the User has.'),
            self::multi('roles', 'A list of roles for the User that collectively represent who the User is.'),
            self::multi('x509Certificates', 'A list of certificates issued to the User.', [], T::BINARY),
        ]);
    }

    public static function group(): ResourceSchema {
        return new ResourceSchema(self::GROUP, 'Group', 'Group', [
            new AttributeDefinition('displayName', description: 'A human-readable name for the Group.', required: true),
            new AttributeDefinition('members', T::COMPLEX, multiValued: true, description: 'A list of members of the Group.', subAttributes: [
                new AttributeDefinition('value', description: 'Identifier of the member of this Group.', mutability: Mutability::IMMUTABLE),
                new AttributeDefinition('$ref', T::REFERENCE, description: 'The URI corresponding to a SCIM resource that is a member of this Group.',
                    mutability: Mutability::IMMUTABLE, referenceTypes: ['User', 'Group']),
                new AttributeDefinition('display', description: 'A human-readable name, primarily used for display purposes.', mutability: Mutability::READ_ONLY),
                new AttributeDefinition('type', description: 'A label indicating the type of resource, e.g., \'User\' or \'Group\'.',
                    mutability: Mutability::IMMUTABLE, canonicalValues: ['User', 'Group']),
            ]),
        ]);
    }

    public static function enterpriseUser(): ResourceSchema {
        return new ResourceSchema(self::ENTERPRISE_USER, 'EnterpriseUser', 'Enterprise User', [
            self::text('employeeNumber', 'Numeric or alphanumeric identifier assigned to a person, typically based on order of hire or association with an organization.'),
            self::text('costCenter', 'Identifies the name of a cost center.'),
            self::text('organization', 'Identifies the name of an organization.'),
            self::text('division', 'Identifies the name of a division.'),
            self::text('department', 'Identifies the name of a department.'),
            new AttributeDefinition('manager', T::COMPLEX, description: "The User's manager.", subAttributes: [
                self::text('value', 'The id of the SCIM resource representing the User\'s manager.'),
                new AttributeDefinition('$ref', T::REFERENCE, description: 'The URI of the SCIM resource representing the User\'s manager.', referenceTypes: ['User']),
                self::readOnly('displayName', 'The displayName of the User\'s manager.'),
            ]),
        ]);
    }

    private static function text(string $name, string $description): AttributeDefinition {
        return new AttributeDefinition($name, description: $description);
    }

    /** @param list<string> $referenceTypes */
    private static function readOnly(string $name, string $description, T $type = T::STRING, bool $caseExact = false, array $referenceTypes = []): AttributeDefinition {
        return new AttributeDefinition($name, $type, description: $description, caseExact: $caseExact,
            mutability: Mutability::READ_ONLY, referenceTypes: $referenceTypes);
    }

    /**
     * The usual `value`/`display`/`type`/`primary` multi-valued attribute.
     *
     * @param list<string> $types canonical `type` values
     */
    private static function multi(string $name, string $description, array $types = [], T $valueType = T::STRING): AttributeDefinition {
        return new AttributeDefinition($name, T::COMPLEX, multiValued: true, description: $description, subAttributes: [
            new AttributeDefinition('value', $valueType, description: "The attribute's significant value.",
                referenceTypes: $valueType === T::REFERENCE ? ['external'] : []),
            self::text('display', 'A human-readable name, primarily used for display purposes.'),
            new AttributeDefinition('type', description: "A label indicating the attribute's function.", canonicalValues: $types),
            new AttributeDefinition('primary', T::BOOLEAN, description: "A Boolean value indicating the 'primary' or preferred attribute value for this attribute."),
        ]);
    }
}
