<?php

namespace App\Models;

use Database\Factories\AgentConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string|null $id
 * @property string $agent
 * @property list<int>|null $model_ids
 * @property list<int>|null $live_model_ids
 * @property string|null $provider_name
 * @property string|null $provider_api
 * @property bool $probe_models
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AgentConfig extends Model
{
    /** @use HasFactory<AgentConfigFactory> */
    use HasFactory;

    #[Fillable([
        'agent', 'model_ids','live_model_ids', 'provider_name', 'provider_api', 'probe_models',
    ])]
    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    protected function casts(): array
    {
        return [
            'model_ids' => 'array',
            'live_model_ids' => 'array',
            'probe_models' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AgentConfig $config): void {
            if ($config->id === null) {
                $config->id = (string) Str::ulid();
            }
        });
    }

    /** @return BelongsToMany<AiModel, $this> */
    public function models(): BelongsToMany
    {
        return $this->belongsToMany(AiModel::class, null, null, null, null, null, 'model_ids');
    }
}
