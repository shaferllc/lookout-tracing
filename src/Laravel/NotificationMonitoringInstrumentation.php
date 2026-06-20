<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationSent;
use Lookout\Tracing\Notification\Client as NotificationClient;
use Lookout\Tracing\Tracer;

/**
 * Reports sent notifications to {@code POST /api/ingest/notification} (Telescope Notification Watcher equivalent).
 */
final class NotificationMonitoringInstrumentation
{
    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        if (! class_exists(NotificationSent::class)) {
            return;
        }

        $events->listen(NotificationSent::class, [self::class, 'onNotificationSent']);
    }

    public static function onNotificationSent(NotificationSent $event): void
    {
        if (! self::enabled()) {
            return;
        }

        $notifiableType = is_object($event->notifiable) ? $event->notifiable::class : null;
        $notifiableId = null;
        if (is_object($event->notifiable) && method_exists($event->notifiable, 'getKey')) {
            $key = $event->notifiable->getKey();
            $notifiableId = $key !== null ? (string) $key : null;
        }

        NotificationClient::captureSent(
            $event->notification::class,
            (string) $event->channel,
            $notifiableType,
            $notifiableId,
            null,
            null,
            self::currentTraceId(),
        );
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $notificationCfg = is_array($cfg['notification_monitoring'] ?? null) ? $cfg['notification_monitoring'] : [];
        if (empty($notificationCfg['enabled'])) {
            return false;
        }

        $key = $cfg['api_key'] ?? null;
        $base = $cfg['base_uri'] ?? null;

        return is_string($key) && $key !== '' && is_string($base) && rtrim(trim($base), '/') !== '';
    }

    private static function currentTraceId(): ?string
    {
        $id = Tracer::instance()->traceId();

        return $id !== '' ? $id : null;
    }
}
