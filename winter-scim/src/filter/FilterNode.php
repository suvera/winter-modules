<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

/**
 * The filter syntax tree (RFC 7644 §3.4.2.2). The parser builds it; the DQL
 * converter and the in-memory matcher read it.
 */
interface FilterNode {
}
