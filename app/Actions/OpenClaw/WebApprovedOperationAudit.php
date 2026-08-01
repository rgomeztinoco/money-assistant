<?php

namespace App\Actions\OpenClaw;

final readonly class WebApprovedOperationAudit
{
    public function __construct(
        public string $capability,
        public int $httpStatus,
        public string $resourceType,
        public string $domainAction,
        public int $resourceId,
        public int $resourceRevision,
    ) {}
}
