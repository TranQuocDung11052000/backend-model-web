<?php

namespace App\Models;

use App\DTO\Product\CreateDTO;
use App\Jobs\UploadFileToS3;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HigherOrderCollectionProxy;
use Throwable;

/**
 * @mixin  Builder
 * @property int $id
 * @property string $name
 * @property int $category_id
 * @property int|null $platform_id
 * @property int|null $render_id
 * @property int $user_id
 * @property bool $public
 * @property string $status
 * @property-read Category $category
 * @property-read Platform|null $platform
 * @property-read Render|null $render
 * @property-read User $user
 * @property-read Collection|Color[] $colors
 * @property-read int|null $colors_count
 * @property-read Collection|Material[] $materials
 * @property-read int|null $materials_count
 * @property-read Collection|File[] $files
 * @property-read int|null $files_count
 * @property-read Collection|Tag[] $tags
 * @property-read int|null $tags_count
 * @property-read Collection|Library[] $libraries
 * @property-read int|null $libraries_count
 * @property-read Collection|ProductFiles[] $productFiles
 * @property-read int|null $product_files_count
 * @property-read Collection|File[] $modelFiles
 * @property-read int|null $model_files_count
 * @property-read Collection|File[] $imageFiles
 * @property-read int|null $image_files_count
 * @property mixed $thumbnail
 */
class Product extends Model
{
    use HasFactory;

    /**
     * @var HigherOrderCollectionProxy|mixed
     */
    protected $fillable = [
        'name',
        'user_id',
        'category_id',
        'platform_id',
        'render_id',
        'color_id',
        'material_id',
        'public',
        'status'
    ];

    protected $casts = [
        'status' => 'string'
    ];

    public const STATUS_DRAFT = "DRAFT";
    public const STATUS_PENDING_APPROVAL = "PENDING_APPROVAL";
    public const STATUS_APPROVED = "APPROVED";
    public const STATUS_REJECT = "REJECT";
    public const STATUS_LIST = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_REJECT
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'platform_id');
    }

    public function render(): BelongsTo
    {
        return $this->belongsTo(Render::class, 'render_id');
    }

    public function colors(): BelongsToMany
    {
        return $this->belongsToMany(Color::class, 'product_colors');
    }

    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Material::class, 'product_materials');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'product_files', 'product_id', 'file_id')
            ->withPivot('is_thumbnail'); // Thêm trường từ bảng trung gian
    }

    public function modelFiles(): BelongsToMany
    {
        return $this->belongsToMany(File::class, ProductFiles::class, 'product_id', 'file_id')
            ->withPivot('is_model')
            ->wherePivot('is_model', true);
    }

    public function imageFiles(): BelongsToMany
    {
        return $this->belongsToMany(File::class, ProductFiles::class, 'product_id', 'file_id')
            ->withPivot('is_model', 'is_thumbnail')
            ->wherePivot('is_model', false)
            ->orWherePivotNull('is_model');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tags');
    }

    public function libraries(): BelongsToMany
    {
        return $this->belongsToMany(Library::class, 'library_product', 'product_id', 'library_id');
    }

    public function productFiles(): HasMany
    {
        return $this->hasMany(ProductFiles::class, 'product_id');
    }

    public function createProduct(CreateDTO $validatedData)
    {
        try {
            DB::beginTransaction();

            $uploadedBy = Auth::id();

            $product = Product::create([
                'name' => $validatedData->name,
                'category_id' => $validatedData->category_id,
                'platform_id' => $validatedData->platform_id,
                'render_id' => $validatedData->render_id,
                'status' => Product::STATUS_DRAFT,
                'user_id' => $uploadedBy,
                'public' => true
            ]);

            // 🛑 Lưu Colors vào bảng `product_colors`
            if (!empty($validatedData->color_ids)) {
                $product->colors()->attach($validatedData->color_ids);
            }
            // 🛑 Lưu Materials vào bảng `product_materials`
            if (!empty($validatedData->material_ids)) {
                $product->materials()->attach($validatedData->material_ids);
            }
            // 🛑 Lưu Tags vào bảng `product_tags`
            if (!empty($validatedData->tag_ids)) {
                $product->tags()->attach($validatedData->tag_ids);
            }

            // 🛑 Lưu file model (`file_url`) vào DB trước khi upload lên S3
            // 🛑 Xử lý `file_url` (model file)
            $fileName = basename($validatedData->file_url);
            $fileRecord = File::create([
                'file_name' => $fileName,
                'file_path' => config('app.file_path') . File::MODEL_FILE_PATH . $fileName,
                'uploaded_by' => $uploadedBy
            ]);

            // 🔥 Đẩy lên queue để upload lên S3
            dispatch(new UploadFileToS3($fileRecord->id, $validatedData->file_url, 'models'));

            ProductFiles::create([
                'file_id' => $fileRecord->id,
                'product_id' => $product->id,
                'is_model' => true
            ]);

            if (!empty($validatedData->image_urls) && is_array($validatedData->image_urls)) {
                $imageUrls = array_values($validatedData->image_urls);

                foreach ($imageUrls as $key => $imageUrl) {
                    $imgName = basename($imageUrl);
                    $imageRecord = File::create([
                        'file_name' => $imgName,
                        'file_path' => config("app.file_path") . File::IMAGE_FILE_PATH . $imgName,
                        'uploaded_by' => $uploadedBy
                    ]);

                    dispatch(new UploadFileToS3($imageRecord->id, $imageUrl, 'images'));

                    ProductFiles::create([
                        'file_id' => $imageRecord->id,
                        'product_id' => $product->id,
                        'is_thumbnail' => $key == 0,
                    ]);
                }
            }

            DB::commit();

            return $product->load('colors', 'materials', 'tags');
        } catch (Throwable $e) {
            try {
                DB::rollBack();
            } catch (Throwable $e) {
            }
            return [
                "error" => $e,
                "msg" => $e->getMessage()
            ];
        }
    }
}
