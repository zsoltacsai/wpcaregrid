<?php

namespace App\Support;

use App\Owner\OwnerSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/** Formats persisted instants for people without changing the application's UTC clock. */
final class DisplayDateTime
{
    public function __construct(private readonly OwnerSettings $settings) {}

    public function timezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) $this->settings->get('general.timezone'));
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    public function format(?DateTimeInterface $value, string $format = 'Y-m-d H:i T'): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)
            ->setTimezone($this->timezone())
            ->format($format);
    }

    /** Format with Carbon's locale tokens after converting the same instant. */
    public function localizedFormat(?DateTimeInterface $value, string $format): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)
            ->setTimezone($this->timezone())
            ->locale((string) app()->getLocale())
            ->translatedFormat($format);
    }

    public function date(?DateTimeInterface $value, string $format = 'Y-m-d'): ?string
    {
        return $this->format($value, $format);
    }

    public function time(?DateTimeInterface $value, string $format = 'H:i T'): ?string
    {
        return $this->format($value, $format);
    }

    /** Interpret a local datetime-local form value in the display timezone and return its UTC instant. */
    public function parseLocal(string $value): Carbon
    {
        return Carbon::parse($value, $this->timezone())->utc();
    }
}
