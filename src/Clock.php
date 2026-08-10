<?php

namespace GlpiPlugin\Pellissarisync;

use Session;

/**
 * Time source that is safe to use from cron and CLI.
 *
 * GLPI writes date_creation/date_mod from $_SESSION['glpi_currenttime'], which is
 * simply unset when no session was started -- a plugin cron task that relied on it
 * would silently store NULL timestamps.
 */
final class Clock
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function now(): string
    {
        return Session::getCurrentTime() ?? date(self::FORMAT);
    }

    /**
     * Normalizes a timestamp coming from a peer into the wall-clock string GLPI
     * expects. Ticket::checkFieldsConsistency() rejects anything that is not
     * exactly `Y-m-d H:i:s`, so an ISO-8601 value with an offset would make
     * add()/update() fail.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date(self::FORMAT, $timestamp);
    }

    /**
     * Display format used inside the mirrored content header.
     */
    public static function forHumans(?string $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return '';
        }

        return date('d/m/Y - H:i:s', (int) strtotime($normalized));
    }
}
