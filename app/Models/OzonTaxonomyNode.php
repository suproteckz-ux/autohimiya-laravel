<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class OzonTaxonomyNode extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['is_disabled'=>'boolean','raw_payload'=>'array','synced_at'=>'datetime']; }
    public function account(): BelongsTo { return $this->belongsTo(OzonAccount::class, 'ozon_account_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
    public function attributes(): HasMany { return $this->hasMany(OzonTaxonomyAttribute::class); }
}
