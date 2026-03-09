<?php

namespace App\Services;

use App\DTOs\IntentClassificationDTO;
use App\DTOs\SkillDiscoveryResultDTO;
use App\Logging\MultiLogger;
use App\Services\Discovery\PendingDiscoveryStore;
use App\Services\Discovery\SkillGapDetector;
use App\Services\Discovery\SkillRegistryClient;

/**
 * SkillAutoDiscoveryService
 *
 * Handles automatic discovery and installation of skills when the system
 * detects a gap between user intent and available skills.
 *
 * Delegates to:
 * - {@see SkillGapDetector} for gap detection and search term extraction
 * - {@see SkillRegistryClient} for CLI interaction (find/install)
 * - {@see PendingDiscoveryStore} for pending discovery state management
 */
class SkillAutoDiscoveryService
{
    protected SettingsService $settings;

    protected SkillSearchService $skillService;

    protected SkillClassificationService $classificationService;

    protected SkillGapDetector $gapDetector;

    protected SkillRegistryClient $registryClient;

    protected PendingDiscoveryStore $pendingStore;

    protected int $maxResults;

    public function __construct(
        SettingsService $settings,
        SkillSearchService $skillService,
        SkillClassificationService $classificationService
    ) {
        $this->settings = $settings;
        $this->skillService = $skillService;
        $this->classificationService = $classificationService;

        $gapThreshold = config('laraclaw.skills.gap_detection_threshold', 0.5);
        $this->maxResults = config('laraclaw.skills.max_discovery_results', 5);

        $this->gapDetector = new SkillGapDetector($gapThreshold);
        $this->registryClient = new SkillRegistryClient($skillService, $classificationService);
        $this->pendingStore = new PendingDiscoveryStore;
    }

    /**
     * Detect skill gap and handle discovery/installation.
     *
     * @param  string  $message  The user message
     * @param  IntentClassificationDTO  $classification  The intent classification result
     * @param  string|null  $senderId  The sender ID
     * @param  string|null  $agentId  The agent ID
     * @return SkillDiscoveryResultDTO|null Null if no gap detected or no matches found
     */
    public function detectAndHandle(
        string $message,
        IntentClassificationDTO $classification,
        ?string $senderId = null,
        ?string $agentId = null
    ): ?SkillDiscoveryResultDTO {
        // Check if this looks like a skill-requiring request
        if (! $this->gapDetector->isSkillRequired($message, $classification)) {
            return null;
        }

        // Extract search term from message
        $searchTerm = $this->gapDetector->extractSearchTerm($message, $classification);

        if (empty($searchTerm)) {
            return null;
        }

        MultiLogger::info("Skill gap detected, searching for: {$searchTerm}");

        // Run npx skills find
        $searchResults = $this->registryClient->find($searchTerm);

        if (empty($searchResults)) {
            MultiLogger::info("No skills found for: {$searchTerm}");

            return null;
        }

        // Create result DTO
        $autoInstallEnabled = config('laraclaw.skills.auto_install', false);
        $autoInstallMode = config('laraclaw.skills.auto_install_mode', 'prompt');

        $result = new SkillDiscoveryResultDTO(
            searchTerm: $searchTerm,
            matches: array_slice($searchResults, 0, $this->maxResults),
            autoInstallEnabled: $autoInstallEnabled,
            autoInstallMode: $autoInstallMode
        );

        // Handle based on config
        if ($result->shouldAutoInstallSingle() || $result->shouldAutoInstallFirst()) {
            $topMatch = $result->getTopMatch();
            $installCmd = $result->getInstallCommand(0);

            if ($installCmd && $this->registryClient->install($installCmd)) {
                return $result->markAsAutoInstalled($topMatch['name']);
            }
        }

        // Return for user interaction (prompt mode or auto-install failed)
        return $result;
    }

    /**
     * Install a skill using `npx skills add`.
     *
     * @param  string  $ownerRepoSkill  Install spec like "owner/repo@skillname"
     * @param  callable|null  $outputCallback  Optional callback for real-time output
     * @return bool True if installation succeeded
     */
    public function installSkill(string $ownerRepoSkill, ?callable $outputCallback = null): bool
    {
        return $this->registryClient->install($ownerRepoSkill, $outputCallback);
    }

    /**
     * Install a skill by index from a discovery result.
     */
    public function installSkillByIndex(SkillDiscoveryResultDTO $result, int $index): bool
    {
        $installCmd = $result->getInstallCommand($index);

        if (! $installCmd) {
            MultiLogger::warning("Invalid skill index: {$index}");

            return false;
        }

        return $this->registryClient->install($installCmd);
    }

    /**
     * Refresh the skill index after installation.
     */
    public function refreshSkillIndex(): void
    {
        $this->registryClient->refreshSkillIndex();
    }

    // ==========================================
    // Pending Discovery (delegated to PendingDiscoveryStore)
    // ==========================================

    /**
     * Store a pending discovery for later user selection.
     */
    public function storePendingDiscovery(
        string $senderId,
        SkillDiscoveryResultDTO $result,
        string $originalMessage,
        string $agentId
    ): void {
        $this->pendingStore->store($senderId, $result, $originalMessage, $agentId);
    }

    /**
     * Get a pending discovery for a sender.
     *
     * @return array{result: SkillDiscoveryResultDTO, original_message: string, agent_id: string}|null
     */
    public function getPendingDiscovery(string $senderId): ?array
    {
        return $this->pendingStore->get($senderId);
    }

    /**
     * Clear a pending discovery.
     */
    public function clearPendingDiscovery(string $senderId): void
    {
        $this->pendingStore->clear($senderId);
    }

    /**
     * Check if a message is a response to a pending discovery prompt.
     *
     * @return array{is_selection: bool, index: int|null, skip: bool}
     */
    public function parseDiscoveryResponse(string $message, string $senderId): array
    {
        return $this->pendingStore->parseResponse($message, $senderId);
    }

    // ==========================================
    // Accessor Methods for Extracted Components
    // ==========================================

    /**
     * Get the gap detector.
     */
    public function getGapDetector(): SkillGapDetector
    {
        return $this->gapDetector;
    }

    /**
     * Get the registry client.
     */
    public function getRegistryClient(): SkillRegistryClient
    {
        return $this->registryClient;
    }

    /**
     * Get the pending discovery store.
     */
    public function getPendingStore(): PendingDiscoveryStore
    {
        return $this->pendingStore;
    }
}
