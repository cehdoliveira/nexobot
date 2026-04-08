<?php

class BotAlertService
{
    private const SETTINGS_NAMESPACE = 'bot_alerts';

    public static function sendGridAlertOnce(
        int $gridId,
        string $eventKey,
        string $logType,
        string $subject,
        string $body,
        array $metadata = []
    ): bool {
        if (self::gridEventExists($gridId, $eventKey)) {
            return false;
        }

        $recipients = self::getRecipients();
        if (empty($recipients) || !class_exists('EmailProducer')) {
            return false;
        }

        try {
            $sent = EmailProducer::getInstance()->sendEmail($recipients, $subject, $body, [
                'isHtml' => true,
                'priority' => 'high'
            ]);

            if ($sent) {
                self::saveGridEvent($gridId, $eventKey, $logType, $subject, $metadata);
            }

            return $sent;
        } catch (Throwable $e) {
            error_log('BotAlertService::sendGridAlertOnce error: ' . $e->getMessage());
            return false;
        }
    }

    public static function sendSystemAlertOnce(
        string $alertKey,
        string $subject,
        string $body,
        array $metadata = []
    ): bool {
        $settingsKey = self::normalizeSettingsKey($alertKey);
        if (AppSettings::get(self::SETTINGS_NAMESPACE, $settingsKey, '0') === '1') {
            return false;
        }

        $recipients = self::getRecipients();
        if (empty($recipients) || !class_exists('EmailProducer')) {
            return false;
        }

        try {
            $sent = EmailProducer::getInstance()->sendEmail($recipients, $subject, $body, [
                'isHtml' => true,
                'priority' => 'high'
            ]);

            if ($sent) {
                AppSettings::set(self::SETTINGS_NAMESPACE, $settingsKey, '1');
                AppSettings::set(
                    self::SETTINGS_NAMESPACE,
                    self::normalizeSettingsKey($alertKey . '_payload'),
                    json_encode([
                        'subject' => $subject,
                        'metadata' => $metadata,
                        'sent_at' => date('Y-m-d H:i:s')
                    ])
                );
            }

            return $sent;
        } catch (Throwable $e) {
            error_log('BotAlertService::sendSystemAlertOnce error: ' . $e->getMessage());
            return false;
        }
    }

    public static function clearSystemAlert(string $alertKey): void
    {
        AppSettings::set(self::SETTINGS_NAMESPACE, self::normalizeSettingsKey($alertKey), '0');
    }

    private static function getRecipients(): array
    {
        $usersModel = new users_model();
        $usersModel->set_filter([
            "active = 'yes'",
            "enabled = 'yes'"
        ]);
        $usersModel->load_data();

        $recipients = [];
        foreach ($usersModel->data as $user) {
            $mail = trim((string)($user['mail'] ?? ''));
            if ($mail !== '') {
                $recipients[] = $mail;
            }
        }

        return array_values(array_unique($recipients));
    }

    private static function gridEventExists(int $gridId, string $eventKey): bool
    {
        $gridLogsModel = new grid_logs_model();
        $gridLogsModel->set_filter([
            "grids_id = '" . (int)$gridId . "'",
            "event = '" . addslashes($eventKey) . "'"
        ]);
        $gridLogsModel->set_paginate([1]);
        $gridLogsModel->load_data();

        return !empty($gridLogsModel->data);
    }

    private static function saveGridEvent(
        int $gridId,
        string $eventKey,
        string $logType,
        string $message,
        array $metadata = []
    ): void {
        $gridLogsModel = new grid_logs_model();
        $gridLogsModel->populate([
            'grids_id' => $gridId,
            'event' => $eventKey,
            'log_type' => $logType,
            'message' => $message,
            'data' => json_encode($metadata)
        ]);
        $gridLogsModel->save();
    }

    private static function normalizeSettingsKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_]+/i', '_', strtolower($key));
    }
}
