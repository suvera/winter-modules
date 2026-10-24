<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

/** RFC 7643 §7 */
enum Returned: string {
    case ALWAYS = 'always';
    case NEVER = 'never';
    case DEFAULT = 'default';
    case REQUEST = 'request';
}
