<?php

namespace Dskripchenko\DelayedProcess\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string $uuid
 */
class DelayedProcess extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'delayed' => [
                'uuid' => $this->uuid
            ]
        ];
    }
}
