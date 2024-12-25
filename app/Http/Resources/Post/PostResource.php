<?php

namespace App\Http\Resources\Post;

use App\Models\Favorite;
use App\Models\Follow;
use App\Models\Like;
use App\Models\Post;
use App\Models\SavePost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                =>  $this->id,
            'title'             =>  $this->title,
            'post_type'         =>  $this->post_type,
            // 'media'             =>  $this->media,
            // 'media_thumbnail'   =>  $this->media_thumbnail,
            // 'media_type'        =>  $this->media_type,
            'is_share'          =>  $this->is_share,
            'parent_post'       =>  new PostResource($this->parent_post),
            'created_at'        =>  $this->created_at,
            'interest'          =>  $this->interest,
            'created_by'            => [
                'id'                    =>  $this->user->id,
                'full_name'             =>  $this->user->full_name,
                'user_name'             =>  $this->user->user_name,
                'profile_image'         =>  $this->user->profile_image,
                'status'                =>  $this->user->status,
                'is_follow'             =>  Follow::where(['follower_id' => auth()->id(), 'following_id' => $this->user->id, 'status' => 'accept'])->count()
            ],
            // 'post_views_count'      =>  $this->post_views_count,
            'is_group_post'         =>  $this->is_group_post,
            'group'                 =>  $this->group,
            'likes_count'           =>  $this->likes_count,
            'comments_count'        =>  $this->comments_count,
            'post_view_count'       =>  $this->post_view_count,
            'is_like'               =>  Like::where(['user_id' => auth()->id(), 'record_id' => $this->id, 'like_type' => 'post'])->count(),
            'reaction_types'        =>  $this->reaction_types($this->id),
            'total_share_count'     =>  Post::where(['parent_id' => $this->id, 'is_share' => '1'])->count(),
            'is_favorite'           =>  Favorite::where(['post_id' => $this->id, 'user_id' => auth()->id()])->count(),
            'is_save'               =>  SavePost::where(['post_id' => $this->id, 'user_id' => auth()->id()])->count(),
            // 'comments'              => $this->comments, // CommentResource::collection($this->comments),
            'attachment'              => AttachmentResource::collection($this->attachment)

        ];
    }

    private function reaction_types($postId)
    {
        return Like::where(['record_id' => $postId, 'like_type' => 'post'])->groupBy('record_id', 'reaction_type')->pluck('reaction_type');
    }
}
