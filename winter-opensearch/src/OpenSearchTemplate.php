<?php
declare(strict_types=1);

namespace dev\winterframework\opensearch;

use OpenSearch\Client;
use OpenSearch\Namespaces\AsyncSearchNamespace;
use OpenSearch\Namespaces\CatNamespace;
use OpenSearch\Namespaces\ClusterNamespace;
use OpenSearch\Namespaces\DanglingIndicesNamespace;
use OpenSearch\Namespaces\DataFrameTransformDeprecatedNamespace;
use OpenSearch\Namespaces\IndicesNamespace;
use OpenSearch\Namespaces\IngestNamespace;
use OpenSearch\Namespaces\MonitoringNamespace;
use OpenSearch\Namespaces\NodesNamespace;
use OpenSearch\Namespaces\SearchableSnapshotsNamespace;
use OpenSearch\Namespaces\SecurityNamespace;
use OpenSearch\Namespaces\SnapshotNamespace;
use OpenSearch\Namespaces\SqlNamespace;
use OpenSearch\Namespaces\SslNamespace;
use OpenSearch\Namespaces\TasksNamespace;

class OpenSearchTemplate {

    private Client $client;

    public function __construct(Client $client) {
        $this->client = $client;
    }

    public function getClient(): Client {
        return $this->client;
    }

    public function __call(string $method, array $arguments): mixed {
        return $this->client->$method(...$arguments);
    }

    public function search(array $params): array {
        return $this->client->search($params);
    }

    public function index(array $params): array {
        return $this->client->index($params);
    }

    public function get(array $params): array {
        return $this->client->get($params);
    }

    public function delete(array $params): array {
        return $this->client->delete($params);
    }

    public function update(array $params): array {
        return $this->client->update($params);
    }

    public function count(array $params): array {
        return $this->client->count($params);
    }

    public function bulk(array $params): array {
        return $this->client->bulk($params);
    }

    public function mget(array $params): array {
        return $this->client->mget($params);
    }

    public function msearch(array $params): array {
        return $this->client->msearch($params);
    }

    public function deleteByQuery(array $params): array {
        return $this->client->deleteByQuery($params);
    }

    public function updateByQuery(array $params): array {
        return $this->client->updateByQuery($params);
    }

    public function scroll(array $params): array {
        return $this->client->scroll($params);
    }

    public function clearScroll(array $params): array {
        return $this->client->clearScroll($params);
    }

    public function exists(array $params): bool {
        return $this->client->exists($params);
    }

    public function getSource(array $params): array {
        return $this->client->getSource($params);
    }

    public function existsSource(array $params): bool {
        return $this->client->existsSource($params);
    }

    public function ping(): bool {
        return $this->client->ping();
    }

    public function info(): array {
        return $this->client->info();
    }

    public function cat(): CatNamespace {
        return $this->client->cat();
    }

    public function cluster(): ClusterNamespace {
        return $this->client->cluster();
    }

    public function danglingIndices(): DanglingIndicesNamespace {
        return $this->client->danglingIndices();
    }

    public function indices(): IndicesNamespace {
        return $this->client->indices();
    }

    public function ingest(): IngestNamespace {
        return $this->client->ingest();
    }

    public function nodes(): NodesNamespace {
        return $this->client->nodes();
    }

    public function snapshot(): SnapshotNamespace {
        return $this->client->snapshot();
    }

    public function tasks(): TasksNamespace {
        return $this->client->tasks();
    }

    public function asyncSearch(): AsyncSearchNamespace {
        return $this->client->asyncSearch();
    }

    public function dataFrameTransformDeprecated(): DataFrameTransformDeprecatedNamespace {
        return $this->client->dataFrameTransformDeprecated();
    }

    public function monitoring(): MonitoringNamespace {
        return $this->client->monitoring();
    }

    public function searchableSnapshots(): SearchableSnapshotsNamespace {
        return $this->client->searchableSnapshots();
    }

    public function security(): SecurityNamespace {
        return $this->client->security();
    }

    public function ssl(): SslNamespace {
        return $this->client->ssl();
    }

    public function sql(): SqlNamespace {
        return $this->client->sql();
    }
}
