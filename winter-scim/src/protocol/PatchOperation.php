<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

use dev\winterframework\scim\filter\PatchPath;

final readonly class PatchOperation {
    public function __construct(
        public PatchOp $op,
        public ?PatchPath $path,
        public mixed $value = null,
    ) {
    }
}
