<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Clustering\CapacityAdjuster;
use Genaker\Bundle\ComiVoyager\Core\Clustering\KMeansClusterer;
use Genaker\Bundle\ComiVoyager\Core\Contract\DistanceMatrixProviderInterface;
use Genaker\Bundle\ComiVoyager\Core\Model\Address;
use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPRoute;
use Genaker\Bundle\ComiVoyager\Core\Model\VRPSolution;

/**
 * Vehicle Routing Problem solver.
 *
 * Pipeline:
 *  1. Cluster orders into K groups (K-Means on coordinates)
 *  2. Adjust clusters for capacity/radius constraints
 *  3. Route each cluster via TSP solver (optimal visiting order)
 *  4. Return VRPSolution with per-vehicle routes + unassigned orders
 */
final class VRPSolver
{
    private const KM_TO_MILES = 0.621371;

    public function __construct(
        private readonly DistanceMatrixProviderInterface $distanceProvider,
        private readonly KMeansClusterer $clusterer = new KMeansClusterer(),
        private readonly CapacityAdjuster $adjuster = new CapacityAdjuster(),
        private readonly TopNRouteSolver $tspSolver = new TopNRouteSolver(),
    ) {
    }

    /**
     * @param DeliveryOrder[] $orders
     * @param Vehicle[] $vehicles
     */
    public function solve(
        array $orders,
        array $vehicles,
        Coordinate $depot,
        float $maxRadiusMiles = 100.0,
    ): VRPSolution {
        if (empty($orders) || empty($vehicles)) {
            return new VRPSolution();
        }

        $k = count($vehicles);

        $clusters = $this->clusterer->cluster($orders, $k, $depot);

        $adjusted = $this->adjuster->adjust($clusters, $vehicles, $depot, $maxRadiusMiles);

        /** @var VRPRoute[] $routes */
        $routes = $adjusted['routes'];

        foreach ($routes as $route) {
            if ($route->getStopCount() < 2) {
                if ($route->getStopCount() === 1) {
                    $this->setSingleStopDistance($route, $depot);
                }
                continue;
            }
            $this->optimizeRoute($route, $depot);
        }

        return new VRPSolution($routes, $adjusted['unassigned'], $adjusted['out_of_range']);
    }

    private function optimizeRoute(VRPRoute $route, Coordinate $depot): void
    {
        $stops = $route->getStops();

        $addresses = [new Address('__depot__', $depot)];
        foreach ($stops as $order) {
            $addresses[] = new Address($order->getId(), $order->getCoordinate());
        }

        $coordinates = array_map(fn(Address $a) => $a->coordinate, $addresses);
        $matrix = $this->distanceProvider->build($coordinates);

        $options = new SolveOptions(returnToStart: true, depotIndex: 0);
        $result = $this->tspSolver->solve($addresses, $matrix, 1, $options);

        if (empty($result->routes)) {
            return;
        }

        $bestRoute = $result->routes[0];

        // Reorder stops according to TSP solution (skip depot)
        $orderMap = [];
        foreach ($stops as $order) {
            $orderMap[$order->getId()] = $order;
        }

        $reordered = [];
        foreach ($bestRoute->stops as $stop) {
            $label = $stop->address->label;
            if ($label === '__depot__') {
                continue;
            }
            if (isset($orderMap[$label])) {
                $reordered[] = $orderMap[$label];
            }
        }

        $route->setStops($reordered);
        $route->setTotalDistanceMiles($bestRoute->totalDistanceKm * self::KM_TO_MILES);
    }

    private function setSingleStopDistance(VRPRoute $route, Coordinate $depot): void
    {
        $coordinates = [$depot, $route->getStops()[0]->getCoordinate()];
        $matrix = $this->distanceProvider->build($coordinates);
        $distanceKm = $matrix->distanceBetween(0, 1) + $matrix->distanceBetween(1, 0);
        $route->setTotalDistanceMiles($distanceKm * self::KM_TO_MILES);
    }
}
