<?php

namespace Motomedialab\Checkout\Factories;

use Illuminate\Support\Collection;
use Motomedialab\Checkout\Contracts\ComparesVoucher;
use Motomedialab\Checkout\Contracts\ValidatesVoucher;
use Motomedialab\Checkout\Enums\OrderStatus;
use Motomedialab\Checkout\Enums\ProductStatus;
use Motomedialab\Checkout\Exceptions\CheckoutException;
use Motomedialab\Checkout\Exceptions\InvalidVoucherException;
use Motomedialab\Checkout\Exceptions\UnsupportedCurrencyException;
use Motomedialab\Checkout\Models\Order;
use Motomedialab\Checkout\Models\Product;
use Motomedialab\Checkout\Models\Voucher;

class OrderFactory
{
    protected Collection $basket;
    protected Voucher|null $voucher = null;
    
    public function __construct(protected Order $order)
    {
        $this->basket = collect();
    
        // hydrate our data.
        $this->voucher = $order->voucher;
        $this->order->products->each(function (Product $product) {
            $this->add($product, $product->orderPivot->quantity);
        });
    }
    
    /**
     * Create a new OrderFactory instance.
     *
     * @param  string  $currency
     *
     * @return OrderFactory
     *
     * @throws UnsupportedCurrencyException
     */
    public static function make(string $currency): OrderFactory
    {
        $currency = strtoupper($currency);
        
        if (!array_key_exists($currency, config('checkout.currencies'))) {
            throw new UnsupportedCurrencyException();
        }
        
        return new static(new Order([
            'currency' => $currency,
            'status' => OrderStatus::PENDING,
        ]));
    }
    
    public static function fromExisting(Order $order): OrderFactory
    {
        return new static($order);
    }
    
    public function add(Product $product, int $quantity = 1, bool $increment = false): static
    {
        if (!$product->availableInCurrency($this->order->currency)) {
            throw new CheckoutException($product->name.' is not available in the requested currency');
        }
        
        if ($product->status === ProductStatus::UNAVAILABLE) {
            throw new CheckoutException($product->name.' is not available for purchase');
        }
        
        $product->quantity = ($increment ? $this->basket->firstWhere('id',
                $product->getKey())?->quantity : 0) + $quantity;
        
        // should this item be removed from the basket?
        if ($product->quantity === 0 && false === $increment) {
            $this->remove($product);
            return $this;
        }
        
        $this->remove($product);
        $this->basket->push($product);
        
        return $this;
    }
    
    public function remove(Product $product): static
    {
        $this->basket = $this->basket->reject(fn(Product $item) => $item->is($product));
        
        return $this;
    }
    
    public function setAddress(array $address): static
    {
        $this->order->setAttribute('recipient_address', $address);
        
        return $this;
    }
    
    public function applyVoucher(?Voucher $voucher): static
    {
        if (is_null($voucher)) {
            $this->voucher = null;
            return $this;
        }
        
        
        if ($this->voucher) {
            
            $voucherDifference = app(ComparesVoucher::class)(
                $this->basket,
                $this->order->currency,
                $this->voucher,
                $voucher
            );
            
            if ($voucherDifference < 0) {
                throw new InvalidVoucherException(
                    'The voucher you are trying to apply is worth less than your current voucher'
                );
            }
        }
        
        if (app(ValidatesVoucher::class)($voucher, $this->basket)) {
            $this->voucher = $voucher;
        }
        
        return $this;
    }
    
    public function removeVoucher(): static
    {
        return $this->applyVoucher(null);
    }
    
    public function save(): Order
    {
        $this->order->setStatus(OrderStatus::PENDING);
        $this->order->save();
        
        $this->order->products()->sync(
            $this->basket->mapWithKeys(function (Product $product, int $key) {
                return [
                    $product->getKey() => [
                        'quantity' => $product->quantity,
                        'amount_in_pence' => $product->price($this->order->currency)->toPence(),
                        'vat_rate' => $product->vat_rate,
                    ]
                ];
            })->toArray()
        );
    
        $this->order->voucher()->associate($this->voucher);
        
        return $this->order = tap($this->order->refresh())->save();
    }
    
}