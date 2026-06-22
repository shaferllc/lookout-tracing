<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSent;
use Lookout\Tracing\Mail\Client as MailClient;
use Lookout\Tracing\Tracer;

/**
 * Reports sent mail to {@code POST /api/ingest/mail} (Telescope Mail Watcher equivalent).
 */
final class MailMonitoringInstrumentation
{
    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        if (! class_exists(MessageSent::class)) {
            return;
        }

        $events->listen(MessageSent::class, [self::class, 'onMessageSent']);
    }

    public static function onMessageSent(MessageSent $event): void
    {
        if (! self::enabled()) {
            return;
        }

        if (! self::passesSampling()) {
            return;
        }

        $message = $event->message;
        $subject = method_exists($message, 'getSubject') ? (string) $message->getSubject() : null;

        $to = [];
        if (method_exists($message, 'getTo')) {
            foreach ($message->getTo() ?? [] as $addr) {
                if (is_object($addr) && method_exists($addr, 'getAddress')) {
                    $to[] = (string) $addr->getAddress();
                } elseif (is_string($addr)) {
                    $to[] = $addr;
                }
            }
        }

        $mailable = 'mail.message';
        if (isset($event->data['mailable']) && is_object($event->data['mailable'])) {
            $mailable = $event->data['mailable']::class;
        } elseif (isset($event->data['__laravel_notification']) && is_string($event->data['__laravel_notification'])) {
            $mailable = $event->data['__laravel_notification'];
        }

        MailClient::captureSent(
            $mailable,
            $subject !== '' ? $subject : null,
            $to !== [] ? $to : null,
            null,
            null,
            self::currentTraceId(),
        );
    }

    /**
     * Random per-message client-side sampling. Rate 1.0 keeps everything, 0.0 keeps nothing.
     */
    private static function passesSampling(): bool
    {
        $cfg = config('lookout-tracing.mail_monitoring');
        $rate = is_array($cfg) ? (float) ($cfg['sample_rate'] ?? 1.0) : 1.0;
        $rate = max(0.0, min(1.0, $rate));

        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $mailCfg = is_array($cfg['mail_monitoring'] ?? null) ? $cfg['mail_monitoring'] : [];
        if (empty($mailCfg['enabled'])) {
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
