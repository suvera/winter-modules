<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

/** RFC 7643 §7 */
enum Uniqueness: string {
    case NONE = 'none';
    case SERVER = 'server';
    case GLOBAL = 'global';
}
