<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Models\NotificationDelivery;

/**
 * NotificationChannelService — dispatches a notification via a specific channel.
 *
 * Each send* method:
 *   1. Creates (or finds) the delivery row via NotificationDelivery.
 *   2. Attempts the channel-specific dispatch.
 *   3. Updates the delivery row to sent/failed with any provider ref.
 *   4. Returns the final status string.
 *
 * In this implementation, SMS, Email and WhatsApp are queued rather than sent
 * synchronously.  The actual provider SDK calls happen in background workers
 * that pick up rows with status = 'queued'.  This method therefore sets the
 * row to 'queued' and returns immediately.  In-app messages are stored in the
 * DB directly and are considered 'delivered' once persisted.
 *
 * To wire in a real provider, replace the TODO stubs below with the
 * appropriate SDK/HTTP client calls and update the status accordingly.
 */
class NotificationChannelService
{
    public function __construct(
        private readonly NotificationDelivery $deliveryModel,
    ) {}

    // -------------------------------------------------------------------------
    // In-app
    // -------------------------------------------------------------------------

    /**
     * Store an in-app notification delivery record.
     * The notification row itself already exists; this just records the delivery.
     * Returns the delivery status.
     */
    public function sendInApp(array $notification): string
    {
        $deliveryId = $this->deliveryModel->insert(
            (int) $notification['id'],
            NotificationDelivery::CHANNEL_IN_APP,
        );

        // In-app is immediately "delivered" — the client reads from the DB.
        $this->deliveryModel->updateStatus($deliveryId, [
            'status'       => NotificationDelivery::STATUS_DELIVERED,
            'provider_ref' => null,
        ]);

        return NotificationDelivery::STATUS_DELIVERED;
    }

    // -------------------------------------------------------------------------
    // SMS
    // -------------------------------------------------------------------------

    /**
     * Queue an SMS delivery for the given notification.
     * Returns the delivery status ('queued' or 'failed').
     */
    public function sendSms(array $notification): string
    {
        $deliveryId = $this->deliveryModel->insert(
            (int) $notification['id'],
            NotificationDelivery::CHANNEL_SMS,
        );

        try {
            // TODO: enqueue a job to the SMS provider (e.g. Twilio, MSG91).
            // $this->smsQueue->dispatch($notification, $deliveryId);

            // Row stays 'queued'; worker will update to sent/delivered/failed.
            return NotificationDelivery::STATUS_QUEUED;
        } catch (\Throwable $e) {
            $this->deliveryModel->updateStatus($deliveryId, [
                'status'       => NotificationDelivery::STATUS_FAILED,
                'provider_ref' => null,
            ]);

            return NotificationDelivery::STATUS_FAILED;
        }
    }

    // -------------------------------------------------------------------------
    // Email
    // -------------------------------------------------------------------------

    /**
     * Queue an email delivery for the given notification.
     * Returns the delivery status ('queued' or 'failed').
     */
    public function sendEmail(array $notification): string
    {
        $deliveryId = $this->deliveryModel->insert(
            (int) $notification['id'],
            NotificationDelivery::CHANNEL_EMAIL,
        );

        try {
            // TODO: enqueue a job to the email provider (e.g. SES, SendGrid).
            // $this->emailQueue->dispatch($notification, $deliveryId);

            return NotificationDelivery::STATUS_QUEUED;
        } catch (\Throwable $e) {
            $this->deliveryModel->updateStatus($deliveryId, [
                'status'       => NotificationDelivery::STATUS_FAILED,
                'provider_ref' => null,
            ]);

            return NotificationDelivery::STATUS_FAILED;
        }
    }

    // -------------------------------------------------------------------------
    // WhatsApp
    // -------------------------------------------------------------------------

    /**
     * Queue a WhatsApp Business template message for the given notification.
     *
     * WhatsApp Business API requires pre-approved message templates.
     * The event_type is used to select the appropriate template name.
     * Returns the delivery status ('queued' or 'failed').
     */
    public function sendWhatsApp(array $notification): string
    {
        $deliveryId = $this->deliveryModel->insert(
            (int) $notification['id'],
            NotificationDelivery::CHANNEL_WHATSAPP,
        );

        try {
            // TODO: select the WhatsApp template for $notification['event_type']
            // and enqueue via WhatsApp Business Cloud API (Meta) or partner.
            // $templateName = $this->resolveTemplate($notification['event_type']);
            // $this->whatsappQueue->dispatch($notification, $deliveryId, $templateName);

            return NotificationDelivery::STATUS_QUEUED;
        } catch (\Throwable $e) {
            $this->deliveryModel->updateStatus($deliveryId, [
                'status'       => NotificationDelivery::STATUS_FAILED,
                'provider_ref' => null,
            ]);

            return NotificationDelivery::STATUS_FAILED;
        }
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Dispatch via any supported channel by name.
     * Convenience wrapper used by NotificationService.
     */
    public function dispatch(array $notification, string $channel): string
    {
        return match ($channel) {
            NotificationDelivery::CHANNEL_IN_APP   => $this->sendInApp($notification),
            NotificationDelivery::CHANNEL_SMS      => $this->sendSms($notification),
            NotificationDelivery::CHANNEL_EMAIL    => $this->sendEmail($notification),
            NotificationDelivery::CHANNEL_WHATSAPP => $this->sendWhatsApp($notification),
            default => throw new \InvalidArgumentException("Unknown channel: {$channel}"),
        };
    }
}
