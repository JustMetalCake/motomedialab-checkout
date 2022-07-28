<?php

namespace Motomedialab\Checkout\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Motomedialab\Checkout\Models\Order;

/**
 * @property-read Order $resource
 */
class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->resource->uuid,
            'currency' => $this->resource->currency,
            'products' => ProductResource::collection($this->resource->products()->get())->toArray($request),
            'vat_rate' => $this->resource->hasBeenSubmitted() ? $this->resource->vat_rate : config('checkout.default_vat_rate'),
            'totals' => [
                'amount_in_pence' => $this->resource->amount_in_pence,
                'shipping_in_pence' => $this->resource->shipping_in_pence,
                'discount_in_pence' => $this->resource->discount_in_pence ?: null,
            ],
            'voucher' => $this->resource->voucher?->code,
        ];
    }
}