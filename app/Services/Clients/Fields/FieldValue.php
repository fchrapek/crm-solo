<?php

declare(strict_types=1);

namespace App\Services\Clients\Fields;

use App\Models\ClientFieldDefinition;
use App\Services\Agent\AgentRefusal;
use App\Services\Humanizer;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns what a person or agent typed into the stored value of one custom
 * field, and a stored value back into text. Null means empty.
 */
final class FieldValue
{
    public static function normalise(ClientFieldDefinition $field, mixed $raw): mixed
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $value = match ($field->type) {
            'text' => self::text($field, $raw, 255),
            'textarea' => (string) Humanizer::clean(self::text($field, $raw, 5000)),
            'select' => self::option($field, $raw),
            'multiselect' => self::options($field, $raw),
            'date' => self::date($field, $raw),
            'datetime' => self::datetime($field, $raw),
            'url' => self::url($field, $raw),
            default => throw AgentRefusal::invalid("Field {$field->key} has an unknown type {$field->type}."),
        };

        return $value === '' || $value === [] ? null : $value;
    }

    public static function display(ClientFieldDefinition $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $labels = $field->optionLabels();

        return match ($field->type) {
            'select' => $labels[$value] ?? (string) $value,
            'multiselect' => implode(', ', array_map(fn ($key): string => $labels[$key] ?? (string) $key, (array) $value)),
            'datetime' => CarbonImmutable::parse((string) $value)->setTimezone(LocalCalendar::timezone())->format('Y-m-d H:i'),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /** For people: dates as 20.10.2026 and 20.10.2026, 10:00 in the display timezone, the rest as display(). */
    public static function readable(ClientFieldDefinition $field, mixed $value): ?string
    {
        if (! is_string($value) || ! in_array($field->type, ['date', 'datetime'], true)) {
            return self::display($field, $value);
        }

        return $field->type === 'date'
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('d.m.Y')
            : CarbonImmutable::parse($value)->setTimezone(LocalCalendar::timezone())->format('d.m.Y, H:i');
    }

    /** What a form control holds: a datetime as local Y-m-d\TH:i, a multiselect as its keys. */
    public static function input(ClientFieldDefinition $field, mixed $value): mixed
    {
        if ($value === null) {
            return $field->type === 'multiselect' ? [] : '';
        }

        return $field->type === 'datetime'
            ? CarbonImmutable::parse((string) $value)->setTimezone(LocalCalendar::timezone())->format('Y-m-d\TH:i')
            : $value;
    }

    private static function text(ClientFieldDefinition $field, mixed $raw, int $max): string
    {
        $text = mb_trim(self::scalar($field, $raw));
        if (mb_strlen($text) > $max) {
            throw AgentRefusal::invalid("{$field->label}: at most {$max} characters.", ['field' => $field->key]);
        }

        return $text;
    }

    private static function option(ClientFieldDefinition $field, mixed $raw): string
    {
        $key = mb_trim(self::scalar($field, $raw));
        $labels = $field->optionLabels();

        if (array_key_exists($key, $labels)) {
            return $key;
        }

        // A label typed instead of the key, compared without case.
        foreach ($labels as $optionKey => $label) {
            if (mb_strtolower($label) === mb_strtolower($key)) {
                return (string) $optionKey;
            }
        }

        throw AgentRefusal::invalid("{$field->label}: unknown option {$key}.", ['field' => $field->key, 'valid_options' => array_keys($labels)]);
    }

    /** @return list<string> */
    private static function options(ClientFieldDefinition $field, mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', self::scalar($field, $raw));
        $keys = [];
        foreach ($parts as $part) {
            if (! is_string($part)) {
                throw AgentRefusal::invalid("{$field->label}: a list of option keys.", ['field' => $field->key, 'valid_options' => array_keys($field->optionLabels())]);
            }
            if (mb_trim($part) !== '') {
                $keys[] = self::option($field, $part);
            }
        }
        $keys = array_values(array_unique($keys));

        // Stored in the definition's order, so equal choices compare equal.
        $order = array_flip(array_keys($field->optionLabels()));
        usort($keys, fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $keys;
    }

    private static function date(ClientFieldDefinition $field, mixed $raw): string
    {
        $text = mb_trim(self::scalar($field, $raw));
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $text);
        } catch (Throwable) {
            $date = null;
        }
        if ($date === null || $date->format('Y-m-d') !== $text) {
            throw AgentRefusal::invalid("{$field->label}: a date as YYYY-MM-DD.", ['field' => $field->key]);
        }

        return $text;
    }

    /** Stored in UTC; a time without an offset is local wall-clock time. */
    private static function datetime(ClientFieldDefinition $field, mixed $raw): string
    {
        $text = mb_trim(self::scalar($field, $raw));
        $refusal = AgentRefusal::invalid("{$field->label}: a real date and time as YYYY-MM-DD HH:MM.", ['field' => $field->key]);
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::00)?(Z|[+-]\d{2}:\d{2})?$/', $text, $m)) {
            throw $refusal;
        }

        try {
            $instant = isset($m[3])
                ? CarbonImmutable::createFromFormat('!Y-m-d H:i P', "{$m[1]} {$m[2]} ".($m[3] === 'Z' ? '+00:00' : $m[3]))
                : CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$m[1]} {$m[2]}", LocalCalendar::timezone());
        } catch (Throwable) {
            throw $refusal;
        }

        // Carbon rolls 30 February into March and a time inside the spring DST gap forward; neither is what was typed.
        try {
            $readBack = isset($m[3]) ? $instant?->setTimezone($m[3] === 'Z' ? '+00:00' : $m[3]) : $instant;
        } catch (Throwable) {
            throw $refusal;
        }
        if ($instant === null || $readBack?->format('Y-m-d H:i') !== "{$m[1]} {$m[2]}") {
            throw $refusal;
        }

        return $instant->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private static function url(ClientFieldDefinition $field, mixed $raw): string
    {
        $url = mb_trim(self::scalar($field, $raw));
        if (mb_strlen($url) > 2048 || ! preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw AgentRefusal::invalid("{$field->label}: a full http or https link.", ['field' => $field->key]);
        }

        return $url;
    }

    private static function scalar(ClientFieldDefinition $field, mixed $raw): string
    {
        if (! is_scalar($raw)) {
            throw AgentRefusal::invalid("{$field->label}: expected a single value.", ['field' => $field->key]);
        }

        return (string) $raw;
    }
}
