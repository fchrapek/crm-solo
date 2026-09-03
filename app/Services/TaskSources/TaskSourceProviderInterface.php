<?php

declare(strict_types=1);

namespace App\Services\TaskSources;

use App\Models\Integration;
use App\Models\Project;

/**
 * A pluggable external task source (Trello today; GitHub Issues / Linear later).
 *
 * "Container" is the provider's unit that maps onto a CRM project — a Trello
 * board, a GitHub repository's issue list, a Linear project.
 *
 * Contract, extracted verbatim from the Trello integration:
 * - Sync is strictly ONE-WAY (source → CRM). The CRM never writes to the
 *   external source, with a single exception: container/list/label creation
 *   during onboarding (connectNewContainer). linkExistingContainer performs
 *   no remote writes at all — it only pulls.
 * - Provider-owned tasks (tasks.source = key()) are read-only in the CRM;
 *   edits happen at the source and flow back on the next sync.
 * - disconnect() touches only local rows: it clears the project's link
 *   columns + provider settings and keeps already-synced tasks as history.
 */
interface TaskSourceProviderInterface
{
    /**
     * Stable identifier for this source. Matches both `integrations.provider`
     * and `tasks.source` values (e.g. 'trello'). Lowercase snake_case.
     */
    public function key(): string;

    /**
     * Pull the project's linked container into local tasks. The project must
     * already be connected through this provider.
     *
     * @return array{created: int, updated: int, errors: int}
     */
    public function syncProject(Project $project, Integration $integration): array;

    /**
     * Pull every container visible to the integration's account. Creates
     * projects for containers not yet linked locally.
     *
     * @return array<string, int> provider-defined summary counters
     */
    public function syncAll(Integration $integration): array;

    /**
     * List remote containers for the connect/link picker, normalized so the
     * UI never sees provider-specific payload shapes.
     *
     * @return list<array{id: string|null, name: string, url: string|null, workspace: string|null}>
     */
    public function listContainers(Integration $integration): array;

    /**
     * Create a fresh remote container (plus its default states/labels) and
     * attach it to the given project. This onboarding step is the ONLY place
     * the CRM is allowed to write to the external source.
     */
    public function connectNewContainer(Project $project, Integration $integration): Project;

    /**
     * Link an existing remote container to the given project and pull its
     * items immediately. No remote writes.
     */
    public function linkExistingContainer(Project $project, Integration $integration, string $containerId): Project;

    /**
     * Detach the container from the project — local-only: clear link columns
     * and provider settings, keep synced tasks as historical records. The
     * remote container is never touched.
     */
    public function disconnect(Project $project): void;
}
