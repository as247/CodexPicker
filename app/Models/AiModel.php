<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $provider_id
 * @property string $model_id
 * @property string $name
 * @property string|null $description
 * @property string|null $family
 * @property int|null $context_window
 * @property int|null $max_output_tokens
 * @property list<string>|null $input_modalities
 * @property list<string>|null $output_modalities
 * @property bool $supports_reasoning
 * @property bool $supports_tools
 * @property bool $supports_structured_output
 * @property bool $supports_temperature
 * @property bool $open_weights
 * @property string|float|null $input_price
 * @property string|float|null $output_price
 * @property string|float|null $cache_read_price
 * @property string|float|null $cache_write_price
 * @property array<array-key, mixed>|null $reasoning_config
 * @property list<string>|null $supported_parameters
 * @property array<array-key, mixed>|null $pricing_rules
 * @property Carbon|string|null $release_date
 * @property Carbon|string|null $last_updated
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiModel extends Model
{
    use HasFactory;

    #[Fillable([
        'provider_id', 'model_id', 'name', 'description', 'family',
        'context_window', 'max_output_tokens', 'input_modalities', 'output_modalities',
        'supports_reasoning', 'supports_tools', 'supports_structured_output',
        'supports_temperature', 'open_weights', 'input_price', 'output_price',
        'cache_read_price', 'cache_write_price', 'reasoning_config',
        'supported_parameters', 'pricing_rules', 'release_date', 'last_updated', 'source',
    ])]
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_modalities' => 'array',
            'output_modalities' => 'array',
            'reasoning_config' => 'array',
            'supported_parameters' => 'array',
            'pricing_rules' => 'array',
            'supports_reasoning' => 'boolean',
            'supports_tools' => 'boolean',
            'supports_structured_output' => 'boolean',
            'supports_temperature' => 'boolean',
            'open_weights' => 'boolean',
            'release_date' => 'date',
            'last_updated' => 'date',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
