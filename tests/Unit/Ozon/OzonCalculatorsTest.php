<?php

namespace Tests\Unit\Ozon;
use App\Models\OzonAccount; use App\Models\OzonProduct; use App\Models\Product; use App\Services\Ozon\OzonPriceCalculator; use App\Services\Ozon\OzonStockCalculator; use InvalidArgumentException; use PHPUnit\Framework\Attributes\DataProvider; use PHPUnit\Framework\TestCase;
class OzonCalculatorsTest extends TestCase
{
 #[DataProvider('roundingRules')] public function test_price_rules(string $rule,string $expected): void { $product=new Product(['price'=>'123.45']); $account=new OzonAccount(['default_price_multiplier'=>'1.2','rounding_rule'=>$rule]); self::assertSame($expected,(new OzonPriceCalculator)->calculate($product,$account)); }
 public static function roundingRules(): array { return [['none','148.14'],['integer','148.00'],['nearest_10','150.00'],['nearest_100','100.00'],['up_to_10','150.00'],['up_to_100','200.00']]; }
 public function test_manual_and_individual_price_take_precedence(): void { $p=new Product(['price'=>'100']);$a=new OzonAccount(['default_price_multiplier'=>'3']);$o=new OzonProduct(['manual_ozon_price'=>'50','price_multiplier'=>'2']);self::assertSame('100.00',(new OzonPriceCalculator)->calculate($p,$a,$o)); }
 public function test_non_positive_price_is_rejected(): void { $this->expectException(InvalidArgumentException::class);(new OzonPriceCalculator)->calculate(new Product(['price'=>0]),new OzonAccount(['default_price_multiplier'=>1])); }
 public function test_stock_uses_quantity_not_stock_quantity(): void { $p=new Product(['quantity'=>8,'stock_quantity'=>999]);self::assertSame(5,(new OzonStockCalculator)->calculate($p,5));$p->quantity=-4;self::assertSame(0,(new OzonStockCalculator)->calculate($p)); }
}
