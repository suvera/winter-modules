# Winter Boot Module - SCIM 2.0

A SCIM 2.0 server library (RFC 7643, RFC 7644) for Winter Boot apps: the
protocol, the core schemas, filters, PATCH and the discovery endpoints. The app
supplies storage through one interface per resource type and a thin controller.
No extra Composer or `ext-*` dependency.

It follows the design of [suvera/scim2-sdk](https://github.com/suvera/scim2-sdk)
and [suvera/goScim2](https://github.com/suvera/goScim2) (filter grammar, listener
events, column-mapping converter), with a hand-written parser that builds a
syntax tree instead of an ANTLR one.

## Contents

| Namespace | Classes |
| --- | --- |
| `filter\` | `FilterParser` (filters and PATCH paths into `FilterNode` trees: `Comparison`, `Presence`, `Logical`, `Negation`, `ValuePath`), `AttributePath`, `PatchPath`, `DqlFilterConverter` (a filter as a Doctrine DQL condition over a field map), `FilterMatcher` (a filter against a resource array), `ComparisonValue` |
| `schema\` | `CoreSchemas` (User, Group, Enterprise User, the common attributes), `ResourceType`, `ResourceSchema`, `AttributeDefinition` and its enums, `ServiceProviderConfig` |
| `protocol\` | `ScimException` + `ScimErrorType`, `ListQuery`, `ListResponse`, `PatchRequest` / `PatchOperation`, `PatchApplier`, `ResourceValidator`, `AttributeSelector`, `Keys` |
| `server\` | `ScimEndpoint` (every endpoint as a method returning `application/scim+json`), `ScimResourceService` (what the app implements), `ScimPage` |

## Usage

Implement `ScimResourceService` for each resource type (list with the filter
and paging, get, create, replace, delete), then delegate from a controller:

```php
#[RestController]
#[RequestMapping(path: '/scim/v2')]
class ScimController {
    #[Autowired] private ScimUserService $users;     // implements ScimResourceService
    #[Autowired] private ScimGroupService $groups;

    private function endpoint(): ScimEndpoint {
        return new ScimEndpoint('https://app.example.com/scim/v2', [$this->users, $this->groups], maxResults: 200);
    }

    #[GetMapping(path: '/{type}')]
    public function list(HttpRequest $request, #[PathVariable] string $type): ResponseEntity {
        return $this->endpoint()->list($type, $request);
    }
    // get, create (POST), replace (PUT), patch (PATCH), delete, search (POST /{type}/.search),
    // serviceProviderConfig, resourceTypes, schemas, me
}
```

Authenticate the bearer token in an interceptor and answer failures with
`ScimEndpoint::error(new ScimException(401, null, 'Unauthorized'))`.

In `list()`, turn the filter into a DQL condition:

```php
$where = new DqlFilterConverter(ResourceType::user(), [
    'username' => 'u.email',
    'externalid' => 'u.externalId',
    'active' => 'u.active',
    'meta.lastmodified' => 'u.updatedAt',
])->convert($query->filter);
```

An attribute the map does not list is a 400 `invalidFilter`. Columns of
attributes that are not `caseExact` must use a case-insensitive collation.

## Behaviour

- **Filters:** keywords are case-insensitive; `and` binds tighter than `or`;
  free whitespace; JSON string escapes; signed and decimal numbers; schema URN
  paths; `emails[type eq "work"].value eq "x"` (Entra ID). `sw` / `ew` / `co`
  escape `%`, `_` and `!` (`ESCAPE '!'`); `pr` means not null (and not empty
  for strings); `ne` includes rows without a value; dateTime attributes compare
  as UTC instants by their schema type.
- **PATCH:** case-insensitive `op` (Entra sends `Replace`); path-less values
  whose keys are paths or extension URNs; value filters with a sub-attribute;
  group member add / remove / replace; `remove` with a value list (Entra);
  `noTarget` when a `replace` filter matches nothing, a no-op when a `remove`
  filter does.
- **Validation:** read-only attributes clients echo back are dropped, unknown
  attributes ignored, `"True"` / `"false"` strings accepted for booleans,
  immutable changes and missing required attributes rejected.
- **Not supported** (ServiceProviderConfig says so): `/Bulk`, sorting, ETags,
  password change. `/Me` answers 501.

## Tests

`winter-scim/srcTest/`: run `vendor/bin/phpunit --testsuite scim` from the repo
root. `ScimEndpointTest` holds a complete in-memory `ScimResourceService`.
