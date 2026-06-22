<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Controller;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Solver\VRPSolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class VRPController extends AbstractController
{
    public function __construct(
        private readonly VRPSolver $solver,
    ) {
    }

    #[Route(
        path: '/comivoyager/vrp/optimize',
        name: 'genaker_comivoyager_vrp_optimize',
        methods: ['POST'],
        options: ['expose' => true]
    )]
    public function optimizeAction(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        $depotData = $data['depot'] ?? null;
        if (!isset($depotData['lat'], $depotData['lng'])) {
            return new JsonResponse(['error' => 'depot.lat and depot.lng are required'], 400);
        }
        $depot = new Coordinate((float) $depotData['lat'], (float) $depotData['lng']);

        $ordersData = $data['orders'] ?? [];
        if (empty($ordersData)) {
            return new JsonResponse(['error' => 'orders array is required and must not be empty'], 400);
        }

        $orders = [];
        foreach ($ordersData as $i => $o) {
            if (!isset($o['lat'], $o['lng'])) {
                return new JsonResponse(['error' => "orders[$i].lat and orders[$i].lng are required"], 400);
            }
            $orders[] = new DeliveryOrder(
                (string) ($o['id'] ?? "ORD-$i"),
                new Coordinate((float) $o['lat'], (float) $o['lng']),
                (float) ($o['weight_lbs'] ?? 0),
                (string) ($o['priority'] ?? 'normal'),
                isset($o['customer_id']) ? (string) $o['customer_id'] : null,
            );
        }

        $vehiclesData = $data['vehicles'] ?? ['count' => 1];
        $vehicleCount = max(1, (int) ($vehiclesData['count'] ?? 1));
        $capacityLbs = (float) ($vehiclesData['capacity_lbs'] ?? 0);
        $maxStops = (int) ($vehiclesData['max_stops'] ?? 0);

        $vehicles = [];
        for ($i = 1; $i <= $vehicleCount; $i++) {
            $vehicles[] = new Vehicle($i, $capacityLbs, $maxStops);
        }

        $maxRadius = (float) ($data['max_radius_miles'] ?? 100.0);

        try {
            $solution = $this->solver->solve($orders, $vehicles, $depot, $maxRadius);
            return new JsonResponse($solution->toArray());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
