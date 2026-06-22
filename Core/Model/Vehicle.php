<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

final class Vehicle
{
    public function __construct(
        private readonly int $id,
        private readonly float $capacityLbs,
        private readonly int $maxStops = 0,
        private readonly float $maxDistanceMiles = 0.0,
        private readonly ?Coordinate $depot = null,
    ) {
    }

    public function getId(): int { return $this->id; }
    public function getCapacityLbs(): float { return $this->capacityLbs; }
    public function getMaxStops(): int { return $this->maxStops; }
    public function getMaxDistanceMiles(): float { return $this->maxDistanceMiles; }
    public function getDepot(): ?Coordinate { return $this->depot; }

    public function hasCapacityLimit(): bool { return $this->capacityLbs > 0; }
    public function hasStopLimit(): bool { return $this->maxStops > 0; }
    public function hasDistanceLimit(): bool { return $this->maxDistanceMiles > 0; }
}
