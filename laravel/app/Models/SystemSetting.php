<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SystemSetting
 *
 * Global configurations for the platform (e.g. trading_fee_percentage).
 */
final class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
    ];

    /**
     * Get a setting value casted to its proper type.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::where('key', $key)->first();
        if (!$setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            'float'   => (float) $setting->value,
            'json'    => json_decode($setting->value, true),
            default   => $setting->value,
        };
    }
}
