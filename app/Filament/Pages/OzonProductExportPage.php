<?php

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyNode;
use App\Models\OzonWarehouse;
use App\Models\Product;
use App\Services\Ozon\DTO\OzonPreparedProduct;
use App\Services\Ozon\DTO\OzonValidationResult;
use App\Services\Ozon\OzonProductPreparationService;
use App\Services\Ozon\OzonProductSelectionService;
use App\Support\AdminCategoryOptions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class OzonProductExportPage extends Page implements HasTable
{
    use InteractsWithTable;
    protected static string|BackedEnum|null $navigationIcon='heroicon-o-arrow-up-tray';
    protected static string|UnitEnum|null $navigationGroup='Ozon';
    protected static ?string $navigationLabel='Выгрузка товаров';
    protected static ?int $navigationSort=10;
    protected static ?string $slug='ozon-product-export';
    protected string $view='filament.pages.ozon-product-export-page';
    public array $previewRows=[];
    public array $preparationSettings=[];
    public function getTitle(): string { return 'Выгрузка товаров в Ozon'; }

    public function table(Table $table): Table
    {
        return $table->query(function(): Builder { $data=$this->tableFilters; return app(OzonProductSelectionService::class)->query(['category_id'=>$data['category']['value']??null,'include_descendants'=>$data['options']['include_descendants']??false,'include_additional_categories'=>$data['options']['include_additional_categories']??false,'brand_id'=>$data['brand']['value']??null,'ozon_account_id'=>$data['account']['value']??null,'active_only'=>$data['options']['active_only']??false,'in_stock_only'=>$data['options']['in_stock_only']??false,'priced_only'=>$data['options']['priced_only']??false,'has_image'=>$data['options']['has_image']??false,'has_description'=>$data['options']['has_description']??false,'has_attributes'=>$data['options']['has_attributes']??false,'not_added'=>$data['options']['not_added']??false,'sku'=>$data['search']['sku']??null,'name'=>$data['search']['name']??null]); })
            ->modelLabel('товар')->pluralModelLabel('товары')->emptyStateHeading('Товары не найдены')
            ->columns([ImageColumn::make('primaryImage.path')->label('Фото')->height(48),TextColumn::make('name')->label('Название')->limit(40)->wrap(),TextColumn::make('sku')->label('SKU'),TextColumn::make('brand.name')->label('Бренд'),TextColumn::make('category.name')->label('Категория'),TextColumn::make('price')->label('Цена'),TextColumn::make('quantity')->label('Остаток'),TextColumn::make('images_count')->label('Фото'),IconColumn::make('description')->label('Описание')->state(fn(Product $r)=>filled($r->description))->boolean(),TextColumn::make('attributes_count')->label('Характеристики'),TextColumn::make('ozon_status')->label('Ozon-статус')->state(fn(Product $r)=>$r->relationLoaded('ozonProducts')?($r->ozonProducts->first()?->status?->label()??'Не добавлен'):'Выберите аккаунт')->badge(),TextColumn::make('readiness')->label('Готовность')->state(fn(Product $r)=>$this->blockingReason($r)===null?'Можно подготовить':'Заблокирован')->badge(),TextColumn::make('blocking_reason')->label('Причина блокировки')->state(fn(Product $r)=>$this->blockingReason($r)??'—')->wrap()])
            ->filters([SelectFilter::make('category')->label('Категория сайта')->options(fn()=>AdminCategoryOptions::active())->searchable()->query(fn(Builder $query)=>$query),SelectFilter::make('account')->label('Аккаунт Ozon')->options(fn()=>OzonAccount::query()->pluck('name','id'))->query(fn(Builder $query)=>$query),SelectFilter::make('brand')->label('Бренд')->options(fn()=>Brand::query()->orderBy('name')->pluck('name','id'))->query(fn(Builder $query)=>$query),Filter::make('options')->form([Checkbox::make('include_descendants')->label('Включать дочерние категории'),Checkbox::make('include_additional_categories')->label('Учитывать дополнительные категории'),Checkbox::make('active_only')->label('Только активные'),Checkbox::make('in_stock_only')->label('Остаток больше нуля'),Checkbox::make('priced_only')->label('Цена больше нуля'),Checkbox::make('has_image')->label('Есть главное фото'),Checkbox::make('has_description')->label('Есть описание'),Checkbox::make('has_attributes')->label('Есть характеристики'),Checkbox::make('not_added')->label('Ещё не добавлены в кабинет')]),Filter::make('search')->form([TextInput::make('sku')->label('SKU'),TextInput::make('name')->label('Название')])])
            ->bulkActions([BulkAction::make('prepare')->label('Подготовить для Ozon')->form($this->preparationForm())->action(function(Collection $records,array $data): void { $node=OzonTaxonomyNode::query()->findOrFail($data['ozon_taxonomy_node_id']); $data += ['description_category_id'=>$node->description_category_id,'description_category_name'=>$node->category_name,'type_id'=>$node->type_id,'type_name'=>$node->type_name,'manual_warehouse'=>false]; $this->preparationSettings=$data; $this->previewRows=collect(app(OzonProductPreparationService::class)->prepareBatch($records,$data))->map(fn(array $row)=>['product_id'=>$row['preview']->product->id,'snapshot'=>$row['preview']->snapshot,...$row['preview']->validation->toArray()])->all(); Notification::make()->title('Dry-run готов')->body('Данные не сохранены и не отправлены в Ozon.')->success()->send(); })->modalSubmitActionLabel('Выполнить dry-run')->deselectRecordsAfterCompletion()]);
    }

    protected function getHeaderActions(): array { return [Action::make('savePrepared')->label('Сохранить подготовленные товары')->visible(fn()=>count($this->previewRows)>0)->requiresConfirmation()->modalDescription('Данные сохранятся только локально и не будут отправлены в Ozon.')->action(function(): void { $saved=0; foreach($this->previewRows as $row){ $prepared=new OzonPreparedProduct(Product::query()->findOrFail($row['product_id']),$row['snapshot'],new OzonValidationResult($row['errors'],$row['warnings'])); if(app(OzonProductPreparationService::class)->save($prepared,$this->preparationSettings)) $saved++; } Notification::make()->title('Сохранено локально: '.$saved)->success()->send(); $this->previewRows=[]; })]; }
    public function batchSummary(): array { $selected=count($this->previewRows); $prepared=collect($this->previewRows)->where('is_ready',true)->count(); $warnings=collect($this->previewRows)->filter(fn($row)=>$row['is_ready']&&$row['has_warnings'])->count(); return ['selected'=>$selected,'prepared'=>$prepared,'warnings'=>$warnings,'skipped'=>$selected-$prepared]; }
    private function preparationForm(): array { return [Select::make('ozon_account_id')->label('Аккаунт Ozon')->options(fn()=>OzonAccount::query()->where('is_active',true)->pluck('name','id'))->live()->required(),Select::make('ozon_taxonomy_node_id')->label('Категория и тип Ozon')->options(fn(Get $get)=>OzonTaxonomyNode::query()->where('ozon_account_id',$get('ozon_account_id'))->where('is_disabled',false)->where('type_id','!=','0')->orderBy('category_name')->get()->mapWithKeys(fn($node)=>[$node->id=>$node->category_name.' — '.$node->type_name])->all())->searchable()->required(),Select::make('ozon_warehouse_id')->label('Подтверждённый склад')->options(fn(Get $get)=>OzonWarehouse::query()->where('ozon_account_id',$get('ozon_account_id'))->where('is_api_confirmed',true)->where('is_active',true)->orderBy('name')->pluck('name','id'))->required(),TextInput::make('price_multiplier')->label('Коэффициент цены')->numeric()->minValue(0.0001)->default(1)->required(),Select::make('rounding_rule')->label('Способ округления')->options(['none'=>'Без округления','integer'=>'До целого','nearest_10'=>'Ближайшие 10','nearest_100'=>'Ближайшие 100','up_to_10'=>'Вверх до 10','up_to_100'=>'Вверх до 100'])->default('none'),TextInput::make('stock_limit')->label('Лимит остатка')->numeric()->minValue(0),TextInput::make('weight_g')->label('Вес, г')->numeric()->minValue(0),TextInput::make('width_mm')->label('Ширина, мм')->numeric()->minValue(0),TextInput::make('height_mm')->label('Высота, мм')->numeric()->minValue(0),TextInput::make('depth_mm')->label('Глубина, мм')->numeric()->minValue(0),TextInput::make('tnved_code')->label('ТН ВЭД'),Checkbox::make('price_sync_enabled')->label('Разрешить будущую синхронизацию цены')->default(true),Checkbox::make('stock_sync_enabled')->label('Разрешить будущую синхронизацию остатка')->default(true)]; }
    private function blockingReason(Product $product): ?string { if(!filled($product->sku)) return 'Нет SKU'; if(!filled($product->name)) return 'Нет названия'; if(!is_numeric($product->price)||(float)$product->price<=0) return 'Нет положительной цены'; if($product->images_count<1&&!filled($product->primary_image)) return 'Нет главного фото'; if($product->relationLoaded('ozonProducts')&&$product->ozonProducts->isNotEmpty()) return 'Уже добавлен в выбранный кабинет'; return null; }
}
