<?php
/**
 * @author MotoMediaLab <hello@motomedialab.com>
 * Created at: 26/07/2022
 */

namespace Motomedialab\Checkout\Tests\Feature\Api;

use Motomedialab\Checkout\Enums\ProductStatus;
use Motomedialab\Checkout\Models\Product;
use Motomedialab\Checkout\Tests\TestCase;

class ProductControllerTest extends TestCase
{
    
    /**
     * @test
     **/
    function a_request_requires_a_currency_to_be_defined()
    {
        $product = factory(Product::class)->create();
        
        $this->getJson(route('checkout.product', $product))
            ->assertJsonValidationErrorFor('currency');
    }
    
    /**
     * @test
     **/
    function a_product_can_be_retrieved_by_id()
    {
        $product = factory(Product::class)->create();
        
        $response = $this->getJson(route('checkout.product', [$product, 'currency' => 'gbp']));
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'pricing',
                    'available'
                ]
            ]);
    }
    
    /**
     * @test
     **/
    function a_product_with_children_returns_variants()
    {
        $product = factory(Product::class)->create([
            'pricing_in_pence' => ['gbp' => 8995, 'eur' => 1100],
            'shipping_in_pence' => ['eur' => 1299, 'gbp' => 999],
        ]);
        $child = factory(Product::class)->create([
            'parent_product_id' => $product->getKey(),
            'pricing_in_pence' => [],
            'shipping_in_pence' => [],
        ]);
        
        $response = $this->getJson(route('checkout.product', [$product, 'currency' => 'gbp']));
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'children' => [
                        [
                            'id',
                            'name',
                            'pricing',
                            'available'
                        ]
                    ]
                ]
            ]);
    }
    
}