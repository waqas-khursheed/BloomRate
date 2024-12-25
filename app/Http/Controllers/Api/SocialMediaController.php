<?php

namespace App\Http\Controllers\Api;

use App\Enums\GroupMemberRole;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\Group\GroupResource;
use App\Http\Resources\Post\CommentResource;
use App\Http\Resources\Post\LikeResource;
use App\Http\Resources\Post\PostResource;
use App\Models\Comment;
use App\Models\Favorite;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Like;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostView;
use App\Models\Report;
use App\Models\SavePost;
use App\Models\User;
use App\Models\Follow;
use App\Models\PostAttachment;
use App\Models\UserInterest;
use App\Models\GroupRequest;
use App\Models\InterestVideo;



use App\Models\GroupInterest;
// use App\Models\PostView;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SocialMediaController extends Controller
{
    use ApiResponser;

    /*
    |--------------------------------------------------------------------------
    |       POST MODULE
    |--------------------------------------------------------------------------
    */

    /** List post */
    public function listPost(Request $request)
    {
        $this->validate($request, [
            'offset'       =>   'required|numeric',
            'user_id'      =>   'nullable|exists:users,id',
            'list_type'    =>   'nullable|in:feeds,trending',
            'interest_id'  =>   'required_if:list_type,==,trending',
            'post_type'    =>   'nullable|in:thoughts,photo,video',
            'group_id'     =>   'nullable|exists:groups,id'
        ]);

        // Get reported posts
        // $reportedPost = Report::where('user_id', auth()->id())->pluck('post_id');

        $posts = Post::with('user', 'attachment')->withCount('comments', 'likes', 'post_view')->latest()
            // ->whereNotIn('id', $reportedPost)
            ->where('is_block', '0')
            ->when($request->has('list_type') && $request->list_type == 'trending' && $request->interest_id > 0, function ($q) use ($request) {
                $q->where('interest_id', $request->interest_id);
            })
            ->when($request->has('user_id'), function ($q) use ($request) {
                $q->where('user_id', $request->user_id);
            })
            ->when($request->has('post_type'), function ($q) use ($request) {
                $q->where('post_type', $request->post_type);
            })
            // Getting group posts
            ->when($request->has('group_id'), function ($q) use ($request) {
                $q->where('group_id', $request->group_id);
            })
            // Checking if not getting group posts
            ->when(!$request->has('group_id'), function ($q) use ($request) {
                $q->where('is_group_post', '0');
            });
        $totalPosts = $posts->count();
        $posts = $posts->skip($request->offset)->take(10)->get();

        // return $posts;

        if (count($posts) > 0) {
            $data = [
                'total_posts'     =>  $totalPosts,
                'posts'           =>  PostResource::collection($posts)
            ];
            return $this->successDataResponse('Posts found successfully.', $data, 200);
        } else {
            $data = [
                'total_posts'     =>  0,
                'posts'           =>  []
            ];
            return $this->successDataResponse('No posts found.', $data, 200);
        }
    }

    /*** Group Interest Post List */
    public function groupInterestPostList(Request $request)
    {
        $this->validate($request, [
            'offset'           => 'required|numeric',
            'watched_video_id' => 'nullable|exists:posts,id' // Watched video parameter
        ]);

        $authId = auth()->user()->id;

        // Initialize variables
        $watchedInterestId = null;
        $userInterestIds = UserInterest::where('user_id', $authId)->pluck('interest_id');
        $myPostIds = Post::where('user_id', $authId)->where('post_type', 'video')->pluck('id');
        $followingIds = Follow::where('follower_id', $authId)->where('status', 'accept')->pluck('following_id');
        $publicProfileIds = User::where('is_profile_private', '0')->where('user_type', 'user')->pluck('id');
        $userIds = $followingIds->merge($publicProfileIds)->unique()->toArray();
        $posts = collect();

        $interestPostIds = InterestVideo::where('user_id', $authId)
            ->where('status', 'interest')
            ->pluck('post_id');
        $interestVideos = Post::whereIn('id', $interestPostIds)->pluck('interest_id');

        $notInterestPostIds = InterestVideo::where('user_id', $authId)
            ->where('status', 'not_interest')
            ->pluck('post_id');
        $notInterestVideos = Post::whereIn('id', $notInterestPostIds)->pluck('interest_id');

        // If watched_video_id is provided
        if ($request->has('watched_video_id')) {
            $watchedPost = Post::with('user', 'attachment')
                ->withCount('comments', 'likes', 'post_view')
                ->where('id', $request->watched_video_id)
                ->where('is_block', '0')
                ->where('post_type', 'video')
                ->first();

            if ($watchedPost) {
                $watchedInterestId = $watchedPost->interest_id;

                // Fetch other posts similar to the watched video
                $otherPosts = Post::with('user', 'attachment')
                    ->withCount('comments', 'likes', 'post_view')
                    ->latest()
                    ->where('is_block', '0')
                    ->where('post_type', 'video')
                    ->where('interest_id', $watchedInterestId)
                    ->whereNotIn('interest_id', $notInterestVideos)
                    ->where('id', '!=', $watchedPost->id) // Exclude watched video
                    ->whereNotIn('id', $myPostIds)
                    ->whereIn('user_id', $userIds)
                    ->skip($request->offset)
                    ->take(10)
                    ->get();

                // Combine watched video with other posts
                $posts = collect([$watchedPost])->merge($otherPosts);
            }
        }


        // Fetch interest videos if watched videos are less than 10
        if ($posts->count() < 10) {
            $remaining = 10 - $posts->count();

            $interestPosts = Post::with('user', 'attachment')
                ->withCount('comments', 'likes', 'post_view')
                ->latest()
                ->where('is_block', '0')
                ->where('post_type', 'video')
                ->whereNotIn('interest_id', $notInterestVideos)
                ->whereIn('interest_id', $interestVideos)
                ->whereNotIn('id', $posts->pluck('id')->toArray())
                ->whereNotIn('id', $myPostIds)
                ->whereIn('user_id', $userIds)
                ->skip($request->offset)
                ->take($remaining)
                ->get();

            $posts = $posts->merge($interestPosts);
        }

        // Fetch user profile posts if the total count is still less than 10
        if ($posts->count() < 10) {
            $remaining = 10 - $posts->count();

            $profilePosts = Post::with('user', 'attachment')
                ->withCount('comments', 'likes', 'post_view')
                ->latest()
                ->where('is_block', '0')
                ->where('post_type', 'video')
                ->whereNotIn('interest_id', $notInterestVideos)
                ->whereIn('interest_id', $userInterestIds)
                ->whereNotIn('id', $posts->pluck('id')->toArray())
                ->whereNotIn('id', $myPostIds)
                ->whereIn('user_id', $userIds)
                ->skip($request->offset)
                ->take($remaining)
                ->get();

            $posts = $posts->merge($profilePosts);
        }

        if ($posts->count() < 10) {
            $remaining = 10 - $posts->count();
            $publicPost = Post::with('user', 'attachment')
                ->withCount('comments', 'likes', 'post_view')
                ->latest()
                ->where('is_block', '0')
                ->where('post_type', 'video')
                ->whereNotIn('interest_id', $notInterestVideos)
                ->whereNotIn('id', $posts->pluck('id')->toArray())
                ->whereNotIn('id', $myPostIds)
                ->whereIn('user_id', $publicProfileIds)
                ->skip($request->offset)
                ->take($remaining)
                ->get();

            $posts = $posts->merge($publicPost);
        }

        $totalPosts = $posts->count();

        // Prepare response
        if ($totalPosts > 0) {
            $data = [
                'total_posts' => $totalPosts,
                'posts'       => PostResource::collection($posts)
            ];
            return $this->successDataResponse('Posts found successfully.', $data, 200);
        } else {
            $data = [
                'total_posts' => 0,
                'posts'       => []
            ];
            return $this->successDataResponse('No posts found.', $data, 200);
        }
    }


    // groupInterestPost
    public function groupInterestPost(Request $request)
    {
        $this->validate($request, [
            'status'    =>   'required|in:interest,not_interest',
            'post_id' => 'required|exists:posts,id'
        ]);
        $authId = auth()->user()->id;

        try {
            $interestVideo = InterestVideo::updateOrCreate(
                [
                    'user_id' => $authId,
                    'post_id' => $request->post_id
                ],
                [
                    'status' => $request->status
                ]
            );

            $message = $interestVideo->wasRecentlyCreated
                ? 'Interest status created successfully.'
                : 'Interest status updated successfully.';
            return $this->successDataResponse($message, $interestVideo, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /** Detail post */
    public function detailPost(Request $request)
    {
        $this->validate($request, [
            'post_id'          =>      'required|exists:posts,id'
        ]);

        try {
            $posts = Post::whereId($request->post_id)->with('user')->withCount('comments', 'likes', 'post_view')->first();

            if ($posts->user_id != auth()->id()) {
                $isPostViewed = PostView::where(['user_id' => auth()->id(), 'post_id' => $request->post_id])->exists();
                if (!$isPostViewed) {
                    PostView::create(['user_id' => auth()->id(), 'post_id' => $request->post_id]);
                }
            }
            $data = new PostResource($posts);
            return $this->successDataResponse('Post found successfully.', $data, 200);
        } catch (\Exception $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Search post */
    public function searchPost(Request $request)
    {
        $this->validate($request, [
            'offset'         =>   'required|numeric',
            'search_key'     =>   'required',
            'search_type'     =>   'required|in:post,user',
        ]);
        // 
        // Get reported posts
        $reportedPost = Report::where('user_id', auth()->id())->pluck('post_id');

        if ($request->search_type == "post") {
            $posts = Post::with('user')->withCount('comments', 'likes', 'post_view')->latest()
                ->where('title', 'like', '%' . $request->search_key . '%')
                ->whereNotIn('id', $reportedPost)
                ->where('is_block', '0')
                ->where('is_group_post', '0');
            $totalPosts = $posts->count();

            $posts = $posts->skip($request->offset)->take(10)->get();

            if (count($posts) > 0) {
                $data = [
                    'total_posts'     =>  $totalPosts,
                    'posts'           =>  PostResource::collection($posts)
                ];
                return $this->successDataResponse('Posts found successfully.', $data, 200);
            } else {
                $data = [
                    'total_posts'     =>  0,
                    'posts'           =>  []
                ];
                return $this->successDataResponse('No posts found.', $data, 200);
            }
        } else {

            // $users = User::where('full_name', 'like', '%' . $request->search_key . '%')->where('is_profile_complete', '1')
            //     ->select('id', 'full_name', 'user_name', 'email', 'profile_image', 'cover_image', 'phone_number')
            //     ->skip($request->offset)->take(10)->get();

            $users = User::where(function ($query) use ($request) {
                $query->where('full_name', 'like', '%' . $request->search_key . '%')
                    ->orWhere('user_name', 'like', '%' . $request->search_key . '%');
            })
                ->where('is_profile_complete', '1')
                ->select('id', 'full_name', 'user_name', 'email', 'profile_image', 'cover_image', 'phone_number')
                ->skip($request->offset)
                ->take(10)
                ->get();

            if (count($users) > 0) {
                return $this->successDataResponse('Users found successfully.', $users, 200);
            } else {

                return $this->successDataResponse('No users found.', $users, 200);
            }
        }
    }

    /** post view */
    // public function postView(Request $request)
    // {
    //     $this->validate($request, [
    //         'post_id'                   =>  'required|exists:posts,id'
    //     ]);

    //     $post_data = $request->only('post_id', 'description') + ['user_id' => auth()->id()];
    //     PostView::create($post_data);

    //     DB::commit();
    //     return $this->successResponse('Post has been viewed successfully.');
    // }

    /** Create post */
    public function createPost(Request $request)
    {

        $this->validate($request, [
            'interest_id'       =>  'required|exists:interests,id',
            'title'             =>  'required',
            'post_type'         =>  'required|in:thoughts,photo,video',
            'media.*'             =>  'required_if:post_type,==,photo|required_if:post_type,==,video',
            'group_id'          =>  'nullable|exists:groups,id'
        ]);

        try {
            DB::beginTransaction();

            $media_path = null;
            $media_type = null;
            $media_thumbnail = null;

            $post_data = $request->only('title', 'post_type', 'interest_id') +
                [
                    'media'           => $media_path,
                    'media_type'      => $media_type,
                    'media_thumbnail' => $media_thumbnail,
                    'user_id'         => auth()->id(),
                    'group_id'        => $request->group_id ?? null,
                    'is_group_post'   => $request->has('group_id') ? '1' : '0'
                ];

            $post = Post::create($post_data);


            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $key => $mediaFile) {

                    $media_type = explode('/', $mediaFile->getClientMimeType())[0];
                    $media = strtotime("now") . mt_rand(100000, 900000) . '.' . $mediaFile->getClientOriginalExtension();
                    $mediaFile->move(public_path('/media/post_media'), $media);
                    $media_path = '/media/post_media/' . $media;

                    if ($request->post_type == "video") {
                        $media_thumb_image = mt_rand() . time() . ".png";
                        $thumbnail = getcwd() . "/media/thumb/" . $media_thumb_image;
                        $cmd = sprintf('ffmpeg -ss 00:00:02 -i ' . getcwd() . $media_path . ' -frames:v 1 ' . $thumbnail);
                        exec($cmd);
                        $media_thumbnail = "/media/thumb/" . $media_thumb_image;
                    }

                    // If thumbnail not created
                    // if (!file_exists(asset($media_thumbnail)) && $request->post_type == "video") {
                    //     $media_thumbnail = '/media/thumb/default-thumb.jpg';
                    // }

                    // Prepare the data for the PostAttachment model
                    $attachmentData = [
                        'media' => $media_path,
                        'media_thumbnail' => $media_thumbnail,
                        'media_type' => $media_type,
                        'post_id' => $post->id
                    ];

                    // Create a new PostAttachment record
                    PostAttachment::create($attachmentData);
                }
            }

            // notification
            $authUser = auth()->user();
            $follower_ids = Follow::where('following_id', $authUser->id)->where('status', 'accept')->pluck('follower_id');
            $users = User::whereIn('id', $follower_ids)->get(['id', 'device_token', 'push_notification']);

            foreach ($users as $user) {
                // Notification
                $notification = [
                    'device_token'  =>   $user->device_token,
                    'sender_id'     =>   auth()->id(),
                    'receiver_id'   =>   $user->id,
                    'description'   =>   $authUser->full_name  . ' created a post.',
                    'title'         =>   'Created Post',
                    'record_id'     =>   $post->id,
                    'type'          =>   'create_post',
                    'created_at'    =>   now(),
                    'updated_at'    =>   now()
                ];
                if ($user->device_token != null && $user->push_notification == '1') {
                    push_notification($notification);
                }
                in_app_notification($notification);
            }

            DB::commit();
            return $this->successResponse('Post has been created successfully.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Edit post */
    public function editPost(Request $request)
    {
        $this->validate($request, [
            'post_id'               =>  'required|exists:posts,id',
            'title'                 =>  Rule::requiredIf($request->has('title')),
            'attachment_deleted_ids' => 'array'
        ]);

        try {
            DB::beginTransaction();

            $post = Post::whereId($request->post_id)->first();

            $post_data = $request->only('title', 'post_type');
            $updated = Post::whereId($request->post_id)->update($post_data);


            if ($request->has('attachment_deleted_ids') && count($request->attachment_deleted_ids) > 0) {
                $deleteAttachment = $this->deleteAttachment($request->attachment_deleted_ids);
                if ($deleteAttachment != 1) {
                    return $this->errorResponse($deleteAttachment, 400);
                }
            }
            $media_path = $post->media;
            $media_type = $post->media_type;
            $media_thumbnail = $post->media_thumbnail;

            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $key => $mediaFile) {

                    $media_type = explode('/', $mediaFile->getClientMimeType())[0];
                    $media = strtotime("now") . mt_rand(100000, 900000) . '.' . $mediaFile->getClientOriginalExtension();
                    $mediaFile->move(public_path('/media/post_media'), $media);
                    $media_path = '/media/post_media/' . $media;

                    if ($request->post_type == "video") {
                        $media_thumb_image = mt_rand() . time() . ".png";
                        $thumbnail = getcwd() . "/media/thumb/" . $media_thumb_image;
                        $cmd = sprintf('ffmpeg -ss 00:00:02 -i ' . getcwd() . $media_path . ' -frames:v 1 ' . $thumbnail);
                        exec($cmd);
                        $media_thumbnail = "/media/thumb/" . $media_thumb_image;
                    }

                    // // If thumbnail not created
                    // if (!file_exists(asset($media_thumbnail)) && $request->post_type == "video") {
                    //     $media_thumbnail = '/media/thumb/default-thumb.jpg';
                    // }

                    // Prepare the data for the PostAttachment model
                    $attachmentData = [
                        'media' => $media_path,
                        'media_thumbnail' => $media_thumbnail,
                        'media_type' => $media_type,
                        'post_id' => $post->id
                    ];

                    // Create a new PostAttachment record
                    PostAttachment::create($attachmentData);
                }
            }

            DB::commit();
            return $this->successResponse('Post has been updated successfully.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    private function deleteAttachment($deleted_ids)
    {
        try {
            DB::beginTransaction();
            $attachments = PostAttachment::whereIn('id', $deleted_ids)->get();

            if (count($attachments) > 0) {
                foreach ($attachments as $attachment) {
                    if ($attachment->media_type == 'video' && $attachment->media_thumbnail != null) {
                        if (file_exists(public_path($attachment->media_thumbnail))) {
                            unlink(public_path($attachment->media_thumbnail));
                        }
                    }
                    if (file_exists(public_path($attachment->media))) {
                        unlink(public_path($attachment->media));
                    }
                    $attachment->delete();
                }
            }

            DB::commit();
            return 1;
        } catch (\Exception $exception) {
            DB::rollBack();
            return $exception->getMessage();
        }
    }


    /** Delete post */
    public function deletePost(Request $request)
    {
        $this->validate($request, [
            'post_id'         =>    'required|exists:posts,id'
        ]);

        $post = Post::whereId($request->post_id)->where(['user_id' => auth()->id()])->first();
        try {
            DB::beginTransaction();
            if (file_exists(asset($post->media))) {
                unlink(asset($post->media));
            }

            if (file_exists(asset($post->media_thumbnail))) {
                unlink(asset($post->media_thumbnail));
            }
            $post->delete();

            DB::commit();
            return $this->successResponse('Post has been deleted successfully.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Share post */
    public function sharepost(Request $request)
    {
        $this->validate($request, [
            'post_id'         =>    'required|exists:posts,id'
        ]);

        $post = Post::whereId($request->post_id)->first();
        try {
            DB::beginTransaction();

            $newPost = $post->replicate();
            $newPost->user_id = auth()->id();
            $newPost->is_share = '1';
            $newPost->parent_id = $request->post_id;
            $newPost->created_at = now();
            $newPost->updated_at = now();
            $newPost->save();

            // Notification 
            $user = User::where('id', $post->user_id)->first(['id', 'device_token', 'push_notification']);
            if ($user->id != auth()->id()) {
                // Notification
                $notification = [
                    'device_token'  =>   $user->device_token,
                    'sender_id'     =>   auth()->id(),
                    'receiver_id'   =>   $user->id,
                    'description'   =>   auth()->user()->full_name  . ' has shared your post.',
                    'title'         =>   'Share Post',
                    'record_id'     =>   $newPost->id,
                    'type'          =>   'share_post',
                    'created_at'    =>   now(),
                    'updated_at'    =>   now()
                ];
                if ($user->device_token != null && $user->push_notification == '1') {
                    push_notification($notification);
                }
                in_app_notification($notification);
            }

            DB::commit();
            return $this->successResponse('Post has been shared successfully.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Post comment list  */
    public function postCommentList(Request $request)
    {
        $this->validate($request, [
            'post_id'     =>    'required|exists:posts,id',
            'offset'      =>    'required|numeric'
        ]);

        $comments = Comment::where('post_id', $request->post_id)->where('parent_id', null)->with('child_comments.user.status', 'user.status')
            ->offset($request->offset)->limit(50)->latest()->get();

        return $this->successDataResponse('Comments list.', CommentResource::collection($comments));
    }

    /** Post create comment  */
    public function postCreateComment(Request $request)
    {
        $this->validate($request, [
            'post_id'     =>    'required|exists:posts,id',
            'parent_id'   =>    'nullable|exists:comments,id',
            'comment'     =>    'required',
        ]);

        try {
            $data = $request->only('post_id', 'comment', 'parent_id') + ['user_id' => auth()->id()];
            $created = Comment::create($data);

            $post = Post::whereId($request->post_id)->with('user')->first();
            if ($post->user_id != auth()->id()) {

                // Notification
                $notification = [
                    'device_token'  =>   $post->user->device_token,
                    'sender_id'     =>   auth()->id(),
                    'receiver_id'   =>   $post->user->id,
                    'description'   =>   auth()->user()->full_name . ' comment on your post.',
                    'title'         =>   $request->comment,
                    'record_id'     =>   $request->post_id,
                    'type'          =>   'post_comment',
                    'created_at'    =>   now(),
                    'updated_at'    =>   now()
                ];
                if ($post->user->device_token != null && $post->user->post_comment_notification == '1') {
                    push_notification($notification);
                }
                in_app_notification($notification);
            }

            $comment = Comment::whereId($created->id)->with('child_comments.user.status', 'user.status')->first();
            return $this->successDataResponse('Comment has been created successfully.', new CommentResource($comment));
        } catch (\Exception $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Post update comment  */
    public function postUpdateComment(Request $request)
    {
        $this->validate($request, [
            'comment_id'     =>    'required|exists:comments,id',
            'comment'        =>    'required',
        ]);

        try {
            $data = $request->only('comment');
            Comment::whereId($request->comment_id)->update($data);
            return $this->successResponse('Comment has been updated successfully.');
        } catch (\Exception $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Post delete comment  */
    public function postDeleteComment(Request $request)
    {
        $this->validate($request, [
            'comment_id'     =>    'required|exists:comments,id',
        ]);

        try {
            Comment::whereId($request->comment_id)->delete();
            return $this->successResponse('Comment has been deleted successfully.');
        } catch (\Exception $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Post and comment like list  */
    public function postAndCommentLikeList(Request $request)
    {
        $this->validate($request, [
            'type'        =>    'required|in:post,comment',
            'post_id'     =>    'required_if:type,==,post|exists:posts,id',
            'comment_id'  =>    'required_if:type,==,comment|exists:comments,id',
            'offset'      =>    'required|numeric'
        ]);

        $type = $request->type;

        if ($type == 'post') {
            $likes = Like::where('record_id', $request->post_id)->where('like_type', 'post')->with('user.status')
                ->offset($request->offset)->limit(50)->latest()->get();
        } else {
            $likes = Like::where('record_id', $request->comment_id)->where('like_type', 'comment')->with('user.status')
                ->offset($request->offset)->limit(50)->latest()->get();
        }


        return $this->successDataResponse('Likes list.', LikeResource::collection($likes));
    }

    /** Post create like unlike  */
    public function postAndCommentLikeUnlike(Request $request)
    {
        $this->validate($request, [
            'record_id'        =>    'required',
            'reaction_type'    =>    'required|in:good,top,high',
            'like_type'        =>    'required|in:post,comment'
        ]);

        try {
            $like_type = $request->like_type;

            if ($like_type == 'post') {
                $hasPost = Post::whereId($request->record_id)->with('user')->first();
                if (empty($hasPost)) {
                    return $this->errorResponse('Post not found.', 400);
                }
                $user = $hasPost->user;
                $title = $hasPost->title;
            } else if ($like_type == 'comment') {
                $hasComment = Comment::whereId($request->record_id)->with('user')->first();
                if (empty($hasComment)) {
                    return $this->errorResponse('Comment not found.', 400);
                }
                $user = $hasComment->user;
                $title = $hasComment->comment;
            }

            $isLiked = Like::where(['user_id' => auth()->id(), 'record_id' => $request->record_id, 'like_type' => $request->like_type])->first();

            if (!empty($isLiked)) {
                $isLiked->delete();
                $message = ucfirst($like_type) . ' has been unliked successfully.';
            } else {
                $data = $request->only('record_id', 'reaction_type', 'like_type') + ['user_id' => auth()->id()];
                Like::create($data);
                $message = ucfirst($like_type) . ' has been liked successfully.';

                if ($user->id != auth()->id()) {
                    // Notification
                    $notification = [
                        'device_token'  =>   $user->device_token,
                        'sender_id'     =>   auth()->id(),
                        'receiver_id'   =>   $user->id,
                        'description'   =>   auth()->user()->full_name . ' like your ' . $like_type,
                        'title'         =>   $title,
                        'record_id'     =>   $request->record_id,
                        'type'          =>   $like_type . '_like',
                        'created_at'    =>   now(),
                        'updated_at'    =>   now()
                    ];
                    if ($like_type == 'comment') {
                        if ($user->device_token != null && $user->post_comment_notification == '1') {
                            push_notification($notification);
                        }
                    } else {
                        if ($user->device_token != null && $user->push_notification == '1') {
                            push_notification($notification);
                        }
                    }
                    in_app_notification($notification);
                }
            }

            $reaction_types = Like::where(['record_id' => $request->record_id, 'like_type' => $like_type])->groupBy('record_id', 'reaction_type')->pluck('reaction_type');

            return $this->successDataResponse($message, ['reaction_types' => $reaction_types]);
        } catch (\Exception $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Save lists post */
    public function saveListPost(Request $request)
    {
        try {
            $savedPost = SavePost::where('user_id', auth()->id())->pluck('post_id');

            if (count($savedPost) == 0) {
                return $this->errorResponse('No posts have been saved.', 400);
            }

            $posts = Post::with('user')->withCount('comments', 'likes', 'post_view')->whereIn('id', $savedPost)->latest()->get();

            $data = [
                'total_posts'     =>  count($posts),
                'posts'           =>  PostResource::collection($posts)
            ];
            return $this->successDataResponse('Posts found successfully.', $data, 200);
        } catch (\Exception $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Save post */
    public function savePost(Request $request)
    {
        $this->validate($request, [
            'post_id'     =>    'required|exists:posts,id',
        ]);

        try {
            DB::beginTransaction();

            $data = $request->only('post_id') + ['user_id' => auth()->id()];
            $exists = SavePost::where($data)->first();

            if (!empty($exists)) {
                $exists->delete();

                DB::commit();
                return $this->successResponse('Post has been removed from your saved posts.');
            }

            SavePost::create([
                'user_id' => auth()->id(),
                'post_id' => $request->post_id,
            ]);

            $post = Post::whereId($request->post_id)->first(['id', 'user_id']);
            $user = User::where('id', $post->user_id)->first(['id', 'device_token', 'push_notification']);

            if ($user->id != auth()->id()) {
                $notification = [
                    'device_token'  =>   $user->device_token,
                    'sender_id'     =>   auth()->id(),
                    'receiver_id'   =>   $user->id,
                    'description'   =>   auth()->user()->full_name  . ' has saved your post.',
                    'title'         =>   'Save Post',
                    'record_id'     =>   $request->post_id,
                    'type'          =>   'save_post',
                    'created_at'    =>   now(),
                    'updated_at'    =>   now()
                ];
                if ($user->device_token != null && $user->push_notification == '1') {
                    push_notification($notification);
                }
                in_app_notification($notification);
            }

            DB::commit();
            return $this->successResponse('Post has been saved.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Favourite lists post */
    public function favouriteListPost(Request $request)
    {
        try {
            $favouritePost = Favorite::where('user_id', auth()->id())->pluck('post_id');

            if (count($favouritePost) == 0) {
                return $this->errorResponse('No posts have been favourite.', 400);
            }

            $posts = Post::with('user')->withCount('comments', 'likes', 'post_view')->whereIn('id', $favouritePost)->latest()->get();

            $data = [
                'total_posts'     =>  count($posts),
                'posts'           =>  PostResource::collection($posts)
            ];
            return $this->successDataResponse('Posts found successfully.', $data, 200);
        } catch (\Exception $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Favourite post */
    public function favouritePost(Request $request)
    {
        $this->validate($request, [
            'post_id'     =>    'required|exists:posts,id',
        ]);

        try {
            DB::beginTransaction();

            $data = $request->only('post_id') + ['user_id' => auth()->id()];
            $exists = Favorite::where($data)->first();

            if (!empty($exists)) {
                $exists->delete();

                DB::commit();
                return $this->successResponse('Post has been removed from your favourite posts.');
            }

            Favorite::create($data);

            $post = Post::whereId($request->post_id)->first(['id', 'user_id']);
            $user = User::where('id', $post->user_id)->first(['id', 'device_token', 'push_notification']);

            if ($user->id != auth()->id()) {
                $notification = [
                    'device_token'  =>   $user->device_token,
                    'sender_id'     =>   auth()->id(),
                    'receiver_id'   =>   $user->id,
                    'description'   =>   auth()->user()->full_name  . ' has favourited your post.',
                    'title'         =>   'Favourite Post',
                    'record_id'     =>   $request->post_id,
                    'type'          =>   'favourite_post',
                    'created_at'    =>   now(),
                    'updated_at'    =>   now()
                ];
                if ($user->device_token != null && $user->push_notification == '1') {
                    push_notification($notification);
                }
                in_app_notification($notification);
            }
            DB::commit();
            return $this->successResponse('Post has been added to favourite.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Report post */
    public function reportPost(Request $request)
    {
        $this->validate($request, [
            'post_id'     =>    'required|exists:posts,id',
        ]);

        try {
            DB::beginTransaction();

            $data = $request->only('post_id') + ['user_id' => auth()->id()];
            $exists = Report::where($data)->first();

            if (!empty($exists)) {
                DB::commit();
                return $this->errorResponse('You already reported this post.', 400);
            }

            Report::create($data);

            $post = Post::whereId($request->post_id)->first(['id', 'user_id']);
            // Notification 
            $user = User::where('id', $post->user_id)->first(['id', 'device_token', 'push_notification']);
            // Notification
            $notification = [
                'device_token'  =>   $user->device_token,
                'sender_id'     =>   auth()->id(),
                'receiver_id'   =>   $user->id,
                'description'   =>   auth()->user()->full_name  . ' has reported your post.',
                'title'         =>   'Report Post',
                'record_id'     =>   $request->post_id,
                'type'          =>   'report_post',
                'created_at'    =>   now(),
                'updated_at'    =>   now()
            ];
            if ($user->device_token != null && $user->push_notification == '1') {
                push_notification($notification);
            }
            in_app_notification($notification);


            DB::commit();
            return $this->successResponse('To verify when a user report a post, post should not disappear until admin block that post show a toast message when user report a post "Your post report request has been sent to admin.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /*
    |--------------------------------------------------------------------------
    |       GROUP MODULE
    |--------------------------------------------------------------------------
    */

    /** Group list */
    public function groupList(Request $request)
    {
        $this->validate($request, [
            'type'     =>     'required|in:my,all'
        ]);

        $authId = auth()->user()->id;
        try {

            if ($request->type == 'my') {
                // Get the group IDs where the user is a member
                $groupMemberIds = GroupMember::where('user_id', $authId)->pluck('group_id')->toArray();
                // Get the user's created groups
                $userCreatedGroups = Group::with('created_by', 'interest.group_interest')
                    ->where('created_by_id', $authId)
                    ->get();

                // Get the groups where the user is a member but not the creator
                $memberGroups = Group::with('created_by', 'interest.group_interest')
                    ->whereIn('id', $groupMemberIds)
                    ->where('created_by_id', '!=', $authId)
                    ->get();

                // Combine the user's created groups and member groups
                $group = $userCreatedGroups->merge($memberGroups);
            } else {

                // Get the interest IDs of the authenticated user
                $userInterestIds = UserInterest::where('user_id', $authId)->pluck('interest_id');

                $groupIds = GroupMember::where('user_id', $authId)->pluck('group_id'); // Already have in this group

                // Get groups with matching interests
                $matchingGroupIds = Group::with(['interest' => function ($query) use ($userInterestIds) {
                    $query->whereIn('interest_id', $userInterestIds);
                }])->whereHas('interest', function ($query) use ($userInterestIds) {
                    $query->whereIn('interest_id', $userInterestIds);
                })->pluck('id');
                // ->where('group_type', 'public')

                $group = Group::latest()
                    ->with('created_by', 'interest.group_interest')
                    ->whereIn('id', $matchingGroupIds)
                    ->whereNotIn('id', $groupIds)
                    ->get();
            }

            if (count($group) > 0) {
                $data =  GroupResource::collection($group);
                return $this->successDataResponse('Group list found successfully.', $data);
            } else {
                return $this->errorResponse('Group list not found.', 400);
            }
        } catch (\Exception $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Group detail */
    public function groupDetail(Request $request)
    {
        $this->validate($request, [
            // 'group_id'                 =>        'required|exists:groups,id'
            'group_id'                 =>        'required'

        ]);

        $notExist = Group::where('id', $request->group_id)->first();
        if (!$notExist) {

            return $this->errorDataResponse("Group has been deleted", 'deleted', 400);
        }

        $group = Group::whereId($request->group_id)->with('created_by', 'members.user')->first();

        try {
            $data =  new GroupResource($group);
            return $this->successDataResponse('Group detail.', $data);
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Group create */
    public function groupCreate(Request $request)
    {
        $this->validate($request, [
            'image'             => 'mimes:jpeg,png,jpg',
            'title'             => 'required|max:255',
            'description'       => 'nullable',
            'group_type'        => 'required|in:public,private',
            'group_member_ids'  => 'nullable|array|exists:users,id',
            'interest_id' => 'required|array'
        ]);



        try {
            DB::beginTransaction();

            $auth = auth()->user();
            $data = $request->only('title', 'description', 'group_type') + ['created_by_id' => $auth->id];

            if ($request->hasFile('image')) {
                $image = strtotime("now") . mt_rand(100000, 900000) . '.' . $request->image->getClientOriginalExtension();
                $request->image->move(public_path('/media/group_image'), $image);
                $file_path = '/media/group_image/' . $image;
                $data['image'] = $file_path;
            }

            // Create Group
            $created = Group::create($data);

            // Add creator into Group as ADMIN
            GroupMember::create([
                'group_id'  => $created->id,
                'user_id'   => $auth->id,
                'role'      => GroupMemberRole::ADMIN->value
            ]);

            // w
            if (isset($request->interest_id) && count($request->interest_id) > 0) {

                foreach ($request->interest_id as $interest_id) {
                    GroupInterest::create([
                        'group_id'     =>   $created->id,
                        'interest_id' =>   $interest_id,
                    ]);
                }
            }

            // Add members into Group as MEMBER
            if ($request->has('group_member_ids') && is_array($request->group_member_ids) && count($request->group_member_ids)) {
                $user_ids = $request->group_member_ids;

                foreach ($user_ids as $user_id) {
                    GroupMember::create([
                        'group_id'  => $created->id,
                        'user_id'   => $user_id,
                        'role'      => GroupMemberRole::MEMBER->value
                    ]);

                    $user = User::whereId($user_id)->first();

                    // Notification
                    $notification = [
                        'device_token'  => $user->device_token,
                        'sender_id'     => $auth->id,
                        'receiver_id'   => $user->id,
                        'description'   => $auth->full_name . ' added you in a Group.',
                        'title'         => $created->title,
                        'record_id'     => $created->id,
                        'type'          => 'group_invite',
                        'created_at'    => now(),
                        'updated_at'    => now()
                    ];
                    if ($user->device_token != null) {
                        push_notification($notification);
                    }
                    in_app_notification($notification);
                }
            }

            DB::commit();
            return $this->successResponse('Group has been created successfully.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Group update */
    public function groupUpdate(Request $request)
    {
        $this->validate($request, [
            'group_id'                 =>        'required|exists:groups,id',
            'image'                    =>        'mimes:jpeg,png,jpg',
            'title'                    =>        'required|max:255',
            'group_member_ids'         =>        'nullable|array|exists:users,id',
            'delete_group_member_ids'  =>        'nullable|array|exists:users,id',
            'interest_id' => 'required|array'
        ]);

        try {
            DB::beginTransaction();

            $auth = auth()->user();
            $data = $request->only('title', 'description', 'group_type');

            if ($request->hasFile('image')) {
                $image = strtotime("now") . mt_rand(100000, 900000) . '.' . $request->image->getClientOriginalExtension();
                $request->image->move(public_path('/media/group_image'), $image);
                $file_path = '/media/group_image/' . $image;
                $data['image'] = $file_path;
            }

            // Update Group
            Group::whereId($request->group_id)->update($data);


            // w
            if (isset($request->interest_id) && count($request->interest_id) > 0) {
                GroupInterest::where('group_id', $request->group_id)->delete();

                foreach ($request->interest_id as $interest_id) {
                    GroupInterest::create([
                        'group_id'     =>   $request->group_id,
                        'interest_id' =>   $interest_id,
                    ]);
                }
            }

            // Add members into Group as MEMBER
            if (isset($request->group_member_ids) && count($request->group_member_ids)) {
                $group = Group::whereId($request->group_id)->first();
                $user_ids  = $request->group_member_ids;

                foreach ($user_ids as $user_id) {
                    GroupMember::create([
                        'group_id'  =>    $group->id,
                        'user_id'   =>    $user_id,
                        'role'      =>    GroupMemberRole::MEMBER->value
                    ]);

                    $user = User::whereId($user_id)->first();

                    // Notification
                    $notification = [
                        'device_token'  =>   $user->device_token,
                        'sender_id'     =>   $auth->id,
                        'receiver_id'   =>   $user->id,
                        'description'   =>   $auth->full_name . ' added you in a Group.',
                        'title'         =>   $group->title,
                        'record_id'     =>   $group->id,
                        'type'          =>   'group_invite',
                        'created_at'    =>   now(),
                        'updated_at'    =>   now()
                    ];
                    if ($user->device_token != null) {
                        push_notification($notification);
                    }
                    in_app_notification($notification);
                }
            }

            if (isset($request->delete_group_member_ids) && count($request->delete_group_member_ids)) {
                GroupMember::where('group_id', $request->group_id)->whereIn('user_id', $request->delete_group_member_ids)->delete();
            }

            DB::commit();
            return $this->successResponse('Group has been updated successfully.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Group delete */
    public function groupDelete(Request $request)
    {
        $this->validate($request, [
            'group_id'                 =>        'required|exists:groups,id'
        ]);

        try {
            DB::beginTransaction();

            Group::whereId($request->group_id)->delete();
            GroupMember::where('group_id', $request->group_id)->delete();
            Notification::where(['type' => 'group_invite', 'record_id' => $request->group_id])->delete();

            DB::commit();
            return $this->successResponse('Group has been deleted successfully.');
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Group leave */
    public function groupLeave(Request $request)
    {
        $this->validate($request, [
            'group_id'                 =>        'required'
        ]);

        try {
            DB::beginTransaction();
            $auth = auth()->user();

            $group = Group::whereId($request->group_id)->first();
            if (!$group) {
                return $this->errorDataResponse("Group has been deleted", 'deleted', 400);
            }
            if ($auth->id != $group->created_by_id) {
                GroupMember::where(['group_id' => $request->group_id, 'user_id' => $auth->id])->delete();
                Notification::where(['type' => 'group_invite', 'record_id' => $request->group_id, 'receiver_id' => $auth->id])->delete();

                DB::commit();
                return $this->successResponse('Group has been leaved successfully.');
            } else {
                return $this->errorResponse('Group admin can not be leave group.', 400);
            }
        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Group Search */
    public function searchGroup(Request $request)
    {

        $this->validate($request, [
            'group_title'     =>   'required',
        ]);

        $group = Group::latest()
            ->with('created_by', 'members.user')
            ->where('title', 'like', '%' . $request->group_title . '%')
            ->get();

        if (count($group) > 0) {
            $data =  GroupResource::collection($group);
            return $this->successDataResponse('Groups found successfully.', $data, 200);
        } else {
            return $this->successDataResponse('No groups found.', $group, 200);
        }
    }

    // Join Group 
    public function groupJoin(Request $request)
    {
        $this->validate($request, [
            'group_id' => 'required',
        ]);

        $authId = auth()->user()->id;

        $group = Group::whereId($request->group_id)->first();

        if (!$group) {
            return $this->errorDataResponse("Group has been deleted", 'deleted', 400);
        }
        try {
            if ($group->group_type == "public") {

                $checkAleardyMember =  GroupMember::where('group_id', $request->group_id)->where('user_id', $authId)->first();

                if ($checkAleardyMember) {
                    return $this->errorResponse('You are already a member of this group.', 400);
                }

                GroupMember::create([
                    'group_id'  =>    $request->group_id,
                    'user_id'   =>    $authId,
                    'role'      =>    GroupMemberRole::MEMBER->value
                ]);

                $user = User::where('id', $group->created_by_id)->first(['id', 'device_token', 'push_notification']);

                if ($user->id != auth()->id()) {
                    $notification = [
                        'device_token'  =>   $user->device_token,
                        'sender_id'     =>   auth()->id(),
                        'receiver_id'   =>   $user->id,
                        'description'   =>   auth()->user()->full_name  . ' has joined your group.',
                        'title'         =>   'Joined Group',
                        'record_id'     =>   $request->group_id,
                        'type'          =>   'join_group',
                        'created_at'    =>   now(),
                        'updated_at'    =>   now()
                    ];
                    if ($user->device_token != null && $user->push_notification == '1') {
                        push_notification($notification);
                    }
                    in_app_notification($notification);
                }

                return $this->successResponse('Group joined successfully.');
            } else {

                $checkAleardyMember =  GroupMember::where('group_id', $request->group_id)->where('user_id', $authId)->first();

                if ($checkAleardyMember) {
                    return $this->errorResponse('You are already a member of this group.', 400);
                }

                GroupRequest::where('group_id', $request->group_id)->where('user_id', $authId)->delete();

                GroupRequest::create([
                    'group_id'  =>    $request->group_id,
                    'user_id'   =>    $authId,
                ]);

                $user = User::where('id', $group->created_by_id)->first(['id', 'device_token', 'push_notification']);

                if ($user->id != auth()->id()) {
                    $notification = [
                        'device_token'  =>   $user->device_token,
                        'sender_id'     =>   auth()->id(),
                        'receiver_id'   =>   $user->id,
                        'description'   =>   auth()->user()->full_name  . ' has requested to join the group.',
                        'title'         =>   'Group Request',
                        'record_id'     =>   $request->group_id,
                        'type'          =>   'group_request',
                        'created_at'    =>   now(),
                        'updated_at'    =>   now()
                    ];
                    if ($user->device_token != null && $user->push_notification == '1') {
                        push_notification($notification);
                    }
                    in_app_notification($notification);
                }

                return $this->successResponse('Your group membership request has been sent to the group admin. You will be a member once approved.');
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    // Group Requested Users
    public function groupRequestedUser(Request $request)
    {
        $this->validate($request, [
            'group_id' => 'required|exists:groups,id',
        ]);

        $groupRequestedUser = GroupRequest::with('user')->where('group_id', $request->group_id)->where('status', 'pending')->get();

        if (count($groupRequestedUser) > 0) {
            return $this->successDataResponse('Group request users found successfully.', $groupRequestedUser, 200);
        } else {
            return $this->successDataResponse('Group request users not found.', $groupRequestedUser, 200);
        }
    }

    // Group Request Cancel
    public function groupCancelRequest(Request $request)
    {
        $this->validate($request, [
            'group_id' => 'required|exists:groups,id',
        ]);
        $authId = auth()->user()->id;

        try {

            GroupRequest::where('group_id', $request->group_id)->where('user_id', $authId)
                ->where('status', 'pending')
                ->update(['status' => 'cancel']);

            return $this->successResponse('Group request canceled successfully.', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    // Group Request Accepted
    public function groupAcceptRequest(Request $request)
    {
        $this->validate($request, [
            'group_request_id' => 'required|exists:group_requests,id',
        ]);

        $groupRequest = GroupRequest::whereId($request->group_request_id)->first();

        try {
            if ($groupRequest->status == "accept") {
                return $this->errorResponse('Group request already accepted.', 400);
            } else if ($groupRequest->status == "cancel") {
                return $this->errorResponse('Group request has been canceled.', 400);
            } else if ($groupRequest->status == "rejected") {
                return $this->errorResponse('Group request has been rejected.', 400);
            } else {
                $groupRequest->update(['status' => 'accept']);

                GroupMember::create([
                    'group_id' => $groupRequest->group_id,
                    'user_id' => $groupRequest->user_id,
                    'role' => GroupMemberRole::MEMBER->value
                ]);


                $user = User::where('id', $groupRequest->user_id)->first(['id', 'device_token', 'push_notification']);

                if ($user->id != auth()->id()) {
                    $notification = [
                        'device_token'  =>   $user->device_token,
                        'sender_id'     =>   auth()->id(),
                        'receiver_id'   =>   $user->id,
                        'description'   =>   auth()->user()->full_name  . ' has accepted your group request.',
                        'title'         =>   'Accept Group Request',
                        'record_id'     =>   $groupRequest->group_id,
                        'type'          =>   'accept_group_request',
                        'created_at'    =>   now(),
                        'updated_at'    =>   now()
                    ];
                    if ($user->device_token != null && $user->push_notification == '1') {
                        push_notification($notification);
                    }
                    in_app_notification($notification);
                }

                return $this->successResponse('Group request accepted successfully.', 200);
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    // Group Request Accept
    public function groupRejectRequest(Request $request)
    {
        $this->validate($request, [
            'group_request_id' => 'required|exists:group_requests,id',
        ]);

        try {
            GroupRequest::whereId($request->group_request_id)->where('status', 'pending')
                ->update(['status' => 'rejected']);
            return $this->successResponse('Group request rejected successfully.', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    // Group Add Member
    public function groupAddMember(Request $request)
    {
        $this->validate($request, [
            'group_id' => 'required|exists:groups,id',
            'user_id' => 'required|exists:users,id',
        ]);

        try {

            $checkAleardyMember =  GroupMember::where('group_id', $request->group_id)->where('user_id', $request->user_id)->first();

            if ($checkAleardyMember) {
                return $this->errorResponse('This user already a member of this group.', 400);
            }

            GroupRequest::where('group_id', $request->group_id)->where('user_id', $request->user_id)->delete();

            GroupMember::create([
                'group_id' => $request->group_id,
                'user_id' => $request->user_id,
                'role' => GroupMemberRole::MEMBER->value
            ]);

            $user = User::where('id', $request->user_id)->first(['id', 'device_token', 'push_notification']);

            if ($user->id != auth()->id()) {
                $notification = [
                    'device_token'  =>   $user->device_token,
                    'sender_id'     =>   auth()->id(),
                    'receiver_id'   =>   $user->id,
                    'description'   => auth()->user()->full_name . ' has added you to a group.',
                    'title'         =>   'Add Group Member',
                    'record_id'     =>   $request->group_id,
                    'type'          =>   'add_group_member',
                    'created_at'    =>   now(),
                    'updated_at'    =>   now()
                ];
                if ($user->device_token != null && $user->push_notification == '1') {
                    push_notification($notification);
                }
                in_app_notification($notification);
            }

            return $this->successResponse('Member added successfully.', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    // Group Members
    public function groupMember(Request $request)
    {
        $this->validate($request, [
            'offset'       =>   'required|numeric',
            'group_id' => 'required',
        ]);

        $notExist = Group::where('id', $request->group_id)->first();
        if (!$notExist) {
            return $this->errorDataResponse("Group has been deleted", 'deleted', 400);
        }
        $groupMember = GroupMember::latest()
            ->with('user')->where('group_id', $request->group_id)
            ->skip($request->offset)->take(10)
            ->get()->map(function ($groupMember) {
                return [
                    'id' => $groupMember->user->id,
                    'full_name' => $groupMember->user->full_name,
                    'user_name' => $groupMember->user->user_name,
                    'email' => $groupMember->user->email,
                    'profile_image' => $groupMember->user->profile_image,
                ];
            })->toArray();

        if (count($groupMember) > 0) {
            return $this->successDataResponse('Group users found successfully.', $groupMember, 200);
        } else {
            return $this->successDataResponse('No group users found.', $groupMember, 200);
        }
    }

    // Group Not Follow User
    public function groupNotFollowUser(Request $request)
    {
        $this->validate($request, [
            'offset'       =>   'required|numeric',
            'group_id' => 'required|exists:groups,id',
        ]);

        $authUser = auth()->user();
        $authId = $authUser->id;

        $follower_ids = Follow::where('following_id', $authId)->where('status', 'accept')->pluck('follower_id');
        $following_ids = Follow::where('follower_id', $authId)->where('status', 'accept')->pluck('following_id');

        $following_public_ids = Follow::whereHas('following_user', function ($query) {
            $query->where('is_profile_private', '0');
        })->where('following_id', $authId)->where('status', 'pending')->pluck('follower_id');

        $user_ids = $follower_ids->merge($following_ids)->merge($following_public_ids)->unique();

        $groupMember_ids = GroupMember::where('group_id', $request->group_id)->pluck('user_id');

        $users = User::whereIn('id', $user_ids)->whereNotIn('id', $groupMember_ids)->skip($request->offset)->take(10)
            ->get(['id', 'full_name', 'user_name', 'email', 'profile_image']);

        if (count($users) > 0) {
            return $this->successDataResponse('users found successfully.', $users, 200);
        } else {
            return $this->successDataResponse('No users found.', $users, 200);
        }
    }

    // Remove User From Group
    public function groupMemberRemove(Request $request)
    {
        $this->validate($request, [
            'group_id' => 'required|exists:groups,id',
            'user_id' => 'required|exists:users,id',
        ]);

        DB::beginTransaction();

        try {
            // Find the group member
            $groupMember = GroupMember::where('group_id', $request->group_id)
                ->where('user_id', $request->user_id)
                ->first();

            if ($groupMember) {
                // Delete the group member
                $groupMember->delete();

                // Delete group requests for the user in the group
                GroupRequest::where('group_id', $request->group_id)
                    ->where('user_id', $request->user_id)
                    ->delete();

                // Find the user's posts in the group
                $posts = Post::where('group_id', $request->group_id)
                    ->where('user_id', $request->user_id)
                    ->get();

                foreach ($posts as $post) {
                    $postAttachments = PostAttachment::where('post_id', $post->id)->get();

                    foreach ($postAttachments as $attachment) {
                        // Delete post media and thumbnails if they exist
                        if (!empty($attachment->media) && file_exists(public_path($attachment->media))) {
                            unlink(public_path($attachment->media));
                        }

                        if ($attachment->media_type == "video" && !empty($attachment->media_thumbnail) && file_exists(public_path($attachment->media_thumbnail))) {
                            unlink(public_path($attachment->media_thumbnail));
                        }

                        // Delete the post attachment
                        $attachment->delete();
                    }

                    // Delete the post
                    $post->delete();
                }

                DB::commit();

                return $this->successResponse('Remove user successfully.', 200);
            } else {
                DB::rollBack();
                return $this->errorResponse('Group member not found.', 400);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
