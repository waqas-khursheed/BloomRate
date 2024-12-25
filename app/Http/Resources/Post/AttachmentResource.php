<?php

namespace App\Http\Resources\Post;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                    =>  $this->id,
            'media'                 =>  $this->media,
            'media_type'            =>  $this->media_type,
            'media_thumbnail'       =>  $this->media_thumbnail,
        ];
    }
}
