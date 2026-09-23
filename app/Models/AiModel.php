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
 * @property string $model_key
 * @property string $name
 * @property string|null $description
 * @property string|null $family
 * @property string|null $type
 * @property list<string>|array{input?: list<string>, output?: list<string>}|null $modalities
 * @property int|null $context_window
 * @property int|null $limit_input
 * @property int|null $max_output_tokens
 * @property bool $reasoning
 * @property list<array{type?: string, values?: list<string>, min?: int}>|null $reasoning_options
 * @property string|null $reasoning_default_effort
 * @property list<string>|null $reasoning_efforts
 * @property bool $reasoning_mandatory
 * @property bool|null $reasoning_interleaved
 * @property bool $tool_call
 * @property bool $attachment
 * @property bool $open_weights
 * @property string|float|null $cost_input
 * @property string|float|null $cost_output
 * @property string|float|null $cost_cache_read
 * @property string|float|null $cost_cache_write
 * @property string|float|null $cost_input_audio
 * @property string|float|null $cost_output_audio
 * @property string|float|null $cost_reasoning
 * @property array<array-key, mixed>|null $cost_context_over_200k
 * @property list<array<array-key, mixed>>|null $cost_tiers
 * @property array<array-key, mixed>|null $experimental
 * @property array<array-key, mixed>|null $provider_overrides
 * @property list<string>|null $supported_parameters
 * @property array<array-key, mixed>|null $default_parameters
 * @property array<array-key, mixed>|null $benchmarks
 * @property array<array-key, mixed>|null $pricing
 * @property string|null $canonical_slug
 * @property array<array-key, mixed>|null $links
 * @property array{slug?: string, name?: string}|null $alias_target
 * @property string|null $status
 * @property Carbon|string|null $release_date
 * @property Carbon|string|null $last_updated
 * @property string|null $knowledge
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiModel extends Model
{
    use HasFactory;

    #[Fillable([
        'provider_id', 'model_key', 'name', 'description', 'family',
        'type', 'context_window', 'limit_input', 'max_output_tokens', 'modalities',
        'reasoning', 'reasoning_options', 'reasoning_default_effort',
        'reasoning_efforts', 'reasoning_mandatory', 'reasoning_interleaved',
        'tool_call', 'attachment', 'open_weights',
        'cost_input', 'cost_output', 'cost_cache_read', 'cost_cache_write',
        'cost_input_audio', 'cost_output_audio', 'cost_reasoning',
            'cost_context_over_200k', 'cost_tiers',
        'experimental', 'provider_overrides',
        'supported_parameters', 'default_parameters', 'benchmarks',
        'pricing', 'canonical_slug', 'links', 'alias_target',
        'status', 'release_date', 'last_updated', 'knowledge', 'source',
        'structured_output', 'temperature',
    ])]
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'modalities' => 'array',
            'reasoning_options' => 'array',
            'reasoning_efforts' => 'array',
            'supported_parameters' => 'array',
            'default_parameters' => 'array',
            'benchmarks' => 'array',
            'cost_tiers' => 'array',
            'cost_context_over_200k' => 'array',
            'experimental' => 'array',
            'provider_overrides' => 'array',
            'pricing' => 'array',
            'links' => 'array',
            'alias_target' => 'array',
            'reasoning' => 'boolean',
            'tool_call' => 'boolean',
            'attachment' => 'boolean',
            'open_weights' => 'boolean',
            'reasoning_mandatory' => 'boolean',
            'reasoning_interleaved' => 'boolean',
            'structured_output' => 'boolean',
            'temperature' => 'boolean',
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
