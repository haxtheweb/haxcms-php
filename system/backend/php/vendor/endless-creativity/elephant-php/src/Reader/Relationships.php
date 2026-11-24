<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/relationships-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

final readonly class Relationships
{
    /** @var array<string, string> */
    private array $targetsByRelationshipId;

    /** @var array<string, list<string>> */
    private array $targetsByType;

    /**
     * @param  list<Relationship>  $relationships
     */
    public function __construct(array $relationships = [])
    {
        $byId = [];
        $byType = [];
        foreach ($relationships as $relationship) {
            $byId[$relationship->relationshipId] = $relationship->target;
            $byType[$relationship->type][] = $relationship->target;
        }
        $this->targetsByRelationshipId = $byId;
        $this->targetsByType = $byType;
    }

    public static function default(): self
    {
        return new self();
    }

    public function findTargetByRelationshipId(string $relationshipId): ?string
    {
        return $this->targetsByRelationshipId[$relationshipId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function findTargetsByType(string $type): array
    {
        return $this->targetsByType[$type] ?? [];
    }
}
