<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Listeners;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MertAydin\LaravelBackupManager\Events\BackupCompleted;
use MertAydin\LaravelBackupManager\Events\BackupFailed;
use Throwable;

final class SendBackupNotifications
{
    public function handle(BackupCompleted|BackupFailed $event): void
    {
        if (! (bool) config('backup-manager.notifications.enabled', false)) {
            return;
        }

        $success = $event instanceof BackupCompleted;
        $result = $event->result;
        $status = $success ? 'completed' : 'failed';
        $message = sprintf(
            'Laravel backup %s. Backup ID: %s%s',
            $status,
            $result->id,
            $result->error ? '. Error: '.$result->error : '',
        );

        $recipients = array_values(array_filter((array) config('backup-manager.notifications.mail_to', []), 'is_string'));
        if ($recipients !== []) {
            try {
                Mail::raw($message, static function ($mail) use ($recipients, $status): void {
                    $mail->to($recipients)->subject('Backup '.$status);
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $webhook = (string) config('backup-manager.notifications.slack_webhook', '');
        if ($webhook !== '') {
            try {
                Http::timeout(10)->post($webhook, ['text' => $message])->throw();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
