<?php

namespace App\Services\Channels;

/**
 * Interface for channel services that support polling for messages.
 *
 * All channel services (Discord, Telegram, WhatsApp, etc.) should implement this interface
 * to provide a consistent way to start their polling loops.
 */
interface ChannelServiceInterface
{
    /**
     * Initialize the channel service.
     *
     * This method should set up any necessary connections, validate credentials,
     * and prepare the service for polling.
     *
     * @throws \RuntimeException If required configuration is missing
     */
    public function initialize(): void;

    /**
     * Start the polling loop for incoming messages.
     *
     * This method should run indefinitely, polling for new messages and
     * processing them through the message handling pipeline.
     */
    public function startPolling(): void;

    /**
     * Process pending outgoing messages from the queue.
     *
     * This method should be called periodically to send any queued responses
     * back to users through the channel.
     */
    public function processOutgoingQueue(): void;

    /**
     * Check if the channel service is ready to process messages.
     */
    public function isReady(): bool;
}
