<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'interest_id', 'title', 'post_type', 'media', 'media_type', 'media_thumbnail', 'group_id', 'is_group_post'
    ];

    public function interest()
    {
        return $this->belongsTo(Interest::class, 'interest_id')->select('id', 'title');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->with('status');
    }

    public function parent_post()
    {
        return $this->belongsTo(Post::class, 'parent_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'post_id')->with('user')->latest()->select(['id', 'user_id', 'post_id', 'comment', 'created_at']);
    }

    public function likes()
    {
        return $this->hasMany(Like::class, 'record_id')->where('like_type', 'post');
    }

    public function post_view()
    {
        return $this->hasMany(PostView::class, 'post_id');
    }

    public function attachment()
    {
        return $this->hasMany(PostAttachment::class, 'post_id');
    }
}
