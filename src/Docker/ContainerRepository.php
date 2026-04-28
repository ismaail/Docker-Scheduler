<?php

declare(strict_types=1);

namespace App\Docker;

use App\Scheduler\Job;
use Docker\Docker;

class ContainerRepository
{
    public function __construct(
        private readonly Docker $docker,
        private readonly LabelParser $labelParser,
    ) {}

    /**
     * Discover all jobs from currently running containers that have
     * the "acme.enabled=true" label.
     *
     * @return Job[]
     */
    public function discoverJobs(): array
    {
        // Fetch only running containers with "acme.enabled=true"
        // The label filter is handled manually since beluga-php SDK
        // returns all running containers — we filter by label ourselves
        $containers = $this->docker->containerList();

        $jobs = [];

        foreach ($containers as $container) {
            $labels = $container->getLabels() ?? [];

            // Skip containers that don't have the enabled label
            if (($labels[Job::LABEL_ENABLED] ?? '') !== 'true') {
                continue;
            }

            $containerId = $container->getId();
            $containerName = $this->cleanContainerName($container->getNames() ?? []);

            $containerJobs = $this->labelParser->parse($containerId, $containerName, $labels);

            if (empty($containerJobs)) {
                echo "Container $containerName: has " . Job::LABEL_ENABLED . '=true but no valid jobs found' . PHP_EOL;

                continue;
            }

            array_push($jobs, ...$containerJobs);
        }

        echo 'Found ' . count($jobs) . ' job(s) from running containers' . PHP_EOL;

        return $jobs;
    }

    /**
     * Discover jobs from a single container by ID.
     * Used by the event listener when a new container starts.
     *
     * @return Job[]
     */
    public function discoverJobsByContainerId(string $containerId): array
    {
        $info = $this->docker->containerInspect($containerId);
        $labels = $info->getConfig()?->getLabels() ?? [];

        // Skip if not enabled
        if (($labels[Job::LABEL_ENABLED] ?? '') !== 'true') {
            return [];
        }

        $containerName = ltrim($info->getName() ?? 'unknown', '/');

        return $this->labelParser->parse($containerId, $containerName, $labels);
    }

    /**
     * Strip the leading "/" Docker adds to container names.
     * e.g. ["/my_app"] → "my_app"
     *
     * @param string[] $names
     */
    private function cleanContainerName(array $names): string
    {
        if (empty($names)) {
            return 'unknown';
        }

        return ltrim($names[0], '/');
    }
}
