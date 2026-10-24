<?php

declare(strict_types=1);

namespace dev\winterframework\scim\server;

use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use JsonException;
use dev\winterframework\scim\protocol\AttributeSelector;
use dev\winterframework\scim\protocol\ListQuery;
use dev\winterframework\scim\protocol\ListResponse;
use dev\winterframework\scim\protocol\PatchApplier;
use dev\winterframework\scim\protocol\PatchRequest;
use dev\winterframework\scim\protocol\ResourceValidator;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;
use dev\winterframework\scim\schema\ResourceType;
use dev\winterframework\scim\schema\ServiceProviderConfig;
use Throwable;

/**
 * The SCIM 2.0 protocol over an app's {@see ScimResourceService}s
 * (RFC 7644 §3): every endpoint a SCIM client calls, as methods a thin
 * controller delegates to. Each returns `application/scim+json`, errors
 * included (the SCIM error body, never the app's own error shape).
 *
 * Request bodies are read raw, because Winter's #[RequestBody] parses only
 * `application/json` and clients send `application/scim+json`.
 *
 * Not supported (ServiceProviderConfig says so): /Bulk, sorting, ETags,
 * password change. /Me answers 501: a SCIM token belongs to a client, not a person.
 */
final class ScimEndpoint {
    use Wlf4p;

    public const string MEDIA_TYPE = 'application/scim+json';

    /** @var array<string, ScimResourceService> by resource type id and endpoint name, lower case (`user`, `users`) */
    private readonly array $services;

    /** @param list<ScimResourceService> $services */
    public function __construct(
        private readonly string $baseUrl,
        array $services,
        private readonly int $maxResults = 200,
    ) {
        $byId = [];
        foreach ($services as $service) {
            $byId[strtolower($service->resourceType()->id)] = $service;
            $byId[strtolower(ltrim($service->resourceType()->endpoint, '/'))] = $service;
        }
        $this->services = $byId;
    }

    public function serviceProviderConfig(): ResponseEntity {
        return self::json(200, new ServiceProviderConfig($this->maxResults)->toArray($this->baseUrl));
    }

    public function resourceTypes(?string $id = null): ResponseEntity {
        return $this->handle(function () use ($id) {
            if ($id !== null) {
                return self::json(200, $this->service($id)->resourceType()->toArray($this->baseUrl));
            }
            $types = [];
            foreach ($this->services as $service) {
                $types[$service->resourceType()->id] = $service->resourceType()->toArray($this->baseUrl);
            }
            $types = array_values($types);
            return self::json(200, ListResponse::toArray($types, count($types), 1));
        });
    }

    public function schemas(?string $id = null): ResponseEntity {
        return $this->handle(function () use ($id) {
            $schemas = [];
            foreach ($this->services as $service) {
                $type = $service->resourceType();
                foreach ([$type->schema, ...$type->extensions] as $schema) {
                    $schemas[$schema->id] = $schema->toArray($this->baseUrl);
                }
            }
            if ($id === null) {
                return self::json(200, ListResponse::toArray(array_values($schemas), count($schemas), 1));
            }
            foreach ($schemas as $schemaId => $schema) {
                if (strcasecmp($schemaId, $id) === 0) {
                    return self::json(200, $schema);
                }
            }
            throw ScimException::notFound('Schema', $id);
        });
    }

    public function me(): ResponseEntity {
        return self::error(new ScimException(501, null, '/Me is not supported: SCIM tokens belong to provisioning clients'));
    }

    public function list(string $type, HttpRequest $request): ResponseEntity {
        return $this->handle(fn() => $this->page($this->service($type), ListQuery::fromQuery($request->getQueryParams(), $this->maxResults)));
    }

    /** `POST /Users/.search` */
    public function search(string $type, HttpRequest $request): ResponseEntity {
        return $this->handle(fn() => $this->page($this->service($type), ListQuery::fromSearchRequest(self::body($request), $this->maxResults)));
    }

    public function get(string $type, string $id, HttpRequest $request): ResponseEntity {
        return $this->handle(function () use ($type, $id, $request) {
            $service = $this->service($type);
            $resource = $service->get($id) ?? throw ScimException::notFound($service->resourceType()->id, $id);
            return self::json(200, $this->present($service, $resource, ListQuery::projection($request->getQueryParams())));
        });
    }

    public function create(string $type, HttpRequest $request): ResponseEntity {
        return $this->handle(function () use ($type, $request) {
            $service = $this->service($type);
            $resource = new ResourceValidator($service->resourceType())->normalize(self::body($request));
            $created = $this->present($service, $service->create($resource), ListQuery::projection($request->getQueryParams()));
            return self::json(201, $created)->withHeader('Location', $created['meta']['location']);
        });
    }

    /** `PUT` */
    public function replace(string $type, string $id, HttpRequest $request): ResponseEntity {
        return $this->handle(function () use ($type, $id, $request) {
            $service = $this->service($type);
            $existing = $service->get($id) ?? throw ScimException::notFound($service->resourceType()->id, $id);
            $resource = new ResourceValidator($service->resourceType())->normalize(self::body($request), $existing);
            return self::json(200, $this->present($service, $service->replace($id, $resource), ListQuery::projection($request->getQueryParams())));
        });
    }

    public function patch(string $type, string $id, HttpRequest $request): ResponseEntity {
        return $this->handle(function () use ($type, $id, $request) {
            $service = $this->service($type);
            $resourceType = $service->resourceType();
            $existing = $service->get($id) ?? throw ScimException::notFound($resourceType->id, $id);
            $existing = ['schemas' => self::schemasOf($resourceType, $existing)] + $existing;
            $patched = new PatchApplier($resourceType)->apply($existing, PatchRequest::fromArray(self::body($request)));
            $resource = new ResourceValidator($resourceType)->normalize($patched, $existing);
            return self::json(200, $this->present($service, $service->replace($id, $resource), ListQuery::projection($request->getQueryParams())));
        });
    }

    public function delete(string $type, string $id): ResponseEntity {
        return $this->handle(function () use ($type, $id) {
            $service = $this->service($type);
            if ($service->get($id) === null) {
                throw ScimException::notFound($service->resourceType()->id, $id);
            }
            $service->delete($id);
            return ResponseEntity::noContent();
        });
    }

    /** A SCIM error response; for the app's own checks, such as a missing or wrong bearer token. */
    public static function error(ScimException $e): ResponseEntity {
        return self::json($e->status, $e->toArray());
    }

    private function page(ScimResourceService $service, ListQuery $query): ResponseEntity {
        $page = $service->list($query);
        $resources = array_map(fn(array $r) => $this->present($service, $r, $query), array_slice($page->resources, 0, $query->count));
        return self::json(200, ListResponse::toArray($resources, $page->totalResults, $query->startIndex));
    }

    /**
     * Adds `schemas` and `meta` and applies the projection.
     *
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function present(ScimResourceService $service, array $resource, ListQuery $projection): array {
        $type = $service->resourceType();
        $schemas = self::schemasOf($type, $resource);
        $id = (string)($resource['id'] ?? '');
        $meta = is_array($resource['meta'] ?? null) ? $resource['meta'] : [];
        unset($resource['schemas'], $resource['meta']);
        $out = ['schemas' => $schemas, 'id' => $id] + $resource;
        $out['meta'] = ['resourceType' => $type->id] + $meta
            + ['location' => rtrim($this->baseUrl, '/') . $type->endpoint . '/' . rawurlencode($id)];
        return new AttributeSelector($type)->select($out, $projection->attributes, $projection->excludedAttributes);
    }

    /** @return list<string> the core schema and the extensions $resource carries */
    private static function schemasOf(ResourceType $type, array $resource): array {
        $schemas = [$type->schema->id];
        foreach ($type->extensions as $extension) {
            if (isset($resource[$extension->id])) {
                $schemas[] = $extension->id;
            }
        }
        return $schemas;
    }

    private function service(string $type): ScimResourceService {
        return $this->services[strtolower($type)] ?? throw new ScimException(404, null, "Unknown resource type '$type'");
    }

    /** @param callable(): ResponseEntity $action */
    private function handle(callable $action): ResponseEntity {
        try {
            return $action();
        } catch (ScimException $e) {
            return self::error($e);
        } catch (Throwable $e) {
            self::logException($e, 'SCIM request failed');
            return self::error(new ScimException(500, null, 'Internal error'));
        }
    }

    /** @return array<string, mixed> */
    private static function body(HttpRequest $request): array {
        try {
            $body = json_decode($request->getRawBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, 'The body is not valid JSON');
        }
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, 'The body must be a JSON object');
        }
        return $body;
    }

    private static function json(int $status, array $body): ResponseEntity {
        $response = ResponseEntity::status(HttpStatus::getStatus($status))->withContentType(self::MEDIA_TYPE);
        $response->setBody($body);
        return $response;
    }
}
