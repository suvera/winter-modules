# WinterBoot Module - OpenSearch

## Setup

Add the following to your `config/opensearch-config.yml`:

```yaml
opensearch:
    -   name: default
        hosts:
            - http://localhost:9200
        # username: admin
        # password: admin
        # retries: 3
        # ssl_verification: true
```

## Using Basic Authentication

```yaml
opensearch:
    -   name: default
        hosts:
            - https://localhost:9200
        username: admin
        password: admin
        ssl_verification: false   # only disable for local/dev clusters
```


## Using Amazon OpenSearch Service (AWS)

Amazon OpenSearch Service (and OpenSearch Serverless) require every request
to be signed with **AWS Signature Version 4**. This module supports this via
the `aws` config section, which wraps the configured handler (cURL or
`SwooleHttpHandler`) with a SigV4-signing middleware backed by
`aws/aws-sdk-php`.

```yaml
opensearch:
    -   name: aws_opensearch
        hosts:
            - https://your-domain.us-east-1.es.amazonaws.com
        aws:
            -   region: us-east-1
                service: es    # "es" for Amazon OpenSearch Service, "aoss" for Serverless
                # credentials: # optional; omit to use default provider chain
                #     -   key: your_access_key
                #         secret: your_secret_key
                #         token: your_session_token
```


## OpenSearchTemplate Methods

```yaml
#[Autowired]
private OpenSearchTemplate $openSearch; 

// or

#[Autowired("aws_opensearch")]
private OpenSearchTemplate $openSearch;
```

The `OpenSearchTemplate` class provides all OpenSearch API methods.

### Commonly Used Methods

#### `search()`

```php
$result = $openSearch->search([
    'index' => 'my_index',
    'body' => [
        'query' => [
            'match' => [
                'field' => 'value'
            ]
        ]
    ]
]);
```

#### `index()`

```php
$result = $openSearch->index([
    'index' => 'my_index',
    'body' => [
        'field' => 'value'
    ]
]);
```

#### `get()`

```php
$result = $openSearch->get([
    'index' => 'my_index',
    'id' => 'document_id'
]);
```

#### `delete()`

```php
$result = $openSearch->delete([
    'index' => 'my_index',
    'id' => 'document_id'
]);
```

#### `update()`

```php
$result = $openSearch->update([
    'index' => 'my_index',
    'id' => 'document_id',
    'body' => [
        'doc' => [
            'field' => 'new_value'
        ]
    ]
]);
```

#### `bulk()`

```php
$result = $openSearch->bulk([
    'body' => [
        [
            'index' => [
                '_index' => 'my_index',
                '_id' => '1'
            ]
        ],
        [
            'field' => 'value1'
        ],
        [
            'index' => [
                '_index' => 'my_index',
                '_id' => '2'
            ]
        ],
        [
            'field' => 'value2'
        ]
    ]
]);
```

#### `count()`

```php
$result = $openSearch->count([
    'index' => 'my_index',
    'body' => [
        'query' => [
            'match_all' => new \stdClass()
        ]
    ]
]);
```

#### `scroll()`

```php
$result = $openSearch->scroll([
    'scroll' => '1m',
    'scroll_id' => $scrollId
]);
```

#### `clearScroll()`

```php
$result = $openSearch->clearScroll([
    'scroll_id' => [$scrollId1, $scrollId2]
]);
```

#### `exists()`

```php
$exists = $openSearch->exists([
    'index' => 'my_index',
    'id' => 'document_id'
]);
```

#### `getSource()`

```php
$result = $openSearch->getSource([
    'index' => 'my_index',
    'id' => 'document_id'
]);
```

#### `ping()`

```php
$pinged = $openSearch->ping();
```

#### `info()`

```php
$info = $openSearch->info();
```

### Namespace Methods

#### Cluster API

```php
$clusterInfo = $openSearch->cluster()->health();
$nodesInfo = $openSearch->cluster()->nodesStats();
```

#### Indices API

```php
$indicesInfo = $openSearch->indices()->get(['index' => 'my_index']);
$openSearch->indices()->create(['index' => 'my_index']);
$openSearch->indices()->delete(['index' => 'my_index']);
```

#### Cat API

```php
$indices = $openSearch->cat()->indices();
$nodes = $openSearch->cat()->nodes();
```

#### Nodes API

```php
$nodesInfo = $openSearch->nodes()->stats();
```

#### Snapshot API

```php
$snapshots = $openSearch->snapshot()->get(['repository' => 'my_repo']);
```

#### Tasks API

```php
$tasks = $openSearch->tasks()->list();
$task = $openSearch->tasks()->get(['task_id' => 'task_id']);
```

## Full Method List

The `OpenSearchTemplate` class supports all OpenSearch API methods:

- `search()`, `count()`, `scroll()`, `clearScroll()`
- `index()`, `get()`, `getSource()`, `exists()`, `existsSource()`, `delete()`
- `update()`, `updateByQuery()`
- `bulk()`, `mget()`, `msearch()`, `mtermvectors()`
- `deleteByQuery()`, `deleteByQueryRethrottle()`
- `reindex()`, `reindexRethrottle()`
- `searchTemplate()`, `searchShards()`
- `termvectors()`, `fieldCaps()`
- `explain()`, `rankEval()`
- `putScript()`, `getScript()`, `deleteScript()`, `scriptsPainlessExecute()`
- `getScriptContext()`, `getScriptLanguages()`
- `openPointInTime()`, `closePointInTime()`
- `cat()`, `cluster()`, `indices()`, `nodes()`, `snapshot()`, `tasks()`
- `ping()`, `info()`

For detailed API documentation, refer to the [OpenSearch PHP Client Documentation](https://github.com/opensearch-project/opensearch-php).
