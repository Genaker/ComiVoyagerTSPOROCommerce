<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Solver;

use Genaker\Bundle\ComiVoyager\Core\Distance\HaversineDistanceMatrixProvider;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Solver\VRPSolver;
use PHPUnit\Framework\TestCase;

class VRPSolverTest extends TestCase
{
    private VRPSolver $solver;

    protected function setUp(): void
    {
        $this->solver = new VRPSolver(new HaversineDistanceMatrixProvider());
    }

    public function testEmptyInput(): void
    {
        $result = $this->solver->solve([], [], new Coordinate(40.71, -74.01));
        self::assertEmpty($result->getRoutes());
        self::assertSame(0, $result->getTotalOrdersAssigned());
    }

    public function testSingleVehicleSingleOrder(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [$this->order('A', 40.73, -73.99, 5000)];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(1, $result->getVehiclesUsed());
        self::assertSame(1, $result->getTotalOrdersAssigned());
        self::assertGreaterThan(0, $result->getTotalDistanceMiles());
    }

    public function testSingleVehicleMultipleOrders(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 5000),
            $this->order('B', 40.73, -73.99, 8000),
            $this->order('C', 40.74, -73.98, 6000),
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(1, $result->getVehiclesUsed());
        self::assertSame(3, $result->getTotalOrdersAssigned());
        self::assertGreaterThan(0, $result->getTotalDistanceMiles());
        self::assertEmpty($result->getUnassigned());
    }

    public function testTwoVehiclesSplitGeographically(): void
    {
        $depot = new Coordinate(40.71, -74.01);

        // North group
        $orders = [
            $this->order('N1', 40.90, -73.90, 5000),
            $this->order('N2', 40.92, -73.88, 5000),
            $this->order('N3', 40.94, -73.86, 5000),
            // South group
            $this->order('S1', 40.50, -74.20, 5000),
            $this->order('S2', 40.48, -74.22, 5000),
            $this->order('S3', 40.46, -74.24, 5000),
        ];
        $vehicles = [
            $this->vehicle(1, 40000),
            $this->vehicle(2, 40000),
        ];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(2, $result->getVehiclesUsed());
        self::assertSame(6, $result->getTotalOrdersAssigned());
        self::assertEmpty($result->getUnassigned());

        // Each route should have 3 stops
        foreach ($result->getRoutes() as $route) {
            self::assertSame(3, $route->getStopCount());
        }
    }

    public function testCapacityConstraintCreatesUnassigned(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 25000),
            $this->order('B', 40.73, -73.99, 25000),
            $this->order('C', 40.74, -73.98, 25000),
        ];
        // Only one truck with 40,000 lbs capacity — can only fit 1 order
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(1, $result->getTotalOrdersAssigned());
        self::assertGreaterThan(0, count($result->getUnassigned()));
    }

    public function testRadiusFilterExcludesDistantOrders(): void
    {
        $depot = new Coordinate(40.71, -74.01); // NYC
        $orders = [
            $this->order('Near', 40.73, -73.99, 5000),
            $this->order('Far', 42.36, -71.06, 5000), // Boston ~190 miles
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot, 100.0);

        self::assertSame(1, $result->getTotalOrdersAssigned());
        self::assertCount(1, $result->getOutOfRange());
        self::assertSame('Far', $result->getOutOfRange()[0]->getId());
    }

    public function testRadiusZeroAllowsAll(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('Far', 42.36, -71.06, 5000),
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot, 0.0);

        self::assertSame(1, $result->getTotalOrdersAssigned());
        self::assertEmpty($result->getOutOfRange());
    }

    public function testThreeVehiclesBalanced(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [];
        // 3 clusters of 4 orders each in different directions
        foreach ([0.15, -0.15, 0.0] as $gi => $latOffset) {
            for ($i = 0; $i < 4; $i++) {
                $lat = 40.71 + $latOffset + $i * 0.01;
                $lng = -74.01 + ($gi - 1) * 0.15 + $i * 0.01;
                $orders[] = $this->order("G{$gi}O{$i}", $lat, $lng, 5000);
            }
        }

        $vehicles = [
            $this->vehicle(1, 40000),
            $this->vehicle(2, 40000),
            $this->vehicle(3, 40000),
        ];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(12, $result->getTotalOrdersAssigned());
        self::assertEmpty($result->getUnassigned());
        self::assertGreaterThan(0, $result->getTotalDistanceMiles());
    }

    public function testSolutionToArray(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [
            $this->order('A', 40.72, -74.00, 5000),
            $this->order('B', 40.73, -73.99, 8000),
        ];
        $vehicles = [$this->vehicle(1, 40000)];

        $result = $this->solver->solve($orders, $vehicles, $depot);
        $array = $result->toArray();

        self::assertArrayHasKey('routes', $array);
        self::assertArrayHasKey('summary', $array);
        self::assertSame(2, $array['summary']['orders_assigned']);
        self::assertSame(0, $array['summary']['orders_unassigned']);
        self::assertGreaterThan(0, $array['summary']['total_distance_miles']);
    }

    public function testTenOrdersTwoTrucks(): void
    {
        $depot = new Coordinate(40.71, -74.01);
        $orders = [];
        for ($i = 0; $i < 10; $i++) {
            $angle = $i * (2 * M_PI / 10);
            $lat = 40.71 + 0.1 * cos($angle);
            $lng = -74.01 + 0.1 * sin($angle);
            $orders[] = $this->order("ORD-$i", $lat, $lng, 3000);
        }

        $vehicles = [
            $this->vehicle(1, 40000),
            $this->vehicle(2, 40000),
        ];

        $result = $this->solver->solve($orders, $vehicles, $depot);

        self::assertSame(10, $result->getTotalOrdersAssigned());
        self::assertSame(2, $result->getVehiclesUsed());

        // Total distance with 2 trucks should be reasonable
        self::assertGreaterThan(0, $result->getTotalDistanceMiles());
        self::assertLessThan(200, $result->getTotalDistanceMiles());
    }

    private function order(string $id, float $lat, float $lng, float $weight = 0.0): DeliveryOrder
    {
        return new DeliveryOrder($id, new Coordinate($lat, $lng), $weight);
    }

    private function vehicle(int $id, float $capacity): Vehicle
    {
        return new Vehicle($id, $capacity);
    }
}
