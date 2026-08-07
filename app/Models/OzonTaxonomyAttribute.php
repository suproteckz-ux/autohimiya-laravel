<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class OzonTaxonomyAttribute extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['is_required'=>'boolean','is_collection'=>'boolean','values_payload'=>'array','raw_payload'=>'array','synced_at'=>'datetime']; }
    public function node(): BelongsTo { return $this->belongsTo(OzonTaxonomyNode::class, 'ozon_taxonomy_node_id'); }
}
