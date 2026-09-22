<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property int|null $context_window
 * @property int|null $max_output_tokens
 * @property array|null $modalities
 * @property bool $reasoning
 * @property bool $tool_call
 * @property bool $attachment
 * @property bool $open_weights
 * @property string|float|null $cost_input
 * @property string|float|null $cost_output
 * @property string|null $status
 * @property Carbon|string|null $release_date
 * @property Carbon|string|null $last_updated
 * @property string|null $knowledge
 * @property string $source
 * @property array|null $raw
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiModel extends Model
{
    #[Fillable([
        'provider_id', 'model_key', 'name', 'description', 'family',
        'context_window', 'max_output_tokens', 'modalities',
        'reasoning', 'tool_call', 'attachment', 'open_weights',
        'cost_input', 'cost_output', 'status', 'release_date', 'last_updated',
        'knowledge', 'source', 'reasoning_mandatory', 'reasoning_default_effort',
        'reasoning_efforts', 'reasoning_options', 'supported_parameters',
        'structured_output', 'temperature', 'raw',
    ])]
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'modalities' => 'array',
            'reasoning_efforts' => 'array',
            'reasoning_options' => 'array',
            'supported_parameters' => 'array',
            'raw' => 'array',
            'reasoning' => 'boolean',
            'tool_call' => 'boolean',
            'attachment' => 'boolean',
            'open_weights' => 'boolean',
            'reasoning_mandatory' => 'boolean',
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
