<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductFiles extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'file_id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
