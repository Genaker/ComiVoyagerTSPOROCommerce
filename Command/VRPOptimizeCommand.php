<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Command;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use Genaker\Bundle\ComiVoyager\Core\Model\DeliveryOrder;
use Genaker\Bundle\ComiVoyager\Core\Model\Vehicle;
use Genaker\Bundle\ComiVoyager\Core\Solver\VRPSolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'comivoyager:vrp:optimize',
    description: 'Optimize delivery routes for multiple vehicles (VRP)',
)]
class VRPOptimizeCommand extends Command
{
    public function __construct(
        private readonly VRPSolver $solver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('json-file', InputArgument::REQUIRED, 'Path to JSON file with depot, orders, vehicles')
            ->addOption('radius', 'r', InputOption::VALUE_REQUIRED, 'Max delivery radius in miles', '100')
            ->addOption('vehicles', 'k', InputOption::VALUE_REQUIRED, 'Number of vehicles (overrides JSON)')
            ->addOption('capacity', 'c', InputOption::VALUE_REQUIRED, 'Capacity per vehicle in lbs (overrides JSON)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $jsonPath = $input->getArgument('json-file');
        if (!file_exists($jsonPath)) {
            $io->error("File not found: $jsonPath");
            return Command::FAILURE;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            $io->error('Invalid JSON');
            return Command::FAILURE;
        }

        $depotData = $data['depot'] ?? null;
        if (!isset($depotData['lat'], $depotData['lng'])) {
            $io->error('depot.lat and depot.lng are required in JSON');
            return Command::FAILURE;
        }
        $depot = new Coordinate((float) $depotData['lat'], (float) $depotData['lng']);

        $orders = [];
        foreach ($data['orders'] ?? [] as $i => $o) {
            $orders[] = new DeliveryOrder(
                (string) ($o['id'] ?? "ORD-$i"),
                new Coordinate((float) $o['lat'], (float) $o['lng']),
                (float) ($o['weight_lbs'] ?? 0),
                (string) ($o['priority'] ?? 'normal'),
            );
        }

        if (empty($orders)) {
            $io->error('No orders found in JSON');
            return Command::FAILURE;
        }

        $vehicleCount = (int) ($input->getOption('vehicles') ?? $data['vehicles']['count'] ?? 2);
        $capacityLbs = (float) ($input->getOption('capacity') ?? $data['vehicles']['capacity_lbs'] ?? 0);
        $maxRadius = (float) $input->getOption('radius');

        $vehicles = [];
        for ($i = 1; $i <= $vehicleCount; $i++) {
            $vehicles[] = new Vehicle($i, $capacityLbs);
        }

        $io->title('VRP Route Optimization');
        $io->text(sprintf('Orders: %d | Vehicles: %d | Radius: %.0f mi | Capacity: %.0f lbs',
            count($orders), $vehicleCount, $maxRadius, $capacityLbs));

        $start = microtime(true);
        $solution = $this->solver->solve($orders, $vehicles, $depot, $maxRadius);
        $elapsed = (microtime(true) - $start) * 1000;

        foreach ($solution->getRoutes() as $route) {
            if ($route->getStopCount() === 0) {
                continue;
            }
            $io->section(sprintf('Vehicle %d — %d stops, %.1f miles, %.0f lbs',
                $route->getVehicle()->getId(),
                $route->getStopCount(),
                $route->getTotalDistanceMiles(),
                $route->getTotalWeightLbs(),
            ));
            $rows = [];
            foreach ($route->getStops() as $seq => $stop) {
                $rows[] = [$seq + 1, $stop->getId(), sprintf('%.4f', $stop->getCoordinate()->lat), sprintf('%.4f', $stop->getCoordinate()->lng), sprintf('%.0f', $stop->getWeightLbs())];
            }
            $io->table(['#', 'Order ID', 'Lat', 'Lng', 'Weight (lbs)'], $rows);
        }

        if (!empty($solution->getUnassigned())) {
            $io->warning(sprintf('%d orders unassigned (capacity exceeded)', count($solution->getUnassigned())));
        }
        if (!empty($solution->getOutOfRange())) {
            $io->warning(sprintf('%d orders out of range (>%.0f miles)', count($solution->getOutOfRange()), $maxRadius));
        }

        $summary = $solution->toArray()['summary'];
        $io->success(sprintf('Done in %.1f ms — %d vehicles, %.1f total miles',
            $elapsed, $summary['vehicles_used'], $summary['total_distance_miles']));

        return Command::SUCCESS;
    }
}
