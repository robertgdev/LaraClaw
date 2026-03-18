<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\DTOs\IntentClassificationDTO;
use App\Logging\MultiLogger;
use App\Models\ConversationMessage;
use App\Services\Conversation\ResponseDeliveryService;
use App\Services\SkillAutoDiscoveryService;

/**
 * Handles skill discovery flow: detecting skill gaps, prompting users
 * for skill selection, and auto-installing skills.
 *
 * Extracted from ProcessMessageJob to isolate the skill discovery
 * lifecycle from message processing.
 */
class SkillDiscoveryHandler
{
    public function __construct(
        protected SkillAutoDiscoveryService $autoDiscoveryService,
        protected ResponseDeliveryService $deliveryService
    ) {}

    /**
     * Handle pending skill discovery selection from a previous prompt.
     *
     * @return bool True if the message was handled as a discovery response
     */
    public function handlePendingSelection(
        ConversationMessage $message,
        string $senderId
    ): bool {
        $discoveryResponse = $this->autoDiscoveryService->parseDiscoveryResponse($message->message, $senderId);

        if (! $discoveryResponse['is_selection']) {
            return false;
        }

        $pending = $this->autoDiscoveryService->getPendingDiscovery($senderId);

        if (! $pending) {
            return false;
        }

        if ($discoveryResponse['skip']) {
            $this->autoDiscoveryService->clearPendingDiscovery($senderId);
            $this->deliveryService->sendResponse($message, "Okay, I'll continue without installing a new skill.");

            return true;
        }

        if ($discoveryResponse['index'] !== null) {
            $success = $this->autoDiscoveryService->installSkillByIndex($pending['result'], $discoveryResponse['index']);

            if ($success) {
                $this->autoDiscoveryService->clearPendingDiscovery($senderId);
                $selectedSkill = $pending['result']->matches[$discoveryResponse['index']] ?? null;
                $skillName = $selectedSkill['name'] ?? 'the skill';

                $this->deliveryService->sendResponse($message, "Skill **{$skillName}** installed successfully! Now processing your original request...");

                // Re-dispatch with original message
                $originalMessage = ConversationMessage::createIncoming([
                    'conversation_id' => $message->conversation_id,
                    'channel' => $message->channel,
                    'sender' => $message->sender,
                    'sender_id' => $message->sender_id,
                    'message' => $pending['original_message'],
                    'agent_id' => $pending['agent_id'],
                ]);
                \App\Jobs\ProcessMessageJob::dispatch($originalMessage);

                return true;
            } else {
                $this->deliveryService->sendResponse($message, 'Failed to install the skill. Please try again or select a different one.');

                return true;
            }
        }

        return false;
    }

    /**
     * Detect skill gaps and handle auto-discovery/installation.
     *
     * @return bool True if the message was handled by skill discovery
     */
    public function detectAndHandle(
        ConversationMessage $message,
        IntentClassificationDTO $classification,
        string $senderId,
        string $agentId
    ): bool {
        $gapDetectionThreshold = config('laraclaw.skills.gap_detection_threshold', 0.5);
        $autoInstallEnabled = config('laraclaw.skills.auto_install', false);

        if (! $autoInstallEnabled) {
            return false;
        }

        if ($classification->matchedSkill && $classification->skillConfidence >= $gapDetectionThreshold) {
            return false;
        }

        $discoveryResult = $this->autoDiscoveryService->detectAndHandle(
            $message->message,
            $classification,
            $senderId,
            $agentId
        );

        if ($discoveryResult === null) {
            return false;
        }

        if ($discoveryResult->shouldAutoInstallFirst() || $discoveryResult->shouldAutoInstallSingle()) {
            $success = $discoveryResult->shouldAutoInstallFirst()
                ? $this->autoDiscoveryService->installSkill($discoveryResult->getInstallCommand())
                : $this->autoDiscoveryService->installSkill($discoveryResult->getInstallCommand());

            if ($success) {
                $selectedSkill = $discoveryResult->matches[0] ?? null;
                $skillName = $selectedSkill['name'] ?? 'the skill';

                $suffix = $discoveryResult->shouldAutoInstallFirst() ? 'automatically' : '';
                $this->deliveryService->sendResponse($message, "Skill **{$skillName}** installed {$suffix}! Now processing your request...");

                // Re-dispatch with original message
                $originalMessage = ConversationMessage::createIncoming([
                    'conversation_id' => $message->conversation_id,
                    'channel' => $message->channel,
                    'sender' => $message->sender,
                    'sender_id' => $message->sender_id,
                    'message' => $message->message,
                    'agent_id' => $agentId,
                ]);
                \App\Jobs\ProcessMessageJob::dispatch($originalMessage);

                return true;
            }

            return false;
        }

        if ($discoveryResult->needsUserSelection()) {
            $this->autoDiscoveryService->storePendingDiscovery(
                $senderId,
                $discoveryResult,
                $message->message,
                $agentId
            );
            $this->deliveryService->sendResponse($message, $discoveryResult->formatPromptMessage());

            return true;
        }

        if (! $discoveryResult->hasMatches()) {
            MultiLogger::info("No skills found for search term: {$classification->matchedSkill}");
        }

        return false;
    }
}
