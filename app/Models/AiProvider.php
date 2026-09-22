<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $api
 * @property string|null $doc
 * @property string|null $npm
 * @property array|null $env
 * @property bool $is_active
 * @property array|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiProvider extends Model
{
    #[Fillable([
        'slug', 'name', 'api', 'doc', 'npm', 'env', 'is_active', 'meta',
    ])]
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'env' => 'array',
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<AiModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }
}
