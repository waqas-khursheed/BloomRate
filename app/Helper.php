<?php

use App\Models\Notification;

function push_notification($push_arr){
    
     $apiKey = env('FIRE_BASE_API_KEY');

    // $apiKey = "AAAARtHAFkk:APA91bHPCwemOxQQYftfn2n5TPD505lmoh-HyiM6cw8dr1dmKREUm9j-C_YQdT1q5G0pi7miBEasQPIQclAQGnwOmSwITYnOk0Kj0pX40ZE_RIhyM-tG4PhdXqxd180Q-9skwKli7p8m";
    // $apiKey = "AAAA9m3GF5Q:APA91bGSIgZQx2QA5gI9eEo3WCloA-sw_96H8UYcyuZlA9uv3XJlBvhF9vDvw_DGkH56GVYVxSdqTKkOpaULhoWh93-xMe9tJ0-Lm3bAbUFbw8UeFgwsry0tjcpttO7YaD7YQaeOsQ65";
    
    $registrationIDs 	    = (array) $push_arr['device_token'];
    $message 			    = array(
        "body"         	    => $push_arr['description'],
        "title"        		=> $push_arr['title'],
        "notification_type" => $push_arr['type'],
        "other_id"          => $push_arr['record_id'],
        "date"        		=> now(),
        'vibrate'           => 1,
        'sound'             => 1,
    );
    $url = 'https://fcm.googleapis.com/fcm/send';

    // if($push_arr->user_device == "ios"){
    //     $fields = array(
    //         'registration_ids'     =>  $registrationIDs,
    //         'notification'         =>  $message,
    //         'data'         =>  $message
    //     );
    // }else if($push_arr->user_device == "android"){
        $fields = array(
            'registration_ids'     =>  $registrationIDs,
            'notification'         =>  $message,
            'data'         =>  $message
        );
    // }

    $headers = array(
        'Authorization: key='. $apiKey,
        'Content-Type: application/json'
    );
    $ch = curl_init();
    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_POST, true );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $fields ) );
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

function in_app_notification($data) {
    $notification = new Notification();
    $notification->sender_id = $data['sender_id'];
    $notification->receiver_id = $data['receiver_id'];
    $notification->title = $data['title'];
    $notification->description = $data['description'];
    $notification->record_id = $data['record_id'];
    $notification->type = $data['type'];
    $notification->save();
}
