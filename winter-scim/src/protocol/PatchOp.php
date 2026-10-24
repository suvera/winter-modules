<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

enum PatchOp: string {
    case ADD = 'add';
    case REMOVE = 'remove';
    case REPLACE = 'replace';
}
