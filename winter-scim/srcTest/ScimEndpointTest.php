<?php

declare(strict_types=1);

namespace test\winterframework\scim;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use PHPUnit\Framework\TestCase;
use dev\winterframework\scim\filter\FilterMatcher;
use dev\winterframework\scim\protocol\ListQuery;
use dev\winterframework\scim\protocol\PatchRequest;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;
use dev\winterframework\scim\schema\CoreSchemas;
use dev\winterframework\scim\schema\ResourceType;
use dev\winterframework\scim\server\ScimEndpoint;
use dev\winterframework\scim\server\ScimPage;
use dev\winterframework\scim\server\ScimResourceService;

class ScimEndpointTest extends TestCase {
    private const string BASE = 'https://app.example.test/scim/v2';

    private ScimEndpoint $endpoint;

    protected function setUp(): void {
        $this->endpoint = new ScimEndpoint(self::BASE, [new InMemoryUserService()], 2);
    }

    public function test_create_get_list_patch_delete(): void {
        $created = $this->call($this->endpoint->create('Users', $this->request(body: [
            'schemas' => [CoreSchemas::USER],
            'userName' => 'bjensen@example.com',
            'password' => 's3cret',
            'active' => true,
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work']],
        ])));
        $this->assertSame(201, $created[0]);
        $this->assertSame(ScimEndpoint::MEDIA_TYPE, $created[2]);
        $user = $created[1];
        $this->assertSame('1', $user['id']);
        $this->assertSame([CoreSchemas::USER], $user['schemas']);
        $this->assertSame(['resourceType' => 'User', 'created' => '2026-01-01T00:00:00Z', 'location' => self::BASE . '/Users/1'], $user['meta']);
        $this->assertArrayNotHasKey('password', $user, 'returned: never');

        $this->call($this->endpoint->create('Users', $this->request(body: ['schemas' => [CoreSchemas::USER], 'userName' => 'second'])));
        $this->call($this->endpoint->create('Users', $this->request(body: ['schemas' => [CoreSchemas::USER], 'userName' => 'third'])));

        [$status, $list] = $this->call($this->endpoint->list('Users', $this->request(['filter' => 'userName sw "B"', 'attributes' => 'userName'])));
        $this->assertSame(200, $status);
        $this->assertSame(1, $list['totalResults']);
        $this->assertSame(['schemas' => [CoreSchemas::USER], 'id' => '1', 'userName' => 'bjensen@example.com'], $list['Resources'][0]);

        [, $page] = $this->call($this->endpoint->list('Users', $this->request(['count' => '50'])));
        $this->assertSame(3, $page['totalResults']);
        $this->assertSame(2, $page['itemsPerPage'], 'count is capped at maxResults');

        [, $patched] = $this->call($this->endpoint->patch('Users', '1', $this->request(body: [
            'schemas' => [PatchRequest::SCHEMA],
            'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']],
        ])));
        $this->assertFalse($patched['active']);

        $this->assertSame(204, $this->call($this->endpoint->delete('Users', '1'))[0]);
        [$status, $error] = $this->call($this->endpoint->get('Users', '1', $this->request()));
        $this->assertSame(404, $status);
        $this->assertSame(['schemas' => [ScimException::SCHEMA], 'status' => '404', 'detail' => "User '1' not found"], $error);
    }

    public function test_errors_are_scim_error_bodies(): void {
        [$status, $error, $type] = $this->call($this->endpoint->list('Users', $this->request(['filter' => 'userName eq'])));
        $this->assertSame(400, $status);
        $this->assertSame('invalidFilter', $error['scimType']);
        $this->assertSame(ScimEndpoint::MEDIA_TYPE, $type);

        $request = $this->createStub(HttpRequest::class);
        $request->method('getRawBody')->willReturn('{not json');
        $request->method('getQueryParams')->willReturn([]);
        $this->assertSame('invalidSyntax', $this->call($this->endpoint->create('Users', $request))[1]['scimType']);

        $this->call($this->endpoint->create('Users', $this->request(body: ['schemas' => [CoreSchemas::USER], 'userName' => 'dup'])));
        [$status, $error] = $this->call($this->endpoint->create('Users', $this->request(body: ['schemas' => [CoreSchemas::USER], 'userName' => 'DUP'])));
        $this->assertSame(409, $status);
        $this->assertSame('uniqueness', $error['scimType']);

        $this->assertSame(404, $this->call($this->endpoint->list('Widgets', $this->request()))[0]);
        $this->assertSame(501, $this->call($this->endpoint->me())[0]);
    }

    public function test_discovery_endpoints(): void {
        [, $config] = $this->call($this->endpoint->serviceProviderConfig());
        $this->assertTrue($config['patch']['supported']);
        $this->assertSame(2, $config['filter']['maxResults']);
        $this->assertFalse($config['bulk']['supported']);

        [, $types] = $this->call($this->endpoint->resourceTypes());
        $this->assertSame('/Users', $types['Resources'][0]['endpoint']);
        $this->assertSame([['schema' => CoreSchemas::ENTERPRISE_USER, 'required' => false]], $types['Resources'][0]['schemaExtensions']);

        [, $schemas] = $this->call($this->endpoint->schemas());
        $this->assertSame([CoreSchemas::USER, CoreSchemas::ENTERPRISE_USER], array_column($schemas['Resources'], 'id'));

        [$status, $user] = $this->call($this->endpoint->schemas(strtolower(CoreSchemas::USER)));
        $this->assertSame(200, $status);
        $this->assertSame('userName', $user['attributes'][0]['name']);
        $this->assertSame(self::BASE . '/Schemas/' . CoreSchemas::USER, $user['meta']['location']);
    }

    public function test_create_with_attributes_still_sends_the_location(): void {
        $response = $this->endpoint->create('Users', $this->request(['attributes' => 'userName'], ['schemas' => [CoreSchemas::USER], 'userName' => 'carol']));

        $this->assertSame(201, $response->getStatus()->getValue());
        $this->assertSame(['schemas' => [CoreSchemas::USER], 'id' => '1', 'userName' => 'carol'], $response->getBody());
        $this->assertSame(self::BASE . '/Users/1', $response->getHeaders()->getFirst('Location'));
    }

    public function test_search_request(): void {
        $this->call($this->endpoint->create('Users', $this->request(body: ['schemas' => [CoreSchemas::USER], 'userName' => 'alice'])));

        [, $list] = $this->call($this->endpoint->search('Users', $this->request(body: [
            'schemas' => [ListQuery::SEARCH_SCHEMA], 'filter' => 'userName eq "ALICE"', 'excludedAttributes' => ['meta'],
        ])));
        $this->assertSame(1, $list['totalResults']);
        $this->assertArrayNotHasKey('meta', $list['Resources'][0]);
    }

    /** @return array{0: int, 1: array|null, 2: string|null} */
    private function call(ResponseEntity $response): array {
        return [$response->getStatus()->getValue(), $response->getBody(), $response->getHeaders()->getContentType()];
    }

    private function request(array $query = [], ?array $body = null): HttpRequest {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getRawBody')->willReturn($body === null ? '' : json_encode($body));
        return $request;
    }
}

/**
 * A reference service: the whole contract in a few lines, over an array. Like
 * a database-backed service it stores no `schemas`; the endpoint adds them.
 */
final class InMemoryUserService implements ScimResourceService {
    /** @var array<string, array> */
    private array $users = [];
    private int $next = 1;

    public function resourceType(): ResourceType {
        return ResourceType::user();
    }

    public function list(ListQuery $query): ScimPage {
        $matcher = new FilterMatcher($this->resourceType());
        $matched = array_values(array_filter($this->users, fn(array $u) => $query->filter === null || $matcher->matches($query->filter, $u)));
        return new ScimPage(array_slice($matched, $query->offset(), $query->count), count($matched));
    }

    public function get(string $id): ?array {
        return $this->users[$id] ?? null;
    }

    public function create(array $resource): array {
        foreach ($this->users as $user) {
            if (strcasecmp($user['userName'], $resource['userName']) === 0) {
                throw new ScimException(409, ScimErrorType::UNIQUENESS, 'userName is taken');
            }
        }
        unset($resource['password'], $resource['schemas']);
        $id = (string)$this->next++;
        return $this->users[$id] = ['id' => $id] + $resource + ['meta' => ['created' => '2026-01-01T00:00:00Z']];
    }

    public function replace(string $id, array $resource): array {
        unset($resource['schemas']);
        return $this->users[$id] = ['id' => $id] + $resource + ['meta' => $this->users[$id]['meta']];
    }

    public function delete(string $id): void {
        unset($this->users[$id]);
    }
}
