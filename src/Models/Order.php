<?php

namespace Motomedialab\Checkout\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Motomedialab\Checkout\Contracts\CalculatesDiscountValue;
use Motomedialab\Checkout\Contracts\CalculatesProductsShipping;
use Motomedialab\Checkout\Contracts\CalculatesProductsValue;
use Motomedialab\Checkout\Contracts\ValidatesVoucher;
use Motomedialab\Checkout\Enums\OrderStatus;
use Motomedialab\Checkout\Events\OrderStatusUpdated;
use Motomedialab\Checkout\Exceptions\InvalidVoucherException;
use Motomedialab\Checkout\Helpers\Money;
use Motomedialab\Checkout\Models\Pivots\OrderPivot;
use Ramsey\Uuid\Lazy\LazyUuidFromString;
use Ramsey\Uuid\Uuid;

/**
 * @property OrderStatus $status
 * @property string $currency
 * @property LazyUuidFromString $uuid
 * @property integer $amount_in_pence
 * @property integer $discount_in_pence
 * @property integer $shipping_in_pence
 * @property Money $amount
 * @property Money $shipping
 * @property Money $total
 * @property Money $discount
 * @property Collection $products
 * @property ?Voucher $voucher
 * @property float $vat_rate
 */
class Order extends Model
{
    protected $casts = [
        'status' => OrderStatus::class,
        'recipient_address' => 'array',
        'amount_in_pence' => 'integer',
        'shipping_in_pence' => 'integer',
        'discount_in_pence' => 'integer',
    ];
    
    protected $guarded = [];
    
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->setTable(config('checkout.tables.orders'));
    }
    
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
    
    /**
     * @param  string  $uuid
     *
     * @return Order
     */
    public static function findByUuid(string $uuid): Order
    {
        return static::query()->where('uuid', $uuid)->firstOrFail();
    }
    
    protected static function boot()
    {
        parent::boot();
        
        // enforce a UUID for our order
        static::creating(fn($model) => $model->uuid = Uuid::uuid4());
        
        static::updating(function (Order $order) {
            
            if ($order->hasBeenSubmitted()) {
                return true;
            }
            
            // persist our amounts
            $order->vat_rate = config('checkout.default_vat_rate');
            $order->amount_in_pence = app(CalculatesProductsValue::class)($order->products, $order->currency);
            $order->shipping_in_pence = app(CalculatesProductsShipping::class)($order->products, $order->currency);
            $order->discount_in_pence = $order->voucher ? app(CalculatesDiscountValue::class)($order->products,
                $order->voucher, $order->currency) : 0;
            
            return true;
        });
    }
    
    /**
     * An order has many products.
     *
     * @return BelongsToMany
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, config('checkout.tables.order_product'))
            ->withPivot(['quantity', 'amount_in_pence', 'vat_rate'])
            ->as('orderPivot')
            ->using(OrderPivot::class);
    }
    
    /**
     * An order has an owner.
     *
     * @return MorphTo
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }
    
    /**
     * An order belongs to a voucher
     *
     * @return BelongsTo
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
    
    /**
     * Mark an order as confirmed.
     * This will perform a final validation.
     *
     * @return $this
     *
     * @throws InvalidVoucherException
     */
    public function confirm(): static
    {
        if ($this->voucher) {
            app(ValidatesVoucher::class)($this->voucher);
        }
        
        $this->setStatus(OrderStatus::AWAITING_PAYMENT);
        
        return $this;
    }
    
    /**
     * Set the status of an order.
     *
     * @param  OrderStatus  $status
     *
     * @return Order
     */
    public function setStatus(OrderStatus $status): static
    {
        $this->status = $status;
        $this->save();
        
        event(new OrderStatusUpdated($this, $status));
        
        return $this;
    }
    
    protected function amountInPence(): Attribute
    {
        return new Attribute(
            get: fn($value) => (int)$this->hasBeenSubmitted()
                ? $value
                : app(CalculatesProductsValue::class)($this->products, $this->currency)
        );
    }
    
    protected function discountInPence(): Attribute
    {
        return new Attribute(
            get: fn($value) => (int)$this->hasBeenSubmitted() || !$this->voucher
                ? $value
                : app(CalculatesDiscountValue::class)($this->products, $this->voucher, $this->currency)
        );
    }
    
    protected function shippingInPence(): Attribute
    {
        return new Attribute(
            get: fn($value) => (int)$this->hasBeenSubmitted()
                ? $value
                : app(CalculatesProductsShipping::class)($this->products, $this->currency)
        );
    }
    
    public function hasBeenSubmitted(): bool
    {
        return $this->exists && $this->status && $this->status !== OrderStatus::PENDING;
    }
    
    
    protected function amount(): Attribute
    {
        return new Attribute(
            get: fn() => Money::make($this->amount_in_pence, $this->currency)
        );
    }
    
    protected function shipping(): Attribute
    {
        return new Attribute(
            get: fn() => Money::make($this->shipping_in_pence, $this->currency)
        );
    }
    
    protected function discount(): Attribute
    {
        return new Attribute(
            get: fn() => Money::make($this->discount_in_pence, $this->currency)
        );
    }
    
    protected function total(): Attribute
    {
        return new Attribute(
            get: fn() => $this->amount->add($this->shipping)->subtract($this->discount)
        );
    }
    
    protected function vatRate(): Attribute
    {
        return new Attribute(
            get: fn($value) => $this->hasBeenSubmitted()
                ? $value
                : config('checkout.default_vat_rate'),
            set: fn($value) => $value,
        );
    }
}