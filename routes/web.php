<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use App\User;
use App\Carts;
use App\ProductOrders;
use App\SearchKeywords;
use App\Province;
use App\Municipalities;
use App\Users_Info;
use App\SellOnTaiga;
use App\SubmittedApplications;
use App\Settings;
use App\Ratings;
use App\Comments;
use Illuminate\Support\Facades\Input;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
//use Validator;

function getuid($uid){
  //fetch user id
  $user_id = "";
  $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
  foreach($user as $u){
    if(!empty($u->id)){
      $user_id = $u->id;
    }
  }
  //end of fetching user id

  return $user_id;
}

function couponrules($id,$userid){
    //id identifies what rules to follow
    //user id is used to check if coupon rule is followed
      $response = array('shipping'=>false,'total'=>false,'item'=>false,'error'=>'');
          //this rules discounts 10% of total and waived shipping fee within metro manila
      //10 % doesn't have rules
      //shipping fee rule is to be within metro manila to have shipping fee waived
      //get province of user using user id
      //fetch address to deliver the orders
      $userinfo = \App\Users_Info::select('unit_st_brgy','municipality_city_id','province_id')
      ->where('users_id','=',$userid)
      ->where('set_default','=',1)
      ->get();

      $provinceid = 0;

      foreach($userinfo as $ui){
        if(!empty($ui->province_id)){
          $provinceid = $ui->province_id;
        }
      }

      $sql = 'SELECT
      sum(t_variations.price * t_product_orders.quantity) as total_amount
      FROM t_carts
      JOIN t_product_orders ON t_product_orders.cart_id=t_carts.id
      JOIN t_variations ON t_variations.sku=t_product_orders.sku
      WHERE t_carts.users_id='.$userid.'
      AND t_carts.status=0
      AND t_product_orders.status=1';
      $subtotal = DB::select($sql);
    if($id==1){

      $response['total']    = ($subtotal[0]->total_amount >= 500) ? true : false;
      if($response['total']==true){
          $response['shipping'] = $provinceid==82 ? true : false;
      }

    }
    elseif($id==2){
      $response['shipping'] = true;
      $response['total']    = false;
    }
    elseif($id==3){
      $response['shipping'] = false;
      $response['total']    = true;
    }
    elseif($id==4){
        $response['shipping'] = $provinceid==82 ? true : false;
        $response['total'] = true;
      //sql if nagamit na ni user ung voucher code once
      //if true, return
      //$response['shipping'] = "false";
      //$response['total']    = "false";
    }
    elseif($id==5){ //min of 8k total purchase
      $response['shipping'] = false;
      $response['total']    = ($subtotal[0]->total_amount >= 8000) ? true : false;
    }
    elseif($id==6){
      $response['shipping'] = false;
      $response['total']    = ($subtotal[0]->total_amount >= 15000) ? true : false;
    }
    elseif($id==7){
      $response['shipping'] = false;
      $response['total']    = ($subtotal[0]->total_amount >= 3000) ? true : false;
    }
    elseif($id==8){
      $response['shipping'] = false;
      $response['total']    = ($subtotal[0]->total_amount >= 1000) ? true : false;
    }

     return $response;
}


function couponverifier($code, $uid) {
  $response = [];
  $select = DB::table('t_coupons')
            ->where('code', $code);
  $coupon_limit    = $select->pluck('coupon_limit')->first();
  $coupon_consumed = $select->pluck('consumed')->first();
  $coupon_status   = $select->pluck('status')->first();
  $date_start      = $select->pluck('date_start')->first();
  $date_end        = $select->pluck('date_end')->first();
  $rule_id         = $select->pluck('coupon_rule_id')->first();
  $user_id         = getuid($uid);

  if($code != null) {

      if( $select->first() ) {

        if( $coupon_consumed >= $coupon_limit ) {
          array_push($response, [
              'limit' => 'Coupon code reached the maximum limit.'
          ]);
        }

        if ( $coupon_status === 0 ) {
          array_push($response, [
              'status' => 'Coupon code is inactive.'
          ]);
        }

        if ( time() < strtotime($date_start) ) {
          array_push($response, [
              'error' => 'Coupon code is invalid.'
          ]);
        } else if ( time() > strtotime($date_end) ) {
          array_push($response, [
              'time' => 'Coupon code has expired.'
          ]);
        }

        if ( $response == null ) {
          //coupon rule id, userid
          //$rule_id
          $remaining = (int)$coupon_limit - (int)$coupon_consumed;
          $response = [
            'result'    => $select->first(),
            'rules'     => couponrules($rule_id, $user_id),
            'success'   => 'Coupon code verified!'
          ];
        }

      } else {
        array_push($response, [
            'error' => 'Coupon code is invalid.'
        ]);
      }

  } else {
      array_push($response, [
          'error' => "Coupon Code field is required!"
      ]);
  }

   return json_encode($response);
}

Route::get('getcarousel',['middleware'=>'cors',function(){
  $sql = 'SELECT *
  FROM t_carousel
  WHERE published=1
  ORDER BY rank ASC';
  $carousels = DB::select($sql);
  return $carousels;
}]);

Route::get('getcoupons',['middleware'=>'cors',function(){
  //get all coupons from t_coupons table where public is equal to true
  $sql = 'SELECT *
  FROM t_coupons
  WHERE public="true" AND CURDATE()>=date_start AND CURDATE()<=date_end AND status<>0';
  $publiccoupon = DB::select($sql);
  return $publiccoupon;
}]);

Route::get('couponverifier', ['middleware' => 'cors', function(){
    $code   = isset($_GET['code']) ? $_GET['code']: 0;
    $uid    = isset($_GET['uid']) ? $_GET['uid']: 0;
    return couponverifier($code, $uid);
}]);

function checkifsale($id, $startdate, $enddate, $i = 'category'){
  date_default_timezone_set("Asia/Singapore");

  if($i=="category"){
    $sqlsale = 'SELECT *
    FROM t_onsale
    WHERE id_on_sale='.$id.'
    AND is_category="true"
    AND "'.$startdate.'">=start_date
    AND "'.$enddate.'"<=end_date';
  }
  else{
    $sqlsale = 'SELECT *
    FROM t_onsale
    WHERE id_on_sale='.$id.'
    AND is_product="true"
    AND "'.$startdate.'">=start_date
    AND "'.$enddate.'"<=end_date';
  }

  $result = DB::select($sqlsale);

  return $result;
}

function taiga_crypt( $string, $action = 'e' ) {
    // you may change these values to your own
    $secret_key = 'taigacorporation';
    $secret_iv = 'mytaigasecret';

    $output = false;
    $encrypt_method = "AES-256-CBC";
    $key = hash( 'sha256', $secret_key );
    $iv = substr( hash( 'sha256', $secret_iv ), 0, 16 );

    if( $action == 'e' ) {
        $output = base64_encode( openssl_encrypt( $string, $encrypt_method, $key, 0, $iv ) );
    }
    else if( $action == 'd' ){
        $output = openssl_decrypt( base64_decode( $string ), $encrypt_method, $key, 0, $iv );
    }

    return $output;
}

Route::get('submitproductsubcomment',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $pid  = isset($_GET['productid']) ? $_GET['productid'] : "";
  $reply = isset($_GET['reply']) ? $_GET['reply'] : "";
  $uid = isset($_GET['userid']) ? $_GET['userid'] : "";
  $comment_id = isset($_GET['commentid']) ? $_GET['commentid'] : "";
  $error = 0; $response_msg = "Unable to submit comment.";

  //get user id using uid
  //get id of user
  $user_id = "";
  $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
  foreach($user as $u){
    if(!empty($u->id)){
      $user_id = $u->id;
    }
  }

  $uid = $user_id;

  if(!empty($pid) and !empty($uid) and !empty($reply) and !empty($comment_id)){
    $prod_id = taiga_crypt($pid,'d'); //encrypt the product id
    $userid  = $uid; //taiga_crypt($uid,'d'); //encrypt the user id

    //verify if user exists
    $sqlverifyuser = 'SELECT * FROM users WHERE id='.$userid;
    $userresponse = DB::select($sqlverifyuser);

    //verify if product exists
    $sqlverifyproduct = 'SELECT * FROM t_products WHERE id='.$prod_id;
    $productresponse  = DB::select($sqlverifyproduct);

    //verify if comment exists
    $sqlverifycomment = 'SELECT * FROM t_comments WHERE id='.$comment_id;
    $commentresponse  = DB::select($sqlverifycomment);

    //verify the comment does not exceed 150
    $strlen = strlen($reply);

    if(!empty($userresponse) and !empty($productresponse) and $strlen<=150){
      $com = new Comments;
      $com->parent_comment_id = $comment_id;
      $com->product_id = $prod_id;
      $com->comment = $reply;
      $com->user_id = $userid;
      $com->status = 1;
      $com->save();

      if(!empty($com->id)){
        $response_msg = "Successfully submitted your comment.";
      }
    }

  }

  $response = array('error'=>$error,'message'=>$response_msg);

  return $response;
}]);

Route::get('submitproductcomments',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $pid  = isset($_GET['productid']) ? $_GET['productid'] : "";
  $comment = isset($_GET['comment']) ? $_GET['comment'] : "";
  $uid = isset($_GET['userid']) ? $_GET['userid'] : "";
  $error = 0;$response_msg = "Unable to submit comment.";

  //get user id using uid
  //get id of user
  $user_id = "";
  $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
  foreach($user as $u){
    if(!empty($u->id)){
      $user_id = $u->id;
    }
  }

  $uid = $user_id;


  if(!empty($pid) and !empty($uid) and !empty($comment)){
    $prod_id = taiga_crypt($pid,'d'); //encrypt the product id
    $userid  = $uid; //taiga_crypt($uid,'d'); //encrypt the user id

    //verify if user exists
    $sqlverifyuser = 'SELECT * FROM users WHERE id='.$userid;
    $userresponse = DB::select($sqlverifyuser);

    //verify if product exists
    $sqlverifyproduct = 'SELECT * FROM t_products WHERE id='.$prod_id;
    $productresponse  = DB::select($sqlverifyproduct);

    //verify the comment does not exceed 150
    $strlen = strlen($comment);

    //verify the user has not yet submitted a comment
    /*$sqlverifycomment = 'SELECT * FROM t_comments WHERE user_id='.$userid.' AND product_id='.$prod_id.' AND parent_comment_id=0';
    $usercomment  = DB::select($sqlverifycomment);*/
    //$response_msg = "Unable to submit comment.";
    //print_r($usercomment);
    if(!empty($userresponse) and !empty($productresponse) and $strlen<=150){
      $com = new Comments;
      $com->product_id = $prod_id;
      $com->comment = $comment;
      $com->user_id = $userid;
      $com->status = 1;
      $com->save();

      if(!empty($com->id)){
        $response_msg = "Successfully submitted your comment.";
      }
    }

  }

  $response = array('error'=>$error,'message'=>$response_msg);

  return $response;
}]);

Route::get('deletecomment',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

    $cid  = isset($_GET['id']) ? $_GET['id'] : "";
    $uid = isset($_GET['userid']) ? $_GET['userid'] : "";
    $response = array('error'=>0,'message'=>'No error.');

    $user_id = "";
    $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
    foreach($user as $u){
      if(!empty($u->id)){
        $user_id = $u->id;
      }
    }

    $uid = $user_id;

    if(!empty($user_id) and !empty($cid)){
      //verify if the comment is user's comment
      $sqlcomments = 'SELECT * FROM t_comments WHERE id='.$cid.' AND user_id='.$uid;
      $commentsresult = DB::select($sqlcomments);

      if(!empty($commentsresult)){
        if(!empty($cid)){
          $comments = Comments::find($cid);
          if(!empty($comments)){
            $res = Comments::where('id',$cid)->delete();
            $response = array('error'=>0,'message'=>'Successfully deleted the comment!');
          }
          else{
            $response = array('error'=>1,'message'=>'Record does not exist.');
          }

        }
        else{
          $response = array('error'=>0,'message'=>'An error occured while deleting your comment.');
        }
      }
      else{
        $response = array('error'=>0,'message'=>'Error occured.');
      }

    }

    return $response;
}]);

Route::get('getproductcomments',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $pid  = isset($_GET['productid']) ? $_GET['productid'] : "";
  $error = 0; $response_msg = "No comments yet."; $data = array(); $name = ""; $userrating = 0;

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 10; //set number of records per page

  if(!empty($pid)){
    //verify product exist
    $pid = taiga_crypt($pid,'d'); //encrypt the product id

    $sqlverifyproduct = 'SELECT * FROM t_products WHERE id='.$pid;
    $productresponse  = DB::select($sqlverifyproduct);

    if(!empty($productresponse)){
      $sqlcomments = 'SELECT
                      t_comments.id,
                      t_comments.parent_comment_id,
                      t_comments.user_id,
                      users.remember_token,
                      t_comments.product_id,
                      t_comments.comment,
                      t_comments.status,
                      t_comments.updated_at,
                      t_comments.created_at
                      FROM t_comments
                      JOIN users ON users.id=t_comments.user_id
                      WHERE t_comments.product_id='.$pid.' AND t_comments.status=1 AND t_comments.parent_comment_id=0
                      ORDER BY t_comments.created_at DESC';
      $commentsresult = DB::select($sqlcomments);
      if(!empty($commentsresult)){
        $a=0;
        foreach($commentsresult as $cs){

          //get name of the user
          $sqluserinfo = 'SELECT name from users WHERE id='.$cs->user_id;
          $userresult  = DB::select($sqluserinfo);

          foreach($userresult as $ur){
            if(!empty($ur->name)){
              $name = $ur->name;
            }
          }

          //get if user has rated the product
          $sqluserrate = 'SELECT rating FROM t_ratings WHERE user_id='.$cs->user_id.' AND product_id='.$pid;
          $userrateresult = DB::select($sqluserrate);
          foreach($userrateresult as $u){
            if(!empty($u->rating)){
              $userrating = $u->rating;
            }
          }

          //query here all replies for the comment
          $sqluserreplies = 'SELECT t_comments.id,
                              t_comments.parent_comment_id,
                              t_comments.comment,
                              t_comments.product_id,
                              users.name,
                              users.remember_token,
                              t_comments.created_at
                              FROM t_comments
                              JOIN users ON users.id=t_comments.user_id
                              WHERE parent_comment_id='.$cs->id.'
                              ORDER BY created_at ASC';
          $userreplies = DB::select($sqluserreplies);

          $data[$a] = array('comment_id'=>$cs->id,'remember_token'=>$cs->remember_token,'name'=>$name,'rates'=>$userrating,'comment'=>$cs->comment,'date'=>$cs->created_at,'replies'=>$userreplies);

          $a++;
        }

        //$data = array('comments'=>$data);
        $response_msg = "Successfully listed comments.";
      }

    }

  }

  $col = new Collection($data); //convert array into laravel collection

  //Slice array based on the items wish to show on each page and the current page number
  $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

  //Save the Pagination in a variable which will be passed to view
  $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

  $response = array('error'=>$error,'message'=>$response_msg,'data'=>$prod_arr);

  return $response;
}]);

Route::get('submitproductrating',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $pid  = isset($_GET['productid']) ? $_GET['productid'] : "";
  $uid  = isset($_GET['userid']) ? $_GET['userid'] : "";
  $rate = isset($_GET['rate']) ? $_GET['rate'] : "";
  $error = 0; $response_msg = "Unable to submit rating.";

  //get user id using uid
  //get id of user
  $user_id = "";
  $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
  foreach($user as $u){
    if(!empty($u->id)){
      $user_id = $u->id;
    }
  }

  $uid = $user_id;

  if(!empty($pid) and !empty($uid) and !empty($rate)){
    $prod_id = taiga_crypt($pid,'d'); //encrypt the product id
    $userid  = $uid;//taiga_crypt($uid,'d'); //encrypt the user id

    //verify if user exists
    $sqlverifyuser = 'SELECT * FROM users WHERE id='.$userid;
    $userresponse = DB::select($sqlverifyuser);

    //verify if product exists
    $sqlverifyproduct = 'SELECT * FROM t_products WHERE id='.$prod_id;
    $productresponse  = DB::select($sqlverifyproduct);

    //verify if rate is integer as well
    if(!empty($userresponse) and !empty($productresponse) and is_numeric($rate)){

      //verify user has not yet rated the product
      $ver = 'SELECT * FROM t_ratings WHERE product_id='.$prod_id.' AND user_id='.$userid;
      $verresult = DB::select($ver);

      if(empty($verresult)){
        $rates = new Ratings;
        $rates->product_id = $prod_id;
        $rates->rating = $rate;
        $rates->user_id = $userid;
        $rates->save();

        if(!empty($rates->id)){
          $response_msg = "Successfully submitted your rating.";
        }

        $r = array('prodid'=>$prod_id,'rating'=>$rate,'userid'=>$userid);
      }
      else{
        $error = 1;
        $response_msg = "You have already rated this product.";
      }

    }

  }

  $response = array('error'=>$error,'message'=>$response_msg);

  return $response;
}]);

Route::get('getproductratings',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $prod_id = taiga_crypt($_GET['productid'],'d'); //encrypt the product id

  if(!empty($prod_id)){
    $sql = 'SELECT * FROM t_ratings WHERE product_id='.$prod_id;
    $result = DB::select($sql);
    $totalrates = 0; $allrates=0; $one=0;$two=0;$three=0;$four=0;$five=0;$average=0;

    if(!empty($result)){
      foreach($result as $r){
        $allrates += $r->rating;

        if($r->rating==1) $one++;
        if($r->rating==2) $two++;
        if($r->rating==3) $three++;
        if($r->rating==4) $four++;
        if($r->rating==5) $five++;

        $totalrates++;
      }

      $average = $allrates / $totalrates;
    }
  }


  $response = array(
    'totalrates'=>$totalrates,
    'allrates'=>$allrates,
    'average'=> number_format(round($average,2),2),
    'onestar'=>$one,
    'twostar'=>$two,
    'threestar'=>$three,
    'fourstar'=>$four,
    'fivestar'=>$five);

  return $response;
}]);

Route::get('allbrands',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $sql = 'SELECT * FROM t_brands';

  return DB::select($sql);
}]);

Route::get('getvehicle',['middleware'=>'cors',function() {
  date_default_timezone_set("Asia/Singapore");

  $sql = 'SELECT vehicle_make FROM t_products WHERE vehicle_make<>"" AND model<>"" AND yr<>"" AND product<>"" GROUP BY vehicle_make';
  $vehicles = DB::select($sql);

  return $vehicles;
}]);

Route::get('getmodel',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $vehicle = isset($_GET['vehicle']) ? $_GET['vehicle'] : "";
  $result = "";
  if(!empty($vehicle)){
    if($vehicle!="all"){
      $sql = 'SELECT model FROM t_products WHERE vehicle_make<>"" AND vehicle_make="'.$vehicle.'" AND model<>"" GROUP BY model';
      $result = DB::select($sql);
    }
  }

  return $result;
}]);

//Route::get('vieworder/{orderno}','Orders@vieworder')->name('vieworder');

Route::get('getyear',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $vehicle = isset($_GET['vehicle']) ? $_GET['vehicle'] : "";
  $model   = isset($_GET['model']) ? $_GET['model'] : "";
  $result  = ""; $yrs_list=array(); $response = array(); $merged_arr=array();

  if(!empty($vehicle) and !empty($model)){
    $sql = 'SELECT yr FROM t_products WHERE vehicle_make<>"" AND vehicle_make="'.$vehicle.'" AND model="'.$model.'"';
    $result = DB::select($sql);

    $yrs = array(); $a=0;
    foreach($result as $r){
      $yrs[$a] = explode(",",$r->yr);
      $a++;
    }

    if(!empty($yrs)){
      $c = count($yrs);
      $merged_arr = array();
      for($b=0;$b<=$c;$b++){
        if(!empty($yrs[$b])){
            $merged_arr = array_merge($merged_arr,$yrs[$b]);
        }
      }
    }

    if(!empty($merged_arr)){

        for($d=0;$d<=count($merged_arr)-1;$d++){
          if(!in_array($merged_arr[$d],$yrs_list)){
            $yrs_list[$d] = $merged_arr[$d];
          }
        }

        $response = implode(",",$yrs_list);
        $response = explode(",",$response);
    }

  }

  sort($response);

  return $response;
}]);

Route::get('getproducts',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $vehicle = isset($_GET['vehicle']) ? str_replace(' ', '', $_GET['vehicle']) : "";
  $model   = isset($_GET['model']) ? str_replace(' ', '', $_GET['model']) : "";
  $yr      = isset($_GET['year']) ? str_replace(' ', '', $_GET['year']) : "";
  $result  = "";

  if(!empty($vehicle) and !empty($model) and !empty($yr)){
    $sql = 'SELECT product FROM t_products WHERE vehicle_make<>"" AND vehicle_make="'.$vehicle.'" AND model="'.$model.'" AND yr like "%'.$yr.'%" AND product<>"" GROUP BY product';
    $result = DB::select($sql);
  }

  return $result;
}]);

Route::get('getbrands',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $vehicle = isset($_GET['vehicle']) ? $_GET['vehicle'] : "";
  $model   = isset($_GET['model']) ? $_GET['model'] : "";
  $yr      = isset($_GET['year']) ? $_GET['year'] : "";
  $product = isset($_GET['product']) ? $_GET['product'] : "";
  $result  = "";
  $result_brand = "";
  $brand_arr = array();

  if(!empty($vehicle) and !empty($model) and !empty($yr) and !empty($product)){
    $sql = 'SELECT brand_id
            FROM t_products
            WHERE vehicle_make<>"" AND
            vehicle_make="'.$vehicle.'" AND
            model="'.$model.'" AND
            yr like "%'.$yr.'%"
            AND product="'.$product.'"
            GROUP BY brand_id';
    $result = DB::select($sql);

    //print_r($result);
    $brand_ids = array(); $a=0;
    foreach($result as $r){
      if(!empty($r->brand_id)){
        $brand_ids[$a]=$r->brand_id;
        $a++;
      }
    }

    $ex = implode(",",$brand_ids);

    $sql_brand = 'SELECT id,brand FROM t_brands WHERE id IN('.$ex.')';
    $result_brand = DB::select($sql_brand);

  }

  return $result_brand;
}]);

Route::get('advancesearch',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

        $vehicle = isset($_GET['vehicle']) ? $_GET['vehicle'] : "";
        $model   = isset($_GET['model']) ? $_GET['model'] : "";
        $yr      = isset($_GET['year']) ? $_GET['year'] : "";
        $product = isset($_GET['product']) ? $_GET['product'] : "";
        $brand   = isset($_GET['brand']) ? $_GET['brand'] : "";
        if(!empty($vehicle) and empty($model) and empty($yr) and empty($product) and empty($brand)){
           $conditions = 't_products.vehicle_make="'.$vehicle.'" AND';
        }
        if(!empty($vehicle) and !empty($model) and empty($yr) and empty($product) and empty($brand)){
           $conditions = 't_products.vehicle_make="'.$vehicle.'" AND
              t_products.model="'.$model.'" AND';
        }
        if(!empty($vehicle) and !empty($model) and !empty($yr) and empty($product) and empty($brand)){
           $conditions = 't_products.vehicle_make="'.$vehicle.'" AND
              t_products.model="'.$model.'" AND
              t_products.yr like "%'.$yr.'%" AND';
        }
        if(!empty($vehicle) and !empty($model) and !empty($yr) and !empty($product) and empty($brand)){
           $conditions = 't_products.vehicle_make="'.$vehicle.'" AND
              t_products.model="'.$model.'" AND
              t_products.yr like "%'.$yr.'%" AND
              t_products.product="'.$product.'" AND';
        }
        if(!empty($vehicle) and !empty($model) and !empty($yr) and !empty($product) and !empty($brand)){
            $conditions = 't_products.vehicle_make="'.$vehicle.'" AND
                t_products.model="'.$model.'" AND
                t_products.yr like "%'.$yr.'%" AND
                t_products.product="'.$product.'" AND
                t_products.brand_id="'.$brand.'" AND';
        }
        $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
        $perPage = 25; //set number of records per page
        $sql = 'SELECT * FROM ((SELECT
                t_brands.brand,
                t_products.name,
                t_products.id,
                t_variations.application,
                t_variations.retail_price,
                t_variations.price,
                t_variations.images,
                t_categories.id as categoryid
                FROM t_products
                LEFT JOIN t_brands ON t_products.brand_id=t_brands.id
                JOIN t_categories ON t_categories.id=t_products.category_id
                JOIN t_attributes ON t_attributes.product_id=t_products.id
                JOIN t_variations ON t_variations.attribute_id=t_attributes.id
                WHERE '.$conditions.' t_products.status<>0 )
                UNION
                (SELECT
                t_brands.brand,
                t_products.name,
                t_products.id,
                t_variations.application,
                t_variations.retail_price,
                t_variations.price,
                t_variations.images,
                t_categories.id as categoryid
                FROM t_products
                RIGHT JOIN t_brands ON t_products.brand_id=t_brands.id
                JOIN t_categories ON t_categories.id=t_products.category_id
                JOIN t_attributes ON t_attributes.product_id=t_products.id
                JOIN t_variations ON t_variations.attribute_id=t_attributes.id
                WHERE '. $conditions .' t_products.status<>0 ) ) as i ORDER BY RAND()';

      $result = DB::select($sql);

      $prod_arrz = array(); $a=0; $ProductDetails = array();$no_of_prod=0; $prod_ids = array();
      $onsale = false; $addsale = 0;
      foreach($result as $r){

        $saleresponse = checkifsale($r->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
        if(!empty($saleresponse)){
          //get the additional discount, this is to be added on product savings
          foreach($saleresponse as $sr){
            if(!empty($sr->percentage) and $sr->percentage!=0){
              $addsale = $sr->percentage;
              $onsale = true;
            }
          }
        }

        if(!empty($r->id)){
          $no_of_prod++;
          $prod_ids[$a] = $r->id;
        }

        $retail_price = str_replace(",","",$r->retail_price);
        $price = str_replace(",","",$r->price);

        $difference = (double)$retail_price - (double)$price;
        $savings = 0;
        $retail_price = (integer)$retail_price;
        if($retail_price>0){
            $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
        }

        $savings = round($savings,0);

        //===============================
        $productprice = round(trim($r->price),0);
        $totalsavings = $savings;
        $srp = round(trim($r->retail_price),0);

        if($onsale==true){
          $price = round(trim($r->price),0);
          $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
          $additionaldisc = round($salepercent * $price,0);
          $productprice = $price - $additionaldisc;
          $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
        }
          //===============================

        $ProductDetails[$a] = array(
          'application'=>$r->application,
          'retail_price'=>number_format($srp,2),
          'price'=>number_format($productprice,2),
          'images'=>$r->images);


        $prod_id = taiga_crypt($r->id,'e'); //encrypt the product id
        $brand = trim($r->brand);
        if(!empty($brand)){
          $prod_n = $brand.' '.$r->name;
        }
        else{
          $prod_n = $r->name;
        }

        $prod_arrz[$a] = array(
          'idz'=>$r->id,
          'ProductID'=>$prod_id,
          'ProductName'=>$prod_n,
          'OnSale'=>$onsale,
          'retailprice'=>$retail_price,
          'ProductSavings'=>$totalsavings,
          'ProductDetails'=>array($ProductDetails[$a]));

        $a++;
        $addsale=0;
      }

      $col = new Collection($prod_arrz); //convert array into laravel collection

      //Slice array based on the items wish to show on each page and the current page number
      $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

      //Save the Pagination in a variable which will be passed to view
      $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

      //save the search keyword
      $reg = new SearchKeywords;
      $reg->searched_keyword = "advanced searching using the ff filters: "."vehicle: ".$vehicle." | model: ".$model." | year: ".$yr." | product: ".$product." | brand: ".$brand; //$keyword;
      $reg->no_of_result = $no_of_prod;
      $reg->product_ids = implode(', ',$prod_ids);
      $reg->save();

  return $prod_arr;
}]);

Route::get('/', function () {
    return view('welcome');
});

Route::get('testtimezone',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");
  echo date("Y-m-d h:i:s");
}]);

Route::get('products/', 'ProductsController@index');

Route::get('getprovincials',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $province = \App\Province::select('id','province')
  ->orderBy('province','asc')
  ->get();
  return $province;
}]);

Route::get('getmunicipalities',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $province_id = isset($_GET['id']) ? $_GET['id'] : "";
  $municipalities = \App\Municipalities::select('id','municipality_city')
  ->where('province_id','=',$province_id)
  ->orderBy('municipality_city', 'asc')
  ->get();
  return $municipalities;
}]);

Route::get('sellontaiga',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $companyname = isset($_GET['companyname']) ? $_GET['companyname'] : "";
  $email = isset($_GET['email']) ? $_GET['email'] : "";
  $contactperson = isset($_GET['contactperson']) ? $_GET['contactperson'] : "";
  $mobile = isset($_GET['mobile']) ? $_GET['mobile'] : "";
  $products = isset($_GET['products']) ? $_GET['products'] : "";

  $ui = new SellOnTaiga;
  $ui->companyname = $companyname;
  $ui->email = $email;
  $ui->contactperson = $contactperson;
  $ui->mobile = $mobile;
  $ui->products = $products;
  $ui->save();


  $response = array('status'=>'success','message'=>'Successfully submitted the form.','data'=>'');

  return $response;
}]);

Route::get('getrefno',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

    $mId   = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantid')->get();
    $mKey  = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantidkey')->get();
    $mHost = \App\Settings::select('field_name','field_value')->where('field_name','=','dragonpayhost')->get();
    $sUrl  = \App\Settings::select('field_name','field_value')->where('field_name','=','soapurl')->get();

    //create settings table
    $merchantId = $mId[0]->field_value;
    $key        = $mKey[0]->field_value; //"po1rJDrRjFFsEUT";//"7n19ooLyfzv94s1";
    $host       = $mHost[0]->field_value; //"gw.dragonpay.ph"; //"test.dragonpay.ph";
    $txnid      = isset($_GET['txnId']) ? $_GET['txnId'] : "";
    $cart_id    = isset($_GET['cart_id']) ? $_GET['cart_id'] : "";
    $uid        = isset($_GET['uid']) ? $_GET['uid'] : "";

    if($txnid!=""){
      $user_id = "";
      $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
      foreach($user as $u){
        if(!empty($u->id)){
          $user_id = $u->id;
        }
      }

      if(!empty($user_id)){
        $xmlstr = '<?xml version="1.0" encoding="utf-8"?>
          <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
            <soap:Body>
              <GetTxnRefNo xmlns="http://api.dragonpay.ph/">
                <merchantId>'.$merchantId.'</merchantId>
                <password>'.$key.'</password>
                <txnId>'.$txnid.'</txnId>
              </GetTxnRefNo>
            </soap:Body>
          </soap:Envelope>';

          $soapaction = "GetTxnRefNo";
          $soapUrl = $sUrl[0]->field_value.$soapaction;

          $headers = array(
          "POST /DragonPayWebService/MerchantService.asmx HTTP/1.1",
          "Host: ".$host,
          "Content-Type: text/xml; charset=utf-8",
          "Content-Length: ".strlen($xmlstr)
          );

          $url = $soapUrl;

          $ch = curl_init();
          curl_setopt($ch, CURLOPT_URL, $url);
          curl_setopt($ch, CURLOPT_POST, true);
          curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlstr);
          curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

          $responsexml = curl_exec($ch);
          curl_close($ch);

          $response1 = str_replace("<soap:Body>","",$responsexml);
          $response2 = str_replace("</soap:Body>","",$response1);

          $parser = simplexml_load_string($response2);

          $xml = new SimpleXMLElement($response2);
          $xmlrefno = (string)$xml->GetTxnRefNoResponse->GetTxnRefNoResult;

          $updateorder = DB::table('t_carts')
            ->where('id',$cart_id)
            ->where('users_id',$user_id)
            ->update(['status'=>1,'date_time'=>date("Y-m-d h:i:s")]);

          $response = array('status'=>'success','message'=>'Successfully fetched refno.','data'=>array('refno'=>$xmlrefno));
      }
      else{
        $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
      }

    }
    else{
      $response = array('status'=>'error','message'=>'Unable to fetched refno.','data'=>'');
    }

    return $response;
}]);

Route::post('postback',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  //the postback url should return a simple response content-type:text/plain containing only the single line result=ok

  if($_SERVER['REQUEST_METHOD']=='POST'){
    //fetch postback Data
    $txnID = $_POST['txnid'] ?? '';
    $refNo = $_POST['refno'] ?? '';
    $status = $_POST['status'] ?? 'F';
    $message = $_POST['message'] ?? '';
    $digest = $_POST['digest'] ?? '';
    //$param1 = $_POST['param1'] ?? '';
    //$param2 = $_POST['param2'] ?? '';
    /*
    txnid - A unique id identifying this specific transaction from the merchant side
    refno - A common reference number identifying this specific transaction from the PS side
    status - The result of the payment. Refer to Appendix 3 for codes.
    message - If status is SUCCESS, this should be the PG transaction
              reference number. If status is FAILURE, return one of the error
              codes described in Appendix 2. If status is PENDING, the
              message would be a reference number to complete the funding.
    digest - A sha1 checksum digest of the parameters along with the secret key.
    S Success
    F Failure
    P Pending
    U Unknown
    R Refund
    K Chargeback
    V Void
    A Authorized*/

    //get txnid
    $orderno = \App\Carts::select('order_no')
    ->where('order_no','=',$txnID)
    ->get();

    $ordernumber=0;
    foreach($orderno as $on){
      if(!empty($on->order_no)){
        $ordernumber = $on->order_no;
      }
    }

    //update status of txnid
    if($ordernumber>0){
      $db_status=0;
      if($status=="P"){ //
        $db_status = 1;
      }
      elseif($status=="S"){
        $db_status = 2;
      }
      elseif($status=="F"){
        $db_status = 99;
      }
      elseif($status=="V"){
        $db_status = 98;
      }
      elseif($status=="A"){
        $db_status = 97;
      }
      elseif($status=="K"){
        $db_status = 96;
      }
      elseif($status=="R"){
        $db_status = 95;
      }
      elseif($status=="U"){
        $db_status = 94;
      }

      $updateorder = DB::table('t_carts')
        ->where('order_no',$ordernumber)
        ->update(['dp_refno'=>$refNo,
                  'dp_status'=>$status,
                  'status'=>$db_status
                  ]);

      $response = array('status'=>'success','message'=>'Successfully update the record.');
    }
    else{
      $response = array('status'=>'error','message'=>'No record has been updated.');
    }

  }
  /*else{
    //100034
    $txnID = "100034"; //$_POST['txnid'] ?? '';
    $txnid2 = $_POST['txnid'] ?? '';
    $refNo = $_POST['refno'] ?? '';
    $status = $_POST['status'] ?? 'F';
    $message = $_POST['message'] ?? '';
    $digest = $_POST['digest'] ?? '';

    $orderno = \App\Carts::select('order_no')
    ->where('order_no','=',$txnID)
    ->get();

    $ordernumber=0;
    foreach($orderno as $on){
      if(!empty($on->order_no)){
        $ordernumber = $on->order_no;
      }
    }

    //update status of txnid
    if($ordernumber>0){
      $updateorder = DB::table('t_carts')
        ->where('order_no',$ordernumber)
        ->update(['dp_refno'=>$refNo,
                  'dp_status'=>$status
                  ]);

      $response = array('status'=>'success','message'=>'Successfully update the record.');
    }
    else{
      $response = array('status'=>'error','message'=>'No record has been updated.');
    }

  }*/

  $dbinsert = DB::table('postback_logs')->insert(
    ['TxnID' => $_POST['txnid'], 'RefNo' => $_POST['refno'], 'Status'=>$_POST['status'],'Message'=>$_POST['message'],'Digest'=>$_POST['digest'],'created_at'=>date("Y-m-d h:i:s")]
  );

}]);

/*Route::get('returnurl',['middleware'=>'cors',function(){

}]);*/

Route::get('getavailableprocessors',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $mId   = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantid')->get();
  $mKey  = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantidkey')->get();
  $mHost = \App\Settings::select('field_name','field_value')->where('field_name','=','dragonpayhost')->get();
  $sUrl  = \App\Settings::select('field_name','field_value')->where('field_name','=','soapurl')->get();

  //create settings table
  $merchantId = $mId[0]->field_value;
  $key        = $mKey[0]->field_value; //"po1rJDrRjFFsEUT";//"7n19ooLyfzv94s1";
  $host       = $mHost[0]->field_value; //"gw.dragonpay.ph"; //"test.dragonpay.ph";
  $price      = isset($_GET['price']) ? $_GET['price'] : "";

  $xmlstr = '<?xml version="1.0" encoding="utf-8"?>
    <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
      <soap:Body>
        <GetAvailableProcessors xmlns="http://api.dragonpay.ph/">
          <merchantId>'.$merchantId.'</merchantId>
          <password>'.$key.'</password>
          <amount>'.$price.'</amount>
        </GetAvailableProcessors>
      </soap:Body>
    </soap:Envelope>';

    $soapaction = "GetAvailableProcessors";

    $soapUrl = $sUrl[0]->field_value.$soapaction;
    //"https://gw.dragonpay.ph/DragonPayWebService/MerchantService.asmx?op=GetAvailableProcessors"; //https://test.dragonpay.ph/DragonPayWebService/MerchantService.asmx?op=GetAvailableProcessors";

    $headers = array(
    "POST /DragonPayWebService/MerchantService.asmx HTTP/1.1",
    "Host: ".$host,
    "Content-Type: text/xml; charset=utf-8",
    "Content-Length: ".strlen($xmlstr),
    "SOAPAction: http://api.dragonpay.ph/GetAvailableProcessors"
    );

    $url = $soapUrl;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlstr);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $responsexml = curl_exec($ch);
    curl_close($ch);

    $response1 = str_replace("<soap:Body>","",$responsexml);
    $response2 = str_replace("</soap:Body>","",$response1);

    $parser = simplexml_load_string($response2);

    $xml = new SimpleXMLElement($response2);

    $xml = simplexml_load_string($response2);

    $response = array();$a=0;
    foreach($xml->GetAvailableProcessorsResponse->GetAvailableProcessorsResult->ProcessorInfo as $item)
    {
      $response[$a] = array(
        'ProcID'=>(string)$item->procId,
        'shortname'=>(string)$item->shortName,
        'longname'=>(string)$item->longName,
        'logo'=>(string)$item->logo,
        'status'=>(string)$item->status,
        'minAmount'=>(string)$item->minAmount,
        'maxAmount'=>(string)$item->maxAmount,
        'currencies'=>(string)$item->currencies,
        'url'=>(string)$item->url,
        'realTime'=>(string)$item->realTime,
        'pwd'=>(string)$item->pwd,
        'defaultBillerId'=>(string)$item->defaultBillerId,
        'hasTxnPwd'=>(string)$item->hasTxnPwd,
        'hasManualEnrollment'=>(string)$item->hasManualEnrollment,
        'type'=>(string)$item->type,
        'remarks'=>(string)$item->remarks,
        'dayOfWeek'=>(string)$item->dayOfWeek,
        'startTime'=>(string)$item->startTime,
        'endTime'=>(string)$item->endTime,
        'mustRedirect'=>(string)$item->mustRedirect,
        'surcharge'=>(string)$item->surcharge,
        'hasAltRefNo'=>(string)$item->hasAltRefNo,
        'cost'=>(string)$item->cost);

        $a++;
    }

    return $response;
}]);

Route::get('getprocessors',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $mId   = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantid')->get();
  $mKey  = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantidkey')->get();
  $mHost = \App\Settings::select('field_name','field_value')->where('field_name','=','dragonpayhost')->get();
  $sUrl  = \App\Settings::select('field_name','field_value')->where('field_name','=','soapurl')->get();

  //create settings table
  $merchantId = $mId[0]->field_value;
  $key        = $mKey[0]->field_value; //"po1rJDrRjFFsEUT";//"7n19ooLyfzv94s1";
  $host       = $mHost[0]->field_value; //"gw.dragonpay.ph"; //"test.dragonpay.ph";

    $xmlstr = '<?xml version="1.0" encoding="utf-8"?>
    <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
      <soap:Body>
        <GetProcessors xmlns="http://api.dragonpay.ph/" />
      </soap:Body>
    </soap:Envelope>';

      $soapaction = "GetProcessors";

      $soapUrl = $sUrl[0]->field_value.$soapaction;
      //"https://gw.dragonpay.ph/DragonPayWebService/MerchantService.asmx?op=GetProcessors";

      $headers = array(
      "POST /DragonPayWebService/MerchantService.asmx HTTP/1.1",
      "Host: ".$host,
      "Content-Type: text/xml; charset=utf-8",
      "Content-Length: ".strlen($xmlstr)
      );

      $url = $soapUrl;

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlstr);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

      $responsexml = curl_exec($ch);
      curl_close($ch);

      $response1 = str_replace("<soap:Body>","",$responsexml);
      $response2 = str_replace("</soap:Body>","",$response1);

      $parser = simplexml_load_string($response2);

      $xml = new SimpleXMLElement($response2);

      $xml = simplexml_load_string($response2);
      $response = array();$a=0;
      foreach($xml->GetProcessorsResponse->GetProcessorsResult->ProcessorInfo as $item)
      {
        $response[$a] = array(
          'ProcID'=>(string)$item->procId,
          'shortname'=>(string)$item->shortName,
          'longname'=>(string)$item->longName,
          'logo'=>(string)$item->logo,
          'status'=>(string)$item->status,
          'minAmount'=>(string)$item->minAmount,
          'maxAmount'=>(string)$item->maxAmount,
          'currencies'=>(string)$item->currencies,
          'url'=>(string)$item->url,
          'realTime'=>(string)$item->realTime,
          'pwd'=>(string)$item->pwd,
          'defaultBillerId'=>(string)$item->defaultBillerId,
          'hasTxnPwd'=>(string)$item->hasTxnPwd,
          'hasManualEnrollment'=>(string)$item->hasManualEnrollment,
          'type'=>(string)$item->type,
          'remarks'=>(string)$item->remarks,
          'dayOfWeek'=>(string)$item->dayOfWeek,
          'startTime'=>(string)$item->startTime,
          'endTime'=>(string)$item->endTime,
          'mustRedirect'=>(string)$item->mustRedirect,
          'surcharge'=>(string)$item->surcharge,
          'hasAltRefNo'=>(string)$item->hasAltRefNo,
          'cost'=>(string)$item->cost);

          $a++;
      }

      return $response;
  }]);


  Route::get('getorders',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";

  $user_id = "";
  $sql='SELECT id,name,email from users where remember_token="'.$uid.'"';
  $user = DB::select($sql);
  foreach($user as $u){
    if(!empty($u->id)){
      $user_id = $u->id;
    }
  }

  if(!empty($user_id)){
    $response = \App\Carts::select('order_no','date_time','dp_refno','dp_status','status')->where('status','>=',1)->where('users_id','=',$user_id)->get();
  }
  else{
    $response = array('status'=>'error','message'=>'Unknown user.','data'=>'');
  }

  return $response;

}]);

Route::get('orderdetails',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

    $orderno = isset($_GET['orderno']) ? $_GET['orderno'] : "";

    $sql = 'SELECT
    t_carts.payment_method,
    t_products.id,
    concat(t_products.name," ",t_brands.brand) as productname,
    t_product_orders.sku,
    t_product_orders.quantity,
    t_variations.price,
    t_variations.images
    FROM t_carts
    JOIN t_product_orders ON t_product_orders.cart_id=t_carts.id
    JOIN t_variations ON t_variations.sku=t_product_orders.sku
    JOIN t_attributes ON t_attributes.id=t_variations.attribute_id
    JOIN t_products   ON t_products.id=t_attributes.product_id
    JOIN t_brands     ON t_brands.id=t_products.brand_id
    WHERE t_carts.order_no='.$orderno.' AND t_product_orders.status=1';

    $order_details = DB::select($sql);

    $response = array('status'=>'error','message'=>'No records found.','data'=>'');
    if(!empty($order_details)){
      $response = array('status'=>'success','message'=>'Listing items for order.','data'=>$order_details);
    }

    return $response;
}]);

Route::get('placeordermobile',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $mId      = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantid')->get();
  $mKey     = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantidkey')->get();
  $mHost    = \App\Settings::select('field_name','field_value')->where('field_name','=','dragonpayhost')->get();
  $sUrl     = \App\Settings::select('field_name','field_value')->where('field_name','=','soapurl')->get();
  $taigaurl = \App\Settings::select('field_name','field_value')->where('field_name','=','taigaurl')->get();

  //create settings table
  $merchantId = $mId[0]->field_value;
  $key        = $mKey[0]->field_value; //"po1rJDrRjFFsEUT";//"7n19ooLyfzv94s1";
  $host       = $mHost[0]->field_value; //"gw.dragonpay.ph"; //"test.dragonpay.ph";

  $cartid = isset($_GET['cart_id']) ? $_GET['cart_id'] : ""; //current cart id of the user
  $uid = isset($_GET['uid']) ? $_GET['uid'] : ""; //uid of the user
  $procid = isset($_GET['procid']) ? $_GET['procid'] : ""; //determines payment method
  $amount = isset($_GET['amount']) ? $_GET['amount'] : ""; //total amount
  $desc = isset($_GET['description']) ? $_GET['description'] : "";
  $email = isset($_GET['email']) ? $_GET['email'] : "";
  $notes = isset($_GET['order_notes']) ? $_GET['order_notes'] : ""; //order notes left by the user or simply additional instructions
  $freight_collect = isset($_GET['freight_id']) ? $_GET['freight_id'] : "0";
  //$payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : "";

  $couponcode = isset($_GET['coupon_code']) ? $_GET['coupon_code'] : ""; //add coupon code as additional param
  //also add shipping fee info
  $shippingfee = isset($_GET['shippingfee']) ? $_GET['shippingfee'] : ""; //add shippingfee as additional param
  $ruleshipping="false";

  //this will be temporary be removed
  /*if(!empty($couponcode)){
	  $r = couponverifier($couponcode, $uid);
	  $rr = json_decode($r);

	  //the discounts
	  $shippingdiscount = $rr->result->shipping_discount;
	  $totaldiscount    = $rr->result->total_discount;
	  $percent_cash     = $rr->result->total_unit;
	  //true or false
	  $ruleshipping     = $rr->rules->shipping;
	  $ruletotal        = $rr->rules->total;

	  $discountcash=""; $discountshipping="";
	  //get if rule total is true
	  if($ruletotal=="true"){
		if($percent_cash=="percent"){ //if discount is in percent
		  $discountcash = $amount * ($totaldiscount / 100);
		}
		else{ //if discount is in cash
		  $discountcash = $totaldiscount;
		}

		$amount = $amount - $discountcash;
	  }
  }

  if(!empty($shippingfee)){ //just to make sure the order has a shipping fee
    if($ruleshipping=="true"){
      if($percent_cash=="percent"){ //if discount is in percent
        $discountshipping = $shippingfee * ($shippingdiscount / 100);
      }
      else{ //if discount is in cash
        $discountshipping = $shippingdiscount;
      }

      $shippingfee = $shippingfee - $discountshipping;
    }
  }*/
  //this will be removed

  //should get the amount from param or compute here?
  //total amount of payment should be computed here as well and compared to the submitted amount if equal.
  //confirm required fields are met

  if(!empty($cartid) and !empty($uid) and !empty($procid) and !empty($amount)){
        //fetch and confirm if cart has atleast one or more items active/has item
        $sql_check_items = 'SELECT * FROM t_product_orders WHERE cart_id='.$cartid.' AND quantity>=1 AND status>=1';
        $order_items = DB::select($sql_check_items);
        $no_order = 0;
        foreach($order_items as $oi){
          if(!empty($oi->sku)){
            $no_order++;
          }
        }

        //fetch user id
        $user_id = "";
        $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
        foreach($user as $u){
          if(!empty($u->id)){
            $user_id = $u->id;
          }
        }
        //end of fetching user id

        //fetch address to deliver the orders
        $userinfo = \App\Users_Info::select('unit_st_brgy','municipality_city_id','province_id')
        ->where('users_id','=',$user_id)
        ->where('set_default','=',1)
        ->get();

        $unit_st_brgy = ""; $municipality_city_id=0; $province_id=0;
        foreach($userinfo as $ui){
          if(!empty($ui->unit_st_brgy) and !empty($ui->municipality_city_id) and !empty($ui->province_id)){
            $unit_st_brgy = $ui->unit_st_brgy;
            $municipality_city_id = $ui->municipality_city_id;
            $province_id = $ui->province_id;
          }
        }
        //end of fetching the address to deliver the item/s

        if($no_order>=1){

            //update the status of cart id to 1
            $def_order_no = '100000'+$cartid; //this should be changed later
            $fetchedor = "";
            $orderno = \App\Carts::select('order_no')->orderBy('order_no','DESC')->take(1)->get();
            foreach($orderno as $or){
              if(!empty($or->order_no)){
                $fetchedor=$or->order_no;
              }
            }

            if($fetchedor!="" and $fetchedor>=1){
              $def_order_no = $fetchedor+1;
            }
            else{
              $def_order_no = '100000'+1;
            }

            //make sure address is not empty
            if(!empty($unit_st_brgy) and $municipality_city_id!=0 and $province_id!=0){
                if($procid=="CODT" and $freight_collect==0){ //if payment is COD
                  $updateorder = DB::table('t_carts')
                    ->where('id',$cartid)
                    ->where('users_id',$user_id)
                    ->update(['order_no'=>$def_order_no,
                              'status'=>2,
                              'order_notes'=>$notes,
                              'order_description'=>$desc,
                              'payment_method'=>$procid,
                              'date_time'=> date("Y-m-d h:i:s"),
                              'total_payment' => number_format($amount,2,'.',''),
                              'total_shipping' => $shippingfee,
                              'ship_to_st_brgy'=>$unit_st_brgy,
                              'ship_to_municipality'=>$municipality_city_id,
                              'freight_collect_id'=>$freight_collect,
                              'coupon_code' => $couponcode,
                              'total_shipping' => $shippingfee,
                              'ship_to_province'=>$province_id]);
                }
                elseif($procid!="CODT" and ($freight_collect==0 or $freight_collect>=1)){ //if payment is thru dragonpay
                  $updateorder = DB::table('t_carts')
                    ->where('id',$cartid)
                    ->where('users_id',$user_id)
                    ->update(['order_no'=>$def_order_no,
                              'status'=>1,
                              'order_notes'=>$notes,
                              'order_description'=>$desc,
                              'payment_method'=>$procid,
                              'date_time'=> date("Y-m-d h:i:s"),
                              'total_payment' => number_format($amount,2,'.',''),
                              'total_shipping' => number_format($shippingfee,2,'.',''),
                              'ship_to_st_brgy'=>$unit_st_brgy,
                              'ship_to_municipality'=>$municipality_city_id,
                              'freight_collect_id'=>$freight_collect,
                              'coupon_code' => $couponcode,
                              'total_shipping' => $shippingfee,
                              'ship_to_province'=>$province_id]);
                }

                $url = $taigaurl[0]->field_value.$def_order_no; //"https://taiga.com.ph/confirm?txnid=".$def_order_no;//used for dragonpay method

                if($procid!='CODT'){
                  //generate url for dragon pay
                  $params = array(
                        'merchantid' => $merchantId,
                        'txnid'      => $def_order_no,
                        'amount'     => number_format($amount,2,'.',''),
                        'ccy'        => 'PHP',
                        'description'=> $desc,
                        'email'      => $email,
                        'key'        => $key //'po1rJDrRjFFsEUT'//'7n19ooLyfzv94s1'
                     );

                   $digest = implode(':',$params);
                   unset($params['key']);

                   $params['digest'] = sha1($digest);
                   $url = 'https://gw.dragonpay.ph/Pay.aspx?'.http_build_query($params).'&procid='.$procid;
                }

                 $response = array('status'=>'success','message'=>'Successfully submitted cart for order.','data'=>array('txnid'=>$def_order_no,'url'=>$url));
            }
            else{
                $response = array('status'=>'error','message'=>'Default address is required to proceed.','data'=>'');
            }

        }
        else{
          $response = array('status'=>'error','message'=>'Cart is empty.','data'=>'');
        }
  }
  else{
      //should have error logs here to be saved
      $response = array('status'=>'error','message'=>'Insufficient parameters error occured. Kindly contact helpdesk.','data'=>'');
  }

  return $response;
}]);

Route::post('placeorder',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $mId      = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantid')->get();
  $mKey     = \App\Settings::select('field_name','field_value')->where('field_name','=','merchantidkey')->get();
  $mHost    = \App\Settings::select('field_name','field_value')->where('field_name','=','dragonpayhost')->get();
  $sUrl     = \App\Settings::select('field_name','field_value')->where('field_name','=','soapurl')->get();
  $taigaurl = \App\Settings::select('field_name','field_value')->where('field_name','=','taigaurl')->get();

  //create settings table
  $merchantId = $mId[0]->field_value;
  $key        = $mKey[0]->field_value; //"po1rJDrRjFFsEUT";//"7n19ooLyfzv94s1";
  $host       = $mHost[0]->field_value; //"gw.dragonpay.ph"; //"test.dragonpay.ph";

  $cartid = isset($_POST['cart_id']) ? $_POST['cart_id'] : ""; //current cart id of the user
  $uid = isset($_POST['uid']) ? $_POST['uid'] : ""; //uid of the user
  $procid = isset($_POST['procid']) ? $_POST['procid'] : ""; //determines payment method
  $amount = isset($_POST['amount']) ? $_POST['amount'] : ""; //total amount
  $desc = isset($_POST['description']) ? $_POST['description'] : "";
  $email = isset($_POST['email']) ? $_POST['email'] : "";
  $notes = isset($_POST['order_notes']) ? $_POST['order_notes'] : ""; //order notes left by the user or simply additional instructions
  $freight_collect = isset($_POST['freight_id']) ? $_POST['freight_id'] : "0";
  //$payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : "";

  $couponcode = isset($_POST['coupon_code']) ? $_POST['coupon_code'] : ""; //add coupon code as additional param
  //also add shipping fee info
  $shippingfee = isset($_POST['shippingfee']) ? $_POST['shippingfee'] : ""; //add shippingfee as additional param
  $ruleshipping="false";

  //this will be temporary be removed
  /*if(!empty($couponcode)){
	  $r = couponverifier($couponcode, $uid);
	  $rr = json_decode($r);

	  //the discounts
	  $shippingdiscount = $rr->result->shipping_discount;
	  $totaldiscount    = $rr->result->total_discount;
	  $percent_cash     = $rr->result->total_unit;
	  //true or false
	  $ruleshipping     = $rr->rules->shipping;
	  $ruletotal        = $rr->rules->total;

	  $discountcash=""; $discountshipping="";
	  //get if rule total is true
	  if($ruletotal=="true"){
		if($percent_cash=="percent"){ //if discount is in percent
		  $discountcash = $amount * ($totaldiscount / 100);
		}
		else{ //if discount is in cash
		  $discountcash = $totaldiscount;
		}

		$amount = $amount - $discountcash;
	  }
  }

  if(!empty($shippingfee)){ //just to make sure the order has a shipping fee
    if($ruleshipping=="true"){
      if($percent_cash=="percent"){ //if discount is in percent
        $discountshipping = $shippingfee * ($shippingdiscount / 100);
      }
      else{ //if discount is in cash
        $discountshipping = $shippingdiscount;
      }

      $shippingfee = $shippingfee - $discountshipping;
    }
  }*/
  //this will be removed

  //should get the amount from param or compute here?
  //total amount of payment should be computed here as well and compared to the submitted amount if equal.
  //confirm required fields are met

  if(!empty($cartid) and !empty($uid) and !empty($procid) and !empty($amount)){
        //fetch and confirm if cart has atleast one or more items active/has item
        $sql_check_items = 'SELECT * FROM t_product_orders WHERE cart_id='.$cartid.' AND quantity>=1 AND status>=1';
        $order_items = DB::select($sql_check_items);
        $no_order = 0;
        foreach($order_items as $oi){
          if(!empty($oi->sku)){
            $no_order++;
          }
        }

        //fetch user id
        $user_id = "";
        $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
        foreach($user as $u){
          if(!empty($u->id)){
            $user_id = $u->id;
          }
        }
        //end of fetching user id

        //fetch address to deliver the orders
        $userinfo = \App\Users_Info::select('unit_st_brgy','municipality_city_id','province_id')
        ->where('users_id','=',$user_id)
        ->where('set_default','=',1)
        ->get();

        $unit_st_brgy = ""; $municipality_city_id=0; $province_id=0;
        foreach($userinfo as $ui){
          if(!empty($ui->unit_st_brgy) and !empty($ui->municipality_city_id) and !empty($ui->province_id)){
            $unit_st_brgy = $ui->unit_st_brgy;
            $municipality_city_id = $ui->municipality_city_id;
            $province_id = $ui->province_id;
          }
        }
        //end of fetching the address to deliver the item/s

        if($no_order>=1){

            //update the status of cart id to 1
            $def_order_no = '100000'+$cartid; //this should be changed later
            $fetchedor = "";
            $orderno = \App\Carts::select('order_no')->orderBy('order_no','DESC')->take(1)->get();
            foreach($orderno as $or){
              if(!empty($or->order_no)){
                $fetchedor=$or->order_no;
              }
            }

            if($fetchedor!="" and $fetchedor>=1){
              $def_order_no = $fetchedor+1;
            }
            else{
              $def_order_no = '100000'+1;
            }

            //make sure address is not empty
            if(!empty($unit_st_brgy) and $municipality_city_id!=0 and $province_id!=0){
                if($procid=="CODT" and $freight_collect==0){ //if payment is COD
                  $updateorder = DB::table('t_carts')
                    ->where('id',$cartid)
                    ->where('users_id',$user_id)
                    ->update(['order_no'=>$def_order_no,
                              'status'=>2,
                              'order_notes'=>$notes,
                              'order_description'=>$desc,
                              'payment_method'=>$procid,
                              'date_time'=> date("Y-m-d h:i:s"),
                              'total_payment' => number_format($amount,2,'.',''),
                              'total_shipping' => $shippingfee,
                              'ship_to_st_brgy'=>$unit_st_brgy,
                              'ship_to_municipality'=>$municipality_city_id,
                              'freight_collect_id'=>$freight_collect,
                              'coupon_code' => $couponcode,
                              'total_shipping' => $shippingfee,
                              'ship_to_province'=>$province_id]);
                }
                elseif($procid!="CODT" and ($freight_collect==0 or $freight_collect>=1)){ //if payment is thru dragonpay
                  $updateorder = DB::table('t_carts')
                    ->where('id',$cartid)
                    ->where('users_id',$user_id)
                    ->update(['order_no'=>$def_order_no,
                              'status'=>1,
                              'order_notes'=>$notes,
                              'order_description'=>$desc,
                              'payment_method'=>$procid,
                              'date_time'=> date("Y-m-d h:i:s"),
                              'total_payment' => number_format($amount,2,'.',''),
                              'total_shipping' => number_format($shippingfee,2,'.',''),
                              'ship_to_st_brgy'=>$unit_st_brgy,
                              'ship_to_municipality'=>$municipality_city_id,
                              'freight_collect_id'=>$freight_collect,
                              'coupon_code' => $couponcode,
                              'total_shipping' => $shippingfee,
                              'ship_to_province'=>$province_id]);
                }

                $url = $taigaurl[0]->field_value.$def_order_no; //"https://taiga.com.ph/confirm?txnid=".$def_order_no;//used for dragonpay method

                if($procid!='CODT'){
                  //generate url for dragon pay
                  $params = array(
                        'merchantid' => $merchantId,
                        'txnid'      => $def_order_no,
                        'amount'     => number_format($amount,2,'.',''),
                        'ccy'        => 'PHP',
                        'description'=> $desc,
                        'email'      => $email,
                        'key'        => $key //'po1rJDrRjFFsEUT'//'7n19ooLyfzv94s1'
                     );

                   $digest = implode(':',$params);
                   unset($params['key']);

                   $params['digest'] = sha1($digest);
                   $url = 'https://gw.dragonpay.ph/Pay.aspx?'.http_build_query($params).'&procid='.$procid;
                }

                 $response = array('status'=>'success','message'=>'Successfully submitted cart for order.','data'=>array('txnid'=>$def_order_no,'url'=>$url));
            }
            else{
                $response = array('status'=>'error','message'=>'Default address is required to proceed.','data'=>'');
            }

        }
        else{
          $response = array('status'=>'error','message'=>'Cart is empty.','data'=>'');
        }
  }
  else{
      //should have error logs here to be saved
      $response = array('status'=>'error','message'=>'Insufficient parameters error occured. Kindly contact helpdesk.','data'=>'');
  }

  return $response;
}]);

Route::get('getamountbyorderno',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $orderno = isset($_GET['orderno']) ? $_GET['orderno'] : "";

  $response = array('status'=>'error','message'=>'No amount fetched.','data'=>'');
  if(!empty($orderno)){
    $data = \App\Carts::select('total_payment')->where('order_no','=',$orderno)->get();
    $response = array('status'=>'success','message'=>'Successfully fetched total amount','data'=>$data);
  }

  return $response;
}]);

Route::get('cartlist',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";

  //get user id using uid
  //get id of user
  $user_id = "";
  $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
  foreach($user as $u){
    if(!empty($u->id)){
      $user_id = $u->id;
    }
  }


  $carts = \App\Carts::select('id')->where('users_id','=',$user_id)->where('status','=','0')->get();
  $cart_id = "";
  foreach($carts as $c){
    if(!empty($c->id)){
      $cart_id = $c->id;
    }
  }

  $response = array('status'=>'norecord','message'=>'No cartlist yet.','data'=>'');
  $totalproductprice=0;
  //query all from product order where cart id condition
  if($cart_id!=""){
    $sql = 'SELECT
    t_products.id,
    concat(t_products.name," ",IFNULL(t_brands.brand,"")) as productname,
    t_product_orders.sku,
    t_product_orders.quantity,
    t_product_orders.price as orderedprice,
    t_variations.price,
	(t_product_orders.price * t_product_orders.quantity) as product_order_total,
    t_variations.images,
    t_products.shipping_ncr,
    t_products.shipping_luzon,
    t_products.shipping_visayas,
    t_products.shipping_mindanao,
    t_categories.id as categoryid
    FROM t_product_orders
    JOIN t_variations ON t_variations.sku=t_product_orders.sku
    JOIN t_attributes ON t_attributes.id=t_variations.attribute_id
    JOIN t_products   ON t_products.id=t_attributes.product_id
    JOIN t_categories ON t_categories.id=t_products.category_id
    LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
    WHERE t_product_orders.cart_id='.$cart_id.' AND t_product_orders.status=1';

    $cart_products = DB::select($sql);

    $products = array(); $a=0;
    foreach($cart_products as $cp){

      if(!empty($cp->id)){
        //fetch variations by product id
        $sql_str = 'SELECT t_attributes.id,t_attributes.product_id,t_attributes.value,t_variations.sku
        FROM t_attributes
        JOIN t_variations ON t_variations.attribute_id=t_attributes.id
        WHERE t_attributes.product_id='.$cp->id.'
        ORDER BY cast(t_variations.price as unsigned) ASC, t_attributes.id ASC';
        $variations = DB::select($sql_str);
        $b=0;$variation_index=0;$variation="";
        foreach($variations as $v){
          //if equal to $cp->sku, then get variation index
          if($v->sku==$cp->sku){
            $variation_index = $b;
            $variation = $v->value;
          }

          $b++;
        }


        $prod_id = taiga_crypt($cp->id,'e');
        //explode images
        $img = explode(",",$cp->images);

        $products[$a] = array(
          'variation'=>$variation,
          'variation_index'=>$variation_index,
          'id'=>$prod_id,
          'productname'=>$cp->productname,
          'sku'=>$cp->sku,
          'quantity'=>$cp->quantity,
          'price'=>$cp->orderedprice,
          'images'=>$img[0],
          'shipping_ncr'=>$cp->shipping_ncr,
          'shipping_luzon'=>$cp->shipping_luzon,
          'shipping_visayas'=>$cp->shipping_visayas,
          'shipping_mindanao'=>$cp->shipping_mindanao
        );

		$qtymobile = $cp->quantity;
		$pricemobile = str_replace(",","",$cp->orderedprice);

		$totalproductprice += $qtymobile * $pricemobile;
        $a++;
      }

	  //newly added as per mhon today sept-14-2019 needed for mobile
	  //$totalproductprice += $cp->product_order_total;
    }

    $totalproductprice = number_format(round($totalproductprice,0),2);

    $response = array('status'=>'success','message'=>'Listing cart products.','cartid'=>$cart_id,'currenttotalprice'=>$totalproductprice,'data'=>$products);
  }

  return $response;
}]);

Route::get('removefromcart',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

    $sku = isset($_GET['sku']) ? $_GET['sku'] : "";
    $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
    $cart_id = isset($_GET['cart_id']) ? $_GET['cart_id'] : "";

    //get user id using uid
    $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
    $user_id="";
    foreach($user as $u){
      if(!empty($u->id)){
        $user_id = $u->id;
      }
    }

    //verify the cart belongs to uid
    $confirmed_cart_id="";
    if(!empty($user_id)){
      $carts = \App\Carts::select('id')->where('users_id','=',$user_id)->where('id','=',$cart_id)->where('status','=','0')->get();
      foreach($carts as $c){
        if(!empty($c->id) and $c->id==$cart_id){
          $confirmed_cart_id = $c->id;
        }
      }
    }

    //update sku from cart set status to 0
    if($confirmed_cart_id!=""){
      $remove_sku = DB::table('t_product_orders')
        ->where('sku',$sku)
        ->where('cart_id',$confirmed_cart_id)
        ->where('status',1)
        ->update(['status'=>0]);

      $response = array('status'=>'success','message'=>'Successfully removed the item from cart.','data'=>'');
    }
    else{
      $response = array('status'=>'error','message'=>'No update on item has been made.','data'=>'');
    }


    return $response;
}]);

/*Route::get('updateaddress',['middleware'=>'cors',function(){

}]);*/

Route::get('listaddress',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";

    //returns list of address of user
    if(!empty($uid)){
      $sql = 'SELECT id FROM users WHERE remember_token="'.$uid.'"';
      $user_id_res = DB::select($sql);

      $user_id="";
      foreach($user_id_res as $ui){
        if(!empty($ui->id)){
          $user_id=$ui->id;
        }
      }

      if(!empty($user_id)){
        $sql = 'SELECT
        t_users_info.id,
        t_users_info.mobile_no,
        t_users_info.users_id,
        t_users_info.address_name,
        t_users_info.unit_st_brgy,
        t_users_info.set_default,
        t_municipality_city.municipality_city,
        t_province.province
        FROM t_users_info
        JOIN users ON users.id=t_users_info.users_id
        JOIN t_municipality_city ON t_municipality_city.id=t_users_info.municipality_city_id
        JOIN t_province ON t_province.id=t_users_info.province_id
        WHERE users.remember_token="'.$uid.'" AND t_users_info.set_default<>2';

        $user_address = DB::select($sql);

        $no_address="true";
        foreach($user_address as $ua){
          if(!empty($ua)){
            $no_address="false";
          }
        }

        if($no_address=="true"){
          $response = array('status'=>'error','message'=>'No address has been registered.','data'=>'');
        }
        else{
          $response = array('status'=>'success','message'=>'Listing the addresses.','data'=>$user_address);
        }

      }
      else{
        $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
      }
    }
    else{
      $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
    }

    return $response;
}]);

Route::get('setdefaultaddressmobile',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
  $addid = isset($_GET['addid']) ? $_GET['addid'] : "";

  if(!empty($uid) and !empty($addid)){
    if(!empty($uid) and !empty($addid)){
      $sql = 'SELECT id FROM users WHERE remember_token="'.$uid.'"';
      $user_id_res = DB::select($sql);

      $user_id="";
      foreach($user_id_res as $ui){
        if(!empty($ui->id)){
          $user_id=$ui->id;

          //remove default address first
          $remove_default = DB::table('t_users_info')
            ->where('users_id',$user_id)
            ->where('set_default',1)
            ->update(['set_default'=>0]);

          //set default address
          $set_default = DB::table('t_users_info')
            ->where('users_id',$user_id)
            ->where('id',$addid)
            ->update(['set_default'=>1]);

          $response = array('status'=>'success','message'=>'Successfully set as the default address.','data'=>'');
        }
        else{
          $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
        }
      }
    }
    else{
      $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
    }
  }
  else{
    $response = array('status'=>'error','message'=>'Incorrect parameters.','data'=>'');
  }

  return $response;
}]);

Route::post('setdefaultaddress',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $uid = isset($_POST['uid']) ? $_POST['uid'] : "";
  $addid = isset($_POST['addid']) ? $_POST['addid'] : "";

  if(!empty($uid) and !empty($addid)){
    $sql = 'SELECT id FROM users WHERE remember_token="'.$uid.'"';
    $user_id_res = DB::select($sql);

    $user_id="";
    foreach($user_id_res as $ui){
      if(!empty($ui->id)){
        $user_id=$ui->id;

        //remove default address first
        $remove_default = DB::table('t_users_info')
          ->where('users_id',$user_id)
          ->where('set_default',1)
          ->update(['set_default'=>0]);

        //set default address
        $set_default = DB::table('t_users_info')
          ->where('users_id',$user_id)
          ->where('id',$addid)
          ->update(['set_default'=>1]);

        $response = array('status'=>'success','message'=>'Successfully set as the default address.','data'=>'');
      }
      else{
        $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
      }
    }
  }
  else{
    $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
  }

  return $response;
}]);

Route::get('removeaddress',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
  $addid = isset($_GET['addid']) ? $_GET['addid'] : "";

    if(!empty($uid) and !empty($addid)){
      $sql = 'SELECT id FROM users WHERE remember_token="'.$uid.'"';
      $user_id_res = DB::select($sql);

      $user_id="";
      foreach($user_id_res as $ui){
        if(!empty($ui->id)){
          $user_id=$ui->id;

          $sqladd = 'SELECT id,users_id FROM t_users_info WHERE users_id='.$user_id.' and id='.$addid;
          $user_add_res = DB::select($sqladd);

          foreach($user_add_res as $uas){
            if(!empty($uas->id) and !empty($uas->users_id)){
              //update query set set_default=2
              $remove_sku = DB::table('t_users_info')
                ->where('users_id',$user_id)
                ->where('id',$addid)
                ->update(['set_default'=>2]);

                $response = array('status'=>'success','message'=>'Successfully removed the address.','data'=>'');
            }
          }
        }
        else{
          $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
        }
      }

    }
    else{
      $response = array('status'=>'error','message'=>'User does not exist.','data'=>'');
    }

    return $response;
}]);

Route::get('newaddressmobile',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

    $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
    $add_name = isset($_GET['addname']) ? $_GET['addname'] : "";
    $unit_st_brgy = isset($_GET['usb']) ? $_GET['usb'] : "";
    $municipality = isset($_GET['municipality']) ? $_GET['municipality'] : "";
    $province = isset($_GET['province']) ? $_GET['province'] : "";
    $mobile = isset($_GET['mobile']) ? $_GET['mobile'] : "";
    $has_existing = "false"; $default_address = 1;

    if(!empty($unit_st_brgy) and $municipality>0 and is_numeric($municipality) and $province>0 and is_numeric($province)){

      //check if mobile number not empty
      if(!empty($mobile)){

          //check if municipality exists and province exists
          $municipality_exists = "false"; $province_exists = "false";
          $checkmunicipality = \App\Municipalities::select('id','municipality_city')->where('id','=',$municipality)->get();
          foreach ($checkmunicipality as $cm) {
            if(!empty($cm->municipality_city)){
              $municipality_exists = "true";
            }
          }

          $checkprovince = \App\Province::select('id','province')->where('id','=',$province)->get();
          foreach ($checkprovince as $cp) {
            if(!empty($cp->province)){
              $province_exists = "true";
            }
          }

          if($municipality_exists=="true" and $province_exists=="true"){

            //get id of user
            $user_id = "";
            $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
            foreach($user as $u){
              if(!empty($u->id)){
                $user_id = $u->id;
              }
            }

            if(!empty($user_id)){

              //check if user has already address and has default address
              $usersinfo = \App\Users_Info::select('id','set_default')->where('users_id','=',$user_id)->get();
              $c = 0; //counts the number of address the user has
              foreach($usersinfo as $ui){
                if(!empty($ui->id)){
                  $c++;
                  if($ui->set_default==1){
                    $default_address=0;
                  }
                }
              }

              //if($c>=3){
              //  $response = array('status'=>'error','message'=>'The number of address has reached its limit.');
              //}

              $ui = new Users_Info;
              $ui->users_id = $user_id;
              $ui->mobile_no = $mobile;
              $ui->address_name = $add_name;
              $ui->unit_st_brgy = $unit_st_brgy;
              $ui->municipality_city_id = $municipality;
              $ui->province_id = $province;
              $ui->set_default = $default_address;
              $ui->save();

              if(!empty($ui->id)){
                $response = array('status'=>'success','message'=>'Successfully added new address.');
              }
              else{
                $response = array('status'=>'error','message'=>'Unable to add new address. Kindly contact our helpdesk.');
              }

            }
            else{
              $response = array('status'=>'error','message'=>'User does not exists.');
            }

          }
          else{
            $response = array('status'=>'error','message'=>'Incorrect format of address. Municipality/Province does not exists.');
          }

      }
      else{
        $response = array('status'=>'error','message'=>'Mobile number is required.');
      }
    }else{
      $response = array('status'=>'error','message'=>'Incorrect format of address. Municipality, Street / Brgy, and Province.');
    }

    return $response;
}]);

Route::post('newaddress',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

    $uid = isset($_POST['uid']) ? $_POST['uid'] : "";
    $add_name = isset($_POST['addname']) ? $_POST['addname'] : "";
    $unit_st_brgy = isset($_POST['usb']) ? $_POST['usb'] : "";
    $municipality = isset($_POST['municipality']) ? $_POST['municipality'] : "";
    $province = isset($_POST['province']) ? $_POST['province'] : "";
    $mobile = isset($_POST['mobile']) ? $_POST['mobile'] : "";
    $has_existing = "false"; $default_address = 1;

    if(!empty($unit_st_brgy) and $municipality>0 and is_numeric($municipality) and $province>0 and is_numeric($province)){

      //check if mobile number not empty
      if(!empty($mobile)){

          //check if municipality exists and province exists
          $municipality_exists = "false"; $province_exists = "false";
          $checkmunicipality = \App\Municipalities::select('id','municipality_city')->where('id','=',$municipality)->get();
          foreach ($checkmunicipality as $cm) {
            if(!empty($cm->municipality_city)){
              $municipality_exists = "true";
            }
          }

          $checkprovince = \App\Province::select('id','province')->where('id','=',$province)->get();
          foreach ($checkprovince as $cp) {
            if(!empty($cp->province)){
              $province_exists = "true";
            }
          }

          if($municipality_exists=="true" and $province_exists=="true"){

            //get id of user
            $user_id = "";
            $user = \App\User::select('id')->where('remember_token','=',$uid)->get();
            foreach($user as $u){
              if(!empty($u->id)){
                $user_id = $u->id;
              }
            }

            if(!empty($user_id)){

              //check if user has already address and has default address
              $usersinfo = \App\Users_Info::select('id','set_default')->where('users_id','=',$user_id)->get();
              $c = 0; //counts the number of address the user has
              foreach($usersinfo as $ui){
                if(!empty($ui->id)){
                  $c++;
                  if($ui->set_default==1){
                    $default_address=0;
                  }
                }
              }

              //if($c>=3){
              //  $response = array('status'=>'error','message'=>'The number of address has reached its limit.');
              //}

              $ui = new Users_Info;
              $ui->users_id = $user_id;
              $ui->mobile_no = $mobile;
              $ui->address_name = $add_name;
              $ui->unit_st_brgy = $unit_st_brgy;
              $ui->municipality_city_id = $municipality;
              $ui->province_id = $province;
              $ui->set_default = $default_address;
              $ui->save();

              if(!empty($ui->id)){
                $response = array('status'=>'success','message'=>'Successfully added new address.');
              }
              else{
                $response = array('status'=>'error','message'=>'Unable to add new address. Kindly contact our helpdesk.');
              }

            }
            else{
              $response = array('status'=>'error','message'=>'User does not exists.');
            }

          }
          else{
            $response = array('status'=>'error','message'=>'Incorrect format of address.');
          }

      }
      else{
        $response = array('status'=>'error','message'=>'Mobile number is required.');
      }
    }else{
      $response = array('status'=>'error','message'=>'Incorrect format of address.');
    }

    return $response;
}]);
//create related products api with product id parameter
Route::get('getrelatedproducts',['middleware'=>'cors',function(){
    date_default_timezone_set("Asia/Singapore");
    $product_id = isset($_GET['product_id']) ? $_GET['product_id'] : "";
    //$product_list = "";
    $product_id = taiga_crypt($product_id,'d');
    $keyword = App\Products::select('name')->where('id', $product_id)->first()->name;
    $search_arr = [];
    foreach(explode(' ',$keyword) as $item) {
      array_push($search_arr, $item);
    }
    $m = $search_arr[0].' '.$search_arr[1];
    $search_query = 't_products.name LIKE "%'. $m .'%"';
    // $temp_search_query = '';
    // foreach(array_slice($search_arr, 0, 3) as $item) {
    //   $temp_search_query .= " t_products.name LIKE '%$item%' OR";
    // }
    // $search_query = substr($temp_search_query, 0, -3);

    $sql = 'SELECT
        t_brands.brand,
        concat(IFNULL(t_brands.brand,"")," ",t_products.name) as name,
        t_products.id,
        t_categories.category,
        t_categories.id as categoryid,
        t_variations.application,
        t_variations.retail_price,
        t_variations.price,
        t_variations.images
        FROM t_products
        LEFT JOIN t_brands ON t_products.brand_id=t_brands.id
        JOIN t_categories ON t_categories.id=t_products.category_id
        JOIN t_attributes ON t_attributes.product_id=t_products.id
        JOIN t_variations ON t_variations.attribute_id=t_attributes.id
        WHERE '. $search_query .'
        AND t_products.status<>0
        ORDER BY RAND ()
        LIMIT 18';
      $result = DB::select($sql);
      $prod_arrz = array(); $a=0; $ProductDetails = array();$no_of_prod=0; $prod_ids = array();
      $onsale = false; $addsale = 0;
      foreach($result as $r){
        $saleresponse = checkifsale($r->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
        if(!empty($saleresponse)){
          //get the additional discount, this is to be added on product savings
          foreach($saleresponse as $sr){
            if(!empty($sr->percentage) and $sr->percentage!=0){
              $addsale = $sr->percentage;
              $onsale = true;
            }
          }
        }

        if(!empty($r->id)){
          $no_of_prod++;
          $prod_ids[$a] = $r->id;
        }

        $retail_price = str_replace(",","",$r->retail_price);
        $price = str_replace(",","",$r->price);

        $difference = (double)$retail_price - (double)$price;
        $savings = 0;
        $retail_price = (integer)$retail_price;
        if($retail_price>0){
            $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
        }

        $savings = round($savings,0);

        //=====================for sale purposes=================
        $productprice = round(trim($r->price),0);
        $totalsavings = $savings;
        $srp = round(trim($r->retail_price),0);

        if($onsale==true){
          $price = round(trim($r->price),0);
          $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
          $additionaldisc = round($salepercent * $price,0);
          $productprice = $price - $additionaldisc;
          $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
        }
        //=====================for sale purposes=================

        $prod_id = taiga_crypt($r->id,'e'); //encrypt the product id
        $brand = trim($r->brand);
        if(!empty($brand)){
          $prod_n = $brand.' '.$r->name;
        }
        else{
          $prod_n = $r->name;
        }

        $prod_arrz[$a] = array(
          'name' => $m,
          'Status'=>'1',
          'Message'=>'Returned result set.',
          'ProductID'=>taiga_crypt($product_id, 'e'),
          'ProductName'=>$r->name,
          'Application'=>$r->application,
          'OnSale'=>$onsale,
          'RetailPrice'=>number_format($srp,2),
          'Price'=>number_format($productprice,2),
          'Savings'=>$totalsavings,
          'Images'=>$r->images
        );
        $a++;
        $addsale=0;
      }
      return $prod_arrz;
}]);

Route::get('productsbybrand', ['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $id = isset($_GET['brand_id']) ? $_GET['brand_id'] : "";
  $id = taiga_crypt($id,'d'); //decrypt the product id

  $prodbybrand = \App\Products::where('brand_id',$id)->get();

  return $prodbybrand;
}]);

Route::get('allcategoriesmobile', ['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $categories = \App\Categories::select('id','category','slug')->orderBy('category','ASC')->get();
  $cat_arr = array(); $a=0;
    // $r = array('category'=>);
    $no_products = array();$c=array();
    foreach($categories as $cats){
        //get number of products under the category
        /*$query_str = 'SELECT count(*) AS product_count
                      FROM t_products
                      WHERE category_id='.$cats->id.' AND status<>0';
        $product_count = DB::select($query_str);
        foreach($product_count as $pc){
          $prod_count_cat = $pc->product_count;
        }*/

        $subcats = \App\Sub_Category::where('category_id',$cats->id)->get();
        $new_subcat = array();
        $b=0;$total=0;
        foreach($subcats as $sc){
            /*$no_products[$b] = \App\Products::where('sub_category_id',$sc->id)->get();
            $c[$b] = count($no_products[$b]); //count number of products under that sub category
            */

            $encrypted_sub_id = taiga_crypt($sc->id,'e');
            $new_subcat[$b] = array('id'=>$encrypted_sub_id,'sub_category'=>$sc->sub_category,'slug'=>$sc->slug);

            //$total += $c[$b];
            $b++;
        }

        $encrypted_cat_id = taiga_crypt($cats->id,'e');
        $cat_arr['Categories'][$a] = array('Category'=>$cats->category,'id'=>$encrypted_cat_id,'slug'=>$cats->slug,'SubCategories'=>$new_subcat);

     $a++;
    }

    return $cat_arr;
}]);

Route::get('allcategories', ['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $categories = \App\Categories::select('id','category','slug')->orderBy('category','ASC')->get();
  $cat_arr = array(); $a=0;
    // $r = array('category'=>);
    $no_products = array();$c=array();
    foreach($categories as $cats){
        $subcats = \App\Sub_Category::select('id','sub_category', 'slug')->where('category_id',$cats->id)->get();
        $new_subcat = array();
        $b=0;
        foreach($subcats as $sc){

            $encrypted_sub_id = taiga_crypt($sc->id,'e');
            $new_subcat[$b] = array('id'=>$encrypted_sub_id,'sub_category'=>$sc->sub_category,'slug'=>$sc->slug);
            $b++;
        }
        $encrypted_cat_id = taiga_crypt($cats->id,'e');
        $cat_arr['Categories'][$a] = array('Category'=>$cats->category,'id'=>$encrypted_cat_id,'SubCategories'=>$new_subcat,'slug'=>$cats->slug);
     $a++;
    }

    return $cat_arr;
}]);

Route::get('motorcycleproductdetails', ['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

      $pid = isset($_GET['pid']) ? $_GET['pid'] : "";
      $id = taiga_crypt($pid,'d'); //decrypt the product id
      $producthits=0;
      //should add result if no record found

      $product = \App\Products::where('id',$id)->get();

      //get if product has terms of payment
      $product_terms = array();

      $sql = 'SELECT
              t_store_products.id,
              t_store_products.terms_id,
              t_store_products.store_id,
              t_stores.store,
              t_stores.vendor_code,
              t_store_products.price,
              t_store_products.stock_allocation,
              t_store_products.monthly,
              t_store_products.monthly2,
              t_store_products.monthly3,
              t_store_products.monthly4,
              t_store_products.monthly5,
              t_store_products.downpayment,
              t_store_products.downpayment2,
              t_store_products.downpayment3,
              t_store_products.downpayment4,
              t_store_products.downpayment5,
              t_store_products.promo,
              t_store_products.promo_image,
              t_submitted_products.prod_type,
              t_submitted_products.brand_name,
              t_submitted_products.product_name,
              t_submitted_products.short_description,
              t_submitted_products.description,
              t_submitted_products.image,
              t_submitted_products.color,
              t_submitted_products.product_condition,
              t_submitted_products.year_model
              FROM t_store_products
              INNER JOIN t_submitted_products ON t_submitted_products.id=t_store_products.product_id
              INNER JOIN t_stores ON t_stores.id=t_store_products.store_id
              WHERE t_store_products.id='.$id;

      $result = DB::select($sql);

      // //$terms_arr = explode(",",$result[0]->terms_id);
      $product_info = array();
      if(!empty($result)){

        $sql_monthlyterms = 'SELECT term FROM t_terms_main WHERE id IN('.$result[0]->terms_id.')';
        $res = DB::select($sql_monthlyterms);

        //print_r($res);

        $terms = array(); $a=0;
        foreach($res as $r){
          if($r->term==12){
            $terms[$a]['monthly']=$result[0]->monthly;
            $terms[$a]['months']="12";
            $terms[$a]['down']=$result[0]->downpayment;
          }
          elseif($r->term==18){
            $terms[$a]['monthly']=$result[0]->monthly2;
            $terms[$a]['months']="18";
            $terms[$a]['down']=$result[0]->downpayment2;
          }
          elseif($r->term==24){
            $terms[$a]['monthly']=$result[0]->monthly3;
            $terms[$a]['months']="24";
            $terms[$a]['down']=$result[0]->downpayment3;
          }
          elseif($r->term==30){
            $terms[$a]['monthly']=$result[0]->monthly4;
            $terms[$a]['months']="30";
            $terms[$a]['down']=$result[0]->downpayment4;
          }
          elseif($r->term==36){
            $terms[$a]['monthly']=$result[0]->monthly5;
            $terms[$a]['months']="36";
            $terms[$a]['down']=$result[0]->downpayment5;
          }

          $a++;
        }
        $brand = '';
        $get_brandname = App\MotorcycleBrands::where('id', $result[0]->brand_name)->first();

        if(!empty($get_brandname->brand)) {
          $brand = $get_brandname->brand;
        } else {
          $brand = $result[0]->brand_name;
        }
        // $parent_vendor = DB::table('t_vendors')::where('store_id', $result[0]->store_id)->first()->vendor_code;
        $parent_vendor = DB::table('users')
                          ->join('t_stores', 't_stores.user_id', '=', 'users.id')
                          ->select('users.vendor_code')
                          ->first()->vendor_code;

        if(!empty($result)){
          $product_info['id'] = $result[0]->id;
          $product_info['name'] = $result[0]->product_name;
          $product_info['brand'] = $brand;
          $product_info['settings'] = (App\ApplicationSettings::where('vendor_code', $parent_vendor)->first()->settings) ? App\ApplicationSettings::where('vendor_code', $parent_vendor)->first()->settings : 1 ;
          $product_info['vendor_code'] = $result[0]->vendor_code;
          $product_info['stocks'] = $result[0]->stock_allocation;
          $product_info['store_id'] = $result[0]->store_id;
          $product_info['store_name'] = $result[0]->store;
          $product_info['price'] = $result[0]->price;
          $product_info['type'] = $result[0]->prod_type;
          $product_info['image'] = $result[0]->image;
          $product_info['color'] = $result[0]->color;
          $product_info['promo'] = $result[0]->promo;
          $product_info['promo_image'] = $result[0]->promo_image;
          $product_info['terms'] = $terms;
          $product_info['year_model'] = $result[0]->year_model;
          $product_info['product_condition'] = $result[0]->product_condition;
          $product_info['short_description'] = $result[0]->short_description;
          $product_info['main_email'] = App\User::where([['vendor_code',$parent_vendor], ['store_id', null]])->first()->email;
          $product_info['branch_email'] = (App\User::where('store_id',$result[0]->store_id)->first()) ? App\User::where('store_id',$result[0]->store_id)->first()->email : '';
        }
      }

    return $product_info;

}]);

Route::get('productdetails', ['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

      $pid = isset($_GET['pid']) ? $_GET['pid'] : "";
      //$id = $pid; //temporary
      $id = taiga_crypt($pid,'d'); //decrypt the product id
      $producthits=0;
      //should add result if no record found

      $product = \App\Products::where('id',$id)->get();

      $brand_name = "";
      //added below variables for determining sales
      $prodcategory = ""; $prodcategoryid = ""; $addsale = 0; $onsale = false;
      foreach($product as $p){
          $prodcategoryid = $p->category_id;

          //query here to get if product is on sale
          //this is only applicable for sale by category only, needs to be updated once available on sale per product
          if(!empty($prodcategoryid) and $prodcategoryid>=1){ //making sure category id is not empty
            $saleresponse = checkifsale($id, date('Y-m-d'), date('Y-m-d'), $i = 'product');

            if(!empty($saleresponse)){
              //get the additional discount, this is to be added on product savings
              foreach($saleresponse as $sr){
                if(!empty($sr->percentage) and $sr->percentage!=0){
                  $addsale = $sr->percentage;
                  $onsale = true;
                }
              }
            }
          }

          if(!empty($p->hits)){
            $producthits=$p->hits;
          }

          $brand = \App\Brands::where('id',$p->brand_id)->get();
          foreach($brand as $b){
            if(!empty($b->brand)){
              $brand_name = $b->brand;
            }
          }
      }

      //get if product has terms of payment
      $product_terms = array();
      $sql_terms = 'SELECT
              t_terms.id,
              t_terms.terms_id,
              t_terms_main.term,
              t_terms.downpayment,
              t_terms.monthly
              FROM t_terms
              JOIN t_terms_main ON t_terms_main.id=t_terms.terms_id
              WHERE product_id='.$id.'
              ORDER BY t_terms.terms_id ASC';
      $terms_result = DB::select($sql_terms);
      //id, product id, terms id, downpayment, amount_monthly, created_at, updated_at
      //id, term, created_at, updated_at


      $z=0;
      foreach($terms_result as $tr){
        if(!empty($tr->id)){
          $product_terms[$z] = array(
            'term_id'=>$tr->terms_id,
            'term'=>$tr->term,
            'downpayment'=>$tr->downpayment,
            'monthly'=>$tr->monthly);

          $z++;
        }
      }

      //$prodattributes = \App\Attributes::where('product_id',$id)->get();
      $sql_attr = 'SELECT
      t_attributes.id,
      t_attributes.product_id,
      t_attributes.attribute,
      t_attributes.value
      FROM t_attributes
      JOIN t_variations ON t_variations.attribute_id=t_attributes.id
      WHERE t_attributes.product_id='.$id.'
      ORDER BY CAST(t_variations.price as unsigned) ASC';
      $prodattributes = DB::select($sql_attr);

      $product_info=array();$a=0;$product_infos = array();$retailprice=0;$price=0;
      foreach($prodattributes as $pa){ //get variations
          $variation = !empty($pa->attribute) ? $pa->attribute : "";
          $variation_value = !empty($pa->value) ? $pa->value : "";

              $product_info = \App\Variations::select(
                  'prod_type',
                  'sku',
                  'part_no',
                  'made_in',
                  'short_description',
                  'description',
                  'application',
                  'cost',
                  'retail_price',
                  'price',
                  'discount',
                  'stocks',
                  'in_stock',
                  'unit',
                  'length',
                  'width',
                  'height',
                  'weight',
                  'images')->where('attribute_id',$pa->id)->get();

                foreach($product_info as $pi){
                  if(!empty($pi->sku)){
                    //$productprice = $pi->price;
                    $productprice = round(trim($pi->price),0);
                    $currentdifference = ($pi->retail_price - $productprice);
                    $currentpercent = round(($currentdifference / $pi->retail_price) * 100,0);
                    $totalpercent = $currentpercent;

                    $totalsavings = $totalpercent;
                    $srp = round(trim($pi->retail_price),0);

                    if($onsale==true){
                      $price = round(trim($pi->price),0);
                      $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
                      $additionaldisc = round($salepercent * $price,0);
                      $productprice = $price - $additionaldisc;
                      $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
                    }

                    //subtract the sale if there is a sale
                    /*if($addsale>=1){
                      $totalpercent = $currentpercent + $addsale;
                      $percentage = ".".$totalpercent;
                      $tosubtract = $percentage * $pi->retail_price;
                      $productprice = $pi->retail_price - $tosubtract;
                    }

                    $retailprice = $pi->retail_price; //number_format(round($pi->retail_price,0),2);
                    $price = $productprice; //number_format(round($productprice,0),2);
                    */

                      $product_infos[$a] = array(
                        'variation_index'=>$a,
                        'variation'=>$variation_value,
                        'prod_type'=>$pi->prod_type,
                        'sku'=>$pi->sku,
                        'part_no'=>$pi->part_no,
                        'made_in'=>$pi->made_in,
                        'short_description'=>$pi->short_description,
                        'description'=>$pi->description,
                        'application'=>$pi->application,
                        'retail_price'=>number_format($srp,2),
                        'price'=>number_format($productprice,2),
                        'discount'=>$pi->discount,
                        'stocks'=>$pi->stocks,
                        'in_stock'=>$pi->in_stock,
                        'unit'=>$pi->unit,
                        'length'=>$pi->length,
                        'width'=>$pi->width,
                        'height'=>$pi->height,
                        'weight'=>$pi->weight,
                        'images'=>$pi->images);
                  }

                }
          $a++;
      }



        //check if has related products
        /*$tag = \App\Tags::where('product_id',$id)->orderBy('tag_ranking','ASC')->take(1)->get();
        $is_tag="";
        foreach($tag as $t){
          if(!empty($t->tag)) $is_tag = $t->tag;
        }

        //fetch product ids from tags table having the same tag
        if(!empty($is_tag)){
        $related_products = DB::table('t_tags')
              ->select('t_tags.product_id',
              't_products.name',
              't_variations.sku',
              't_variations.prod_type',
              't_variations.part_no',
              't_variations.short_description',
              't_variations.description',
              't_variations.application',
              't_variations.cost',
              't_variations.retail_price',
              't_variations.price',
              't_variations.stocks',
              't_variations.in_stock',
              't_variations.images')
              ->where('t_tags.tag','like','%'.$is_tag.'%')
              ->where('t_tags.product_id','<>',$id)
              ->join('t_products','t_products.id','=','t_tags.product_id')
              ->join('t_attributes','t_attributes.product_id','=','t_products.id')
              ->join('t_variations','t_variations.attribute_id','=','t_attributes.id')
              ->groupBy('t_tags.product_id')
              ->take(15)
              ->get();
        }
        else{
          $related_products="";
        }

        $related_products="";

        $rp = '0';
        if(!empty($related_products)){
          $rp = count($related_products);
        }*/
        $rp=0;//number of related products

        //add $p->shipping_ncr

        $result = array(
          'Terms' => $product_terms,
          'Type'=>$p->type,
          'RelatedProducts'=>$rp,
          'ProductName'=>$brand_name.' '.$p->name,
          'ProductBrand'=>$brand_name,
          'ProductId'=>$p->id,
          'VendorCode'=>$p->vendor_code,
          'ShippingNCR'=>$p->shipping_ncr,
          'OnSale'=>$onsale,
          /*'SaleOff'=>$addsale,*/
          'TotalSavings'=>$totalsavings,
          'VariationBy'=>ucfirst($variation),
          'Variations'=>$product_infos);

        //update product hits
        //$id
        //get hits first
        //update hits
        /*$producthits = $producthits+1;
        $updatehits = DB::table('t_products')
          ->where('id',$id)
          ->update(['hits'=>$producthits]);*/


        return $result;
}]);

Route::get('productsbycategory', ['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $id = !empty($_GET['cid']) ? $_GET['cid'] : "";
  $prod_arr = array('status'=>'error','message'=>'Nothing to fetch.');

    if(!empty($id)){
      $id = taiga_crypt($id,'d');
      $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
      $perPage = 25; //set number of records per page

      //query to fetch all needed infos
      //should add to condition product stocks
      $sql='SELECT
            t_products.id,
            t_products.name,
            t_brands.brand,
            t_categories.category,
            t_categories.id as categoryid,
            t_variations.application,
            t_variations.prod_type,
            t_variations.retail_price,
            t_variations.price,
            t_variations.images
            FROM t_products
            LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
            JOIN t_categories ON t_categories.id=t_products.category_id
            JOIN t_attributes ON t_attributes.product_id=t_products.id
            JOIN t_variations ON t_variations.attribute_id=t_attributes.id
            WHERE t_products.category_id='.$id.' AND t_products.status<>0
            GROUP BY t_products.id
            ORDER BY RAND()';

      $result = DB::select($sql);
      $prod_arr = array();$ProductDetails=array();$a=0;
      $onsale = false; $addsale = 0;
      foreach($result as $r){

        $saleresponse = checkifsale($r->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
        if(!empty($saleresponse)){
          //get the additional discount, this is to be added on product savings
          foreach($saleresponse as $sr){
            if(!empty($sr->percentage) and $sr->percentage!=0){
              $addsale = $sr->percentage;
              $onsale = true;
            }
          }
        }

        $product_id = taiga_crypt($r->id,'e');

        $difference = (double)$r->retail_price - (double)$r->price;
        $retail_price = (integer)$r->retail_price;

        $savings = 0;
        if($retail_price>0 and $difference>0){
            $savings = ($difference / $retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
        }

        $savings = round($savings,0);

        //===============================
        $productprice = round(trim($r->price),0);
        $totalsavings = $savings;
        $srp = round(trim($r->retail_price),0);

        if($onsale==true){
          $price = round(trim($r->price),0);
          $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
          $additionaldisc = round($salepercent * $price,0);
          $productprice = $price - $additionaldisc;
          $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
        }
          //===============================



        $ProductDetails[$a] = array(
                  'application'=>$r->application,
                  'prod_type'=>$r->prod_type,
                  'retail_price'=>number_format($srp,2),
                  'price'=>number_format($productprice,2),
                  'images'=>$r->images
                );

        $prod_arr[$a] = array(
          'ProductName'=>$r->brand.' '.$r->name,
          'ProductID'=>$product_id,
          'ProductCategory'=>$r->category,
          'CategoryID'=>$r->categoryid,
          'OnSale'=>$onsale,
          'ProductSavings'=>$totalsavings,
          'ProductDetails'=>array($ProductDetails[$a]));

        $a++;
        $addsale=0;
      }


        $col = new Collection($prod_arr); //convert array into laravel collection

        //Slice array based on the items wish to show on each page and the current page number
        $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

        //Save the Pagination in a variable which will be passed to view
        //second param will be changed by variable $a instead of count($col)
        $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);
    }

    return $prod_arr;
}]);
//Route::get('productsbysubcategory/{id}', 'ProductsController@productsbysubcategory');
Route::get('productsbysubcategory', ['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $id = !empty($_GET['sid']) ? $_GET['sid'] : "";
    $prod_arr = array('Status'=>'Nothing to fetch.');

    if(!empty($id)){
      $id = taiga_crypt($id,'d');
      $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
      $perPage = 25; //set number of records per page

      $sql='SELECT
            t_products.id,
            t_products.name,
            t_brands.brand,
            t_categories.category,
            t_categories.id as categoryid,
            t_variations.application,
            t_variations.prod_type,
            t_variations.retail_price,
            t_variations.price,
            t_variations.images
            FROM t_products
            LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
            JOIN t_categories ON t_categories.id=t_products.category_id
            JOIN t_attributes ON t_attributes.product_id=t_products.id
            JOIN t_variations ON t_variations.attribute_id=t_attributes.id
            WHERE t_products.sub_category_id='.$id.'
            GROUP BY t_products.id
            ORDER BY RAND()';

      $result = DB::select($sql);

      $prod_arr = array();$ProductDetails=array();$a=0;
      $onsale = false; $addsale = 0;
      foreach($result as $r){

        $saleresponse = checkifsale($r->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
        if(!empty($saleresponse)){
          //get the additional discount, this is to be added on product savings
          foreach($saleresponse as $sr){
            if(!empty($sr->percentage) and $sr->percentage!=0){
              $addsale = $sr->percentage;
              $onsale = true;
            }
          }
        }

        $product_id = taiga_crypt($r->id,'e');

        $retail_price = str_replace(",","",$r->retail_price);
        $price = str_replace(",","",$r->price);

        $difference = (double)$retail_price - (double)$price;
        $savings = 0;
        $retail_price = (integer)$retail_price;
        if($retail_price>0){
            $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
        }

        $savings = round($savings,0);

        //===============================
        $productprice = round(trim($r->price),0);
        $totalsavings = $savings;
        $srp = round(trim($r->retail_price),0);

        if($onsale==true){
          $price = round(trim($r->price),0);
          $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
          $additionaldisc = round($salepercent * $price,0);
          $productprice = $price - $additionaldisc;
          $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
        }
          //==============================

        $ProductDetails[$a] = array(
                  'application'=>$r->application,
                  'prod_type'=>$r->prod_type,
                  'retail_price'=>number_format($srp,2),
                  'price'=>number_format($productprice,2),
                  'images'=>$r->images
                );

        $prod_arr[$a] = array(
          'ProductName'=>$r->brand.' '.$r->name,
          'ProductID'=>$product_id,
          'ProductCategory'=>$r->category,
          'CategoryID'=>$r->categoryid,
          'OnSale'=>$onsale,
          'ProductSavings'=>$totalsavings,
          'ProductDetails'=>array($ProductDetails[$a]));

        $a++;
        $addsale=0;
      }

        $col = new Collection($prod_arr); //convert array into laravel collection

        //Slice array based on the items wish to show on each page and the current page number
        $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

        //Save the Pagination in a variable which will be passed to view
        $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);
    }

    return $prod_arr;
}]);
//Route::get('productsearch/{keyword}/', 'ProductsController@productsearch');
Route::get('searchbybrand',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");
  $id = !empty($_GET['id']) ? $_GET['id'] : "";

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

  $sql = 'SELECT * FROM ((SELECT
          t_brands.brand,
          t_products.name,
          t_products.id,
          t_variations.application,
          t_variations.retail_price,
          t_variations.price,
          t_variations.images,
          t_categories.category,
          t_categories.id as categoryid
          FROM t_products
          LEFT JOIN t_brands ON t_products.brand_id=t_brands.id
          JOIN t_categories ON t_categories.id=t_products.category_id
          JOIN t_attributes ON t_attributes.product_id=t_products.id
          JOIN t_variations ON t_variations.attribute_id=t_attributes.id
          WHERE t_brands.id like '.$id.' AND t_products.status<>0)
          UNION
          (SELECT
          t_brands.brand,
          t_products.name,
          t_products.id,
          t_variations.application,
          t_variations.retail_price,
          t_variations.price,
          t_variations.images,
          t_categories.category,
          t_categories.id as categoryid
          FROM t_products
          RIGHT JOIN t_brands ON t_products.brand_id=t_brands.id
          JOIN t_categories ON t_categories.id=t_products.category_id
          JOIN t_attributes ON t_attributes.product_id=t_products.id
          JOIN t_variations ON t_variations.attribute_id=t_attributes.id
          WHERE t_brands.id='.$id.' AND t_products.status<>0 ) ) as i ORDER BY RAND()';

    $result = DB::select($sql);

    $prod_arrz = array(); $a=0; $ProductDetails = array();$no_of_prod=0; $prod_ids = array();
    $onsale = false; $addsale = 0;
    foreach($result as $r){

      $saleresponse = checkifsale($r->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
      if(!empty($saleresponse)){
        //get the additional discount, this is to be added on product savings
        foreach($saleresponse as $sr){
          if(!empty($sr->percentage) and $sr->percentage!=0){
            $addsale = $sr->percentage;
            $onsale = true;
          }
        }
      }

      $retail_price = str_replace(",","",$r->retail_price);
      $price = str_replace(",","",$r->price);

      $difference = (double)$retail_price - (double)$price;
      $savings = 0;
      $retail_price = (integer)$retail_price;
      if($retail_price>0){
          $savings = ($difference / $retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
      }

	$savings = round($savings,0);
      //===============================sales
      $productprice = round(trim($r->price),0);
      $totalsavings = $savings;
      $srp = round(trim($r->retail_price),0);

      if($onsale==true){
        $price = round(trim($r->price),0);
        $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
        $additionaldisc = round($salepercent * $price,0);
        $productprice = $price - $additionaldisc;
        $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
      }
        //===============================

      if(!empty($r->id)){
        $no_of_prod++;
        $prod_ids[$a] = $r->id;
      }



      $savings = round($savings,0);

      $ProductDetails[$a] = array(
        'application'=>$r->application,
        'retail_price'=>number_format($srp,2),
        'price'=>number_format($productprice,2),
        'images'=>$r->images);


      $prod_id = taiga_crypt($r->id,'e'); //encrypt the product id
      $brand = trim($r->brand);
      if(!empty($brand)){
        $prod_n = $brand.' '.$r->name;
      }
      else{
        $prod_n = $r->name;
      }

      $prod_arrz[$a] = array(
        'ProductID'=>$prod_id,
        'ProductName'=>$prod_n,
        'CategoryID'=>$r->categoryid,
        'Category'=>$r->category,
        'OnSale'=>$onsale,
        'ProductSavings'=>$totalsavings,
        'ProductDetails'=>array($ProductDetails[$a]));

      $a++;
      $addsale=0;
    }

    $col = new Collection($prod_arrz); //convert array into laravel collection
    //$col = $col->sortBy('ProductName');
    //Slice array based on the items wish to show on each page and the current page number
    $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();
    //$col = unset($col['data']);
    //Save the Pagination in a variable which will be passed to view
    $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

    //save the search keyword
    /*$reg = new SearchKeywords;
    $reg->searched_keyword = $keyword;
    $reg->no_of_result = $no_of_prod;
    $reg->product_ids = implode(', ',$prod_ids);
    $reg->save();*/

    return $prod_arr;
}]);
Route::get('productsearch_test',['middleware'=>'cors',function(){
    $search_arr = [];
    foreach(explode(' ', $_GET['keyword_test']) as $item) {
      array_push($search_arr, $item);
    }
    $search_query = '';
    $temp_search_query = '';
    foreach($search_arr as $item) {
      $temp_search_query .= " t_products.name LIKE '%$item%' OR";
    }
    $search_query = substr($temp_search_query, 0, -3);


    date_default_timezone_set("Asia/Singapore");

    $keyword = !empty($_GET['keyword']) ? $_GET['keyword'] : "";
    $type    = isset($_GET['type']) ? $_GET['type'] : "0"; //checks if looking for motorcycle product

    $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
    $perPage = 40; //set number of records per page

        $type_cond = "";
        if($type==1){
          $type_cond = " AND type=1";
        }

        $sql = "SELECT
                t_brands.brand,
                t_products.name,
                t_products.id,
                t_categories.category,
                t_categories.id as categoryid,
                t_variations.application,
                t_variations.retail_price,
                t_variations.price,
                t_variations.images
                FROM t_products
                LEFT JOIN t_brands ON t_products.brand_id=t_brands.id
                JOIN t_categories ON t_categories.id=t_products.category_id
                JOIN t_attributes ON t_attributes.product_id=t_products.id
                JOIN t_variations ON t_variations.attribute_id=t_attributes.id
                WHERE $search_query AND t_products.status<>0 ".$type_cond." ORDER BY RAND() LIMIT $perPage";

      $result = DB::select($sql);
      if(count($result) == 0) {
        $sql = "SELECT
                t_brands.brand,
                t_products.name,
                t_products.id,
                t_categories.category,
                t_categories.id as categoryid,
                t_variations.application,
                t_variations.retail_price,
                t_variations.price,
                t_variations.images
                FROM t_products
                LEFT JOIN t_brands ON t_products.brand_id=t_brands.id
                JOIN t_categories ON t_categories.id=t_products.category_id
                JOIN t_attributes ON t_attributes.product_id=t_products.id
                JOIN t_variations ON t_variations.attribute_id=t_attributes.id
                WHERE t_products.status<>0 ".$type_cond." ORDER BY RAND() LIMIT $perPage";
      }
      $result = DB::select($sql);


      $prod_arrz = array(); $a=0; $ProductDetails = array();$no_of_prod=0; $prod_ids = array();
      $onsale = false; $addsale = 0;
      foreach($result as $r){

        //checks if the product is on sale
        $saleresponse = checkifsale($r->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
        if(!empty($saleresponse)){
          //get the additional discount, this is to be added on product savings
          foreach($saleresponse as $sr){
            if(!empty($sr->percentage) and $sr->percentage!=0){
              $addsale = $sr->percentage;
              $onsale = true;
            }
          }
        }

        if(!empty($r->id)){
          $no_of_prod++;
          $prod_ids[$a] = $r->id;
        }

        $retail_price = str_replace(",","",$r->retail_price);
        $price = str_replace(",","",$r->price);

        $difference = (double)$retail_price - (double)$price;
        $savings = 0;
        $retail_price = (integer)$retail_price;
        if($retail_price>0){
            $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
        }

        $savings = round($savings,0);

        //=====================for sale purposes=================
        $productprice = round(trim($r->price),0);
        $totalsavings = $savings;
        $srp = round(trim($r->retail_price),0);

        if($onsale==true){
          $price = round(trim($r->price),0);
          $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
          $additionaldisc = round($salepercent * $price,0);
          $productprice = $price - $additionaldisc;
          $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
        }
        //=====================for sale purposes=================

        $ProductDetails[$a] = array(
          'application'=>$r->application,
          'retail_price'=>number_format($srp,2),
          'price'=>number_format($productprice,2),
          'images'=>$r->images);


        $prod_id = taiga_crypt($r->id,'e'); //encrypt the product id
        $brand = trim($r->brand);
        if(!empty($brand)){
          $prod_n = $brand.' '.$r->name;
        }
        else{
          $prod_n = $r->name;
        }

        $prod_arrz[$a] = array(
          'ProductID'=>$prod_id,
          'ProductName'=>$prod_n,
          'CategoryID'=>$r->categoryid,
          'ProductCategory'=>$r->category,
          'OnSale'=>$onsale,
          'ProductSavings'=>$totalsavings,
          'ProductDetails'=>array($ProductDetails[$a]));

        $a++;
        $addsale=0;
      }

      $col = new Collection($prod_arrz); //convert array into laravel collection
      //$col = $col->sortBy('ProductName');
      //Slice array based on the items wish to show on each page and the current page number
      //$currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();
      //$col = unset($col['data']);
      //Save the Pagination in a variable which will be passed to view
      //$prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);


      return $col;
}]);

Route::get('productsearch',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $keyword = !empty($_GET['keyword']) ? $_GET['keyword'] : "";
  $type    = isset($_GET['type']) ? $_GET['type'] : "0"; //checks if looking for motorcycle product

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

      /*$sql = 'SELECT
              t_brands.brand,
              t_products.name,
              t_products.id,
              t_variations.application,
              t_variations.retail_price,
              t_variations.price,
              t_variations.images
              FROM t_products
              FULL JOIN t_brands ON t_products.brand_id=t_brands.id
              JOIN t_categories ON t_categories.id=t_products.category_id
              JOIN t_attributes ON t_attributes.product_id=t_products.id
              JOIN t_variations ON t_variations.attribute_id=t_attributes.id
              WHERE t_products.tags LIKE "%'.$keyword.'%"';*/
      $type_cond = "";
      if($type==1){
        $type_cond = " AND type=1";
      }

      $sql = "SELECT * FROM ((SELECT
              t_brands.brand,
              t_products.name,
              t_products.id,
              t_categories.category,
              t_categories.id as categoryid,
              t_variations.application,
              t_variations.retail_price,
              t_variations.price,
              t_variations.images
              FROM t_products
              LEFT JOIN t_brands ON t_products.brand_id=t_brands.id
              JOIN t_categories ON t_categories.id=t_products.category_id
              JOIN t_attributes ON t_attributes.product_id=t_products.id
              JOIN t_variations ON t_variations.attribute_id=t_attributes.id
              WHERE t_products.tags like '%".$keyword."%' AND t_products.status<>0 ".$type_cond.")
              UNION
              (SELECT
              t_brands.brand,
              t_products.name,
              t_products.id,
              t_categories.category,
              t_categories.id as categoryid,
              t_variations.application,
              t_variations.retail_price,
              t_variations.price,
              t_variations.images
              FROM t_products
              RIGHT JOIN t_brands ON t_products.brand_id=t_brands.id
              JOIN t_categories ON t_categories.id=t_products.category_id
              JOIN t_attributes ON t_attributes.product_id=t_products.id
              JOIN t_variations ON t_variations.attribute_id=t_attributes.id
              WHERE t_products.tags like '%".$keyword."%' AND t_products.status<>0 ".$type_cond.") ) as i ORDER BY RAND()";

    $result = DB::select($sql);

    $prod_arrz = array(); $a=0; $ProductDetails = array();$no_of_prod=0; $prod_ids = array();
    $onsale = false; $addsale = 0;
    foreach($result as $r){

      //checks if the product is on sale
      $saleresponse = checkifsale($r->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
      if(!empty($saleresponse)){
        //get the additional discount, this is to be added on product savings
        foreach($saleresponse as $sr){
          if(!empty($sr->percentage) and $sr->percentage!=0){
            $addsale = $sr->percentage;
            $onsale = true;
          }
        }
      }

      if(!empty($r->id)){
        $no_of_prod++;
        $prod_ids[$a] = $r->id;
      }

      $retail_price = str_replace(",","",$r->retail_price);
      $price = str_replace(",","",$r->price);

      $difference = (double)$retail_price - (double)$price;
      $savings = 0;
      $retail_price = (integer)$retail_price;
      if($retail_price>0){
          $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
      }

      $savings = round($savings,0);

      //=====================for sale purposes=================
      $productprice = round(trim($r->price),0);
      $totalsavings = $savings;
      $srp = round(trim($r->retail_price),0);

      if($onsale==true){
        $price = round(trim($r->price),0);
        $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
        $additionaldisc = round($salepercent * $price,0);
        $productprice = $price - $additionaldisc;
        $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
      }
      //=====================for sale purposes=================

      $ProductDetails[$a] = array(
        'application'=>$r->application,
        'retail_price'=>number_format($srp,2),
        'price'=>number_format($productprice,2),
        'images'=>$r->images);


      $prod_id = taiga_crypt($r->id,'e'); //encrypt the product id
      $brand = trim($r->brand);
      if(!empty($brand)){
        $prod_n = $brand.' '.$r->name;
      }
      else{
        $prod_n = $r->name;
      }

      $prod_arrz[$a] = array(
        'ProductID'=>$prod_id,
        'ProductName'=>$prod_n,
        'CategoryID'=>$r->categoryid,
        'ProductCategory'=>$r->category,
        'OnSale'=>$onsale,
        'ProductSavings'=>$totalsavings,
        'ProductDetails'=>array($ProductDetails[$a]));

      $a++;
      $addsale=0;
    }

    $col = new Collection($prod_arrz); //convert array into laravel collection
    //$col = $col->sortBy('ProductName');
    //Slice array based on the items wish to show on each page and the current page number
    $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();
    //$col = unset($col['data']);
    //Save the Pagination in a variable which will be passed to view
    $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

    //save the search keyword
    $reg = new SearchKeywords;
    $reg->searched_keyword = $keyword;
    $reg->no_of_result = $no_of_prod;
    $reg->product_ids = implode(', ',$prod_ids);
    $reg->save();

    return $prod_arr;
}]);
//Route::get('keywordsuggest', 'ProductsController@keywordsuggest');
Route::get('keywordsuggest', ['middleware'=>'cors', function(){
  date_default_timezone_set("Asia/Singapore");

    $keyword = !empty($_GET['keyword']) ? $_GET['keyword'] : "";
    $type    = isset($_GET['type']) ? $_GET['type'] : "0"; //to identify if product searching is motorcycle or not

    if($type==0){
      $tags_result = \App\Tags::select('tag')
          ->where('tag','LIKE',$keyword.'%')
          ->orderBy('tag_ranking','ASC')
          ->orderBy('tag','ASC')
          ->groupBy('tag')
          ->take(10)->get();
    }
    else{
      $sql = 'SELECT t_tags.tag
              FROM t_tags
              JOIN t_products ON t_products.id=t_tags.product_id
              WHERE t_tags.tag like "'.$keyword.'%" and t_products.type=1
              GROUP BY t_tags.tag
              ORDER BY t_tags.tag_ranking,t_tags.tag ASC
              LIMIT 10';
      $tags_result = DB::select($sql);
    }

    return $tags_result;
}]);

Route::get('featuredproducts', ['middleware'=>'cors', function () {
  date_default_timezone_set("Asia/Singapore");

    $n = isset($_GET['prod']) ? $_GET['prod'] : "";
      if($n!=4){
        if($n==1){ //new on the list
          $prod_ids_arr = array();

          //get all category ids
          $sql_cats = 'SELECT id from t_categories WHERE id=2 or id=3 or id=6 ORDER BY sort ASC';
          $cats_res = DB::select($sql_cats);

          //loop in the result
          $a=0;
          foreach($cats_res as $cr){

              //sets number of products based on category id
              if(!empty($cr->id)){
                $limit = "2";
                if($cr->id==2 or $cr->id==3){
                  $limit = "3";
                }

                //get product id where category id is equal to current loop, order by created at, desc
                $sql_prod = 'SELECT * from t_products WHERE category_id='.$cr->id.' AND status = 1 ORDER BY created_at DESC LIMIT '.$limit;
                $prod_res = DB::select($sql_prod);

                //collect all ids
                foreach($prod_res as $pr){
                  if(!empty($pr->id)){
                    $prod_ids_arr[$a] = $pr->id;
                    $a++;
                  }
                }

              }

            //$a++;
          }

          $prod_ids = implode(",",$prod_ids_arr);

          //use collected ids in this query
          $sql = 'SELECT
          t_products.id,
          concat(IFNULL(t_brands.brand,"")," ",t_products.name) as ProductName,
          t_categories.category,
          t_categories.id as categoryid,
          t_products.vendor_code,
          t_variations.application,
          t_variations.retail_price,
          t_variations.price,
          round(((t_variations.retail_price-t_variations.price) / t_variations.retail_price) * 100,0) AS ProductSavings,
          t_variations.prod_type,
          t_variations.sku,
          t_variations.part_no,
          t_variations.made_in,
          t_variations.short_description,
          t_variations.description,
          t_variations.discount,
          t_variations.images
          FROM t_products
          LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
          JOIN t_categories ON t_categories.id=t_products.category_id
          JOIN t_attributes ON t_attributes.product_id=t_products.id
          JOIN t_variations ON t_variations.attribute_id=t_attributes.id
          WHERE t_products.id IN('.$prod_ids.')
          GROUP BY t_products.id
          ORDER BY RAND()';

          $other_products = DB::select($sql);
          $prod_arr = array();$a=0; $onsale = false; $addsale = 0;
          foreach($other_products as $op){
            $saleresponse = checkifsale($op->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
            if(!empty($saleresponse)){
              //get the additional discount, this is to be added on product savings
              foreach($saleresponse as $sr){
                if(!empty($sr->percentage) and $sr->percentage!=0){
                  $addsale = $sr->percentage;
                  $onsale = true;
                }
              }
            }

            $productprice = round(trim($op->price),0);
            $totalsavings = $op->ProductSavings;
            $srp = round(trim($op->retail_price),0);

            if($onsale==true){
              $price = round(trim($op->price),0);
              $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
              $additionaldisc = round($salepercent * $price,0);
              $productprice = $price - $additionaldisc;
              $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
            }

            $product_details[$a] = array(
              'prod_type'=>$op->prod_type,
              'sku'=>$op->sku,
              'part_no'=>$op->part_no,
              'made_in'=>$op->made_in,
              'short_description'=>$op->short_description,
              'description'=>$op->description,
              'application'=>$op->application,
              'retail_price'=>number_format($srp,2),
              'price'=>number_format($productprice,2),
              'discount'=>$prod_ids, /*$op->discount,*/
              'images'=>$op->images);

            $prodid = taiga_crypt($op->id,'e');
            $prod_arr[$a] = array(
              'ProductName'=>$op->ProductName,
              'ProductID'=>$prodid,
              'CategoryID'=>$op->categoryid,
              'OnSale'=>$onsale,
              'ProductCategory'=>$op->category,
              'ProductSavings'=>$totalsavings,
              'ProductDetails'=>array($product_details[$a])
            );
            $a++;
            $addsale=0;
            $onsale=false;
          }
        }
        elseif($n==2){
          $sql = 'SELECT
          t_products.id,
          concat(IFNULL(t_brands.brand,"")," ",t_products.name) as ProductName,
          t_categories.category,
          t_categories.id as categoryid,
          t_products.vendor_code,
          t_variations.application,
          t_variations.retail_price,
          t_variations.price,
          round(((t_variations.retail_price-t_variations.price) / t_variations.retail_price) * 100,0) AS ProductSavings,
          t_variations.prod_type,
          t_variations.sku,
          t_variations.part_no,
          t_variations.made_in,
          t_variations.short_description,
          t_variations.description,
          t_variations.discount,
          t_variations.images
          FROM t_products
          LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
          JOIN t_categories ON t_categories.id=t_products.category_id
          JOIN t_attributes ON t_attributes.product_id=t_products.id
          JOIN t_variations ON t_variations.attribute_id=t_attributes.id
          WHERE t_products.featured = "2,3" OR t_products.featured = 2 AND t_products.status=1
	  GROUP BY t_products.id
          ORDER BY RAND() LIMIT 20';

          $other_products = DB::select($sql);
          $prod_arr = array();$a=0; $onsale = false; $addsale = 0;
          foreach($other_products as $op){

            $saleresponse = checkifsale($op->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
            if(!empty($saleresponse)){
              //get the additional discount, this is to be added on product savings
              foreach($saleresponse as $sr){
                if(!empty($sr->percentage) and $sr->percentage!=0){
                  $addsale = $sr->percentage;
                  $onsale = true;
                }
              }
            }

            $productprice = round(trim($op->price),0);
            $totalsavings = $op->ProductSavings;
            $srp = round(trim($op->retail_price),0);

            if($onsale==true){
              $price = round(trim($op->price),0);
              $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
              $additionaldisc = round($salepercent * $price,0);
              $productprice = $price - $additionaldisc;
              $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
            }

            $product_details[$a] = array(
              'prod_type'=>$op->prod_type,
              'sku'=>$op->sku,
              'part_no'=>$op->part_no,
              'made_in'=>$op->made_in,
              'short_description'=>$op->short_description,
              'description'=>$op->description,
              'application'=>$op->application,
              'retail_price'=>number_format($srp,2),
              'price'=>number_format($productprice,2),
              'discount'=>$op->discount,
              'images'=>$op->images);

            $prodid = taiga_crypt($op->id,'e');
            $prod_arr[$a] = array(
              'ProductName'=>$op->ProductName,
              'ProductID'=>$prodid,
              'CategoryID'=>$op->categoryid,
              'OnSale'=>$onsale,
              'ProductCategory'=>$op->category,
              'ProductSavings'=>$totalsavings,
              'ProductDetails'=>array($product_details[$a])
            );
            $a++;
            $addsale=0;
            $onsale=false;
          }
        }
        else{

            $sql = 'SELECT
            t_products.id,
            concat(IFNULL(t_brands.brand,"")," ",t_products.name) as ProductName,
            t_categories.category,
            t_categories.id as categoryid,
            t_products.vendor_code,
            t_variations.application,
            t_variations.retail_price,
            t_variations.price,
            round(((t_variations.retail_price-t_variations.price) / t_variations.retail_price) * 100,0) AS ProductSavings,
            t_variations.prod_type,
            t_variations.sku,
            t_variations.part_no,
            t_variations.made_in,
            t_variations.short_description,
            t_variations.description,
            t_variations.discount,
            t_variations.images
            FROM t_products
            LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
            JOIN t_categories ON t_categories.id=t_products.category_id
            JOIN t_attributes ON t_attributes.product_id=t_products.id
            JOIN t_variations ON t_variations.attribute_id=t_attributes.id
            WHERE t_products.featured = "2,3" OR t_products.featured = 3 AND t_products.status=1
	    GROUP BY t_products.id
            ORDER BY RAND() LIMIT 20';

            $other_products = DB::select($sql);
            $prod_arr = array();$a=0; $onsale = false; $addsale = 0;
            foreach($other_products as $op){

              $saleresponse = checkifsale($op->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
              if(!empty($saleresponse)){
                //get the additional discount, this is to be added on product savings
                foreach($saleresponse as $sr){
                  if(!empty($sr->percentage) and $sr->percentage!=0){
                    $addsale = $sr->percentage;
                    $onsale = true;
                  }
                }
              }

              $productprice = round(trim($op->price),0);
              $totalsavings = $op->ProductSavings;
              $srp = round(trim($op->retail_price),0);

              if($onsale==true){
                $price = round(trim($op->price),0);
                $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
                $additionaldisc = round($salepercent * $price,0);
                $productprice = $price - $additionaldisc;
                $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
              }

              $product_details[$a] = array(
                'prod_type'=>$op->prod_type,
                'sku'=>$op->sku,
                'part_no'=>$op->part_no,
                'made_in'=>$op->made_in,
                'short_description'=>$op->short_description,
                'description'=>$op->description,
                'application'=>$op->application,
                'retail_price'=>number_format($srp,2),
                'price'=>number_format($productprice,2),
                'discount'=>$op->discount,
                'images'=>$op->images);

              $prodid = taiga_crypt($op->id,'e');
              $prod_arr[$a] = array(
                'ProductName'=>$op->ProductName,
                'ProductID'=>$prodid,
                'CategoryID'=>$op->categoryid,
                'OnSale'=>$onsale,
                'ProductCategory'=>$op->category,
                'ProductSavings'=>$totalsavings,
                'ProductDetails'=>array($product_details[$a])
              );
              $a++;
              $addsale=0;
              $onsale=false;
            }

        }
      } //end of checking if featured not equal to 4
      else{
        $sql = 'SELECT
        t_products.id,
        concat(IFNULL(t_brands.brand,"")," ",t_products.name) as ProductName,
        t_categories.category,
        t_categories.id as categoryid,
        t_products.vendor_code,
        t_variations.application,
        t_variations.retail_price,
        t_variations.price,
        round(((t_variations.retail_price-t_variations.price) / t_variations.retail_price) * 100,0) AS ProductSavings,
        t_variations.prod_type,
        t_variations.sku,
        t_variations.part_no,
        t_variations.made_in,
        t_variations.short_description,
        t_variations.description,
        t_variations.discount,
        t_variations.images
        FROM t_products
        LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
        JOIN t_categories ON t_categories.id=t_products.category_id
        JOIN t_attributes ON t_attributes.product_id=t_products.id
        JOIN t_variations ON t_variations.attribute_id=t_attributes.id
        WHERE t_products.status=1
	GROUP BY t_products.id
        ORDER BY rand() LIMIT 60';

        $other_products = DB::select($sql);
        $prod_arr = array();$a=0; $onsale = false; $addsale = 0;
        foreach($other_products as $op){

          $saleresponse = checkifsale($op->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
          if(!empty($saleresponse)){
            //get the additional discount, this is to be added on product savings
            foreach($saleresponse as $sr){
              if(!empty($sr->percentage) and $sr->percentage!=0){
                $addsale = $sr->percentage;
                $onsale = true;
              }
            }
          }

          $productprice = round(trim($op->price),0);
          $totalsavings = $op->ProductSavings;
          $srp = round(trim($op->retail_price),0);

          if($onsale==true){
            $price = round(trim($op->price),0);
            $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
            $additionaldisc = round($salepercent * $price,0);
            $productprice = $price - $additionaldisc;
            $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
          }

          $product_details[$a] = array(
            'prod_type'=>$op->prod_type,
            'sku'=>$op->sku,
            'part_no'=>$op->part_no,
            'made_in'=>$op->made_in,
            'short_description'=>$op->short_description,
            'description'=>$op->description,
            'application'=>$op->application,
            'retail_price'=>number_format($srp,2),
            'price'=>number_format($productprice,2),
            'discount'=>$op->discount,
            'images'=>$op->images);

          $prodid = taiga_crypt($op->id,'e');
          $prod_arr[$a] = array(
            'ProductName'=>$op->ProductName,
            'ProductID'=>$prodid,
            'CategoryID'=>$op->categoryid,
            'OnSale'=>$onsale,
            'ProductCategory'=>$op->category,
            'ProductSavings'=>$totalsavings,
            'ProductDetails'=>array($product_details[$a])
          );
          $a++;
          $addsale=0;
        }
      }
    //$prod_arrs = shuffle($prod_arr);
    return $prod_arr;
}]);

Route::get('otherproductsloadmore', ['middleware' =>'cors',function(){
  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 42; //set number of records per page
  $sql = 'SELECT
  t_products.id,
  concat(IFNULL(t_brands.brand,"")," ",t_products.name) as ProductName,
  t_categories.id as categoryid,
  t_variations.application,
  t_variations.retail_price,
  t_variations.price,
  round(((t_variations.retail_price-t_variations.price) / t_variations.retail_price) * 100,0) AS ProductSavings,
  t_variations.prod_type,
  t_variations.discount,
  t_variations.images
  FROM t_products
  LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
  JOIN t_categories ON t_categories.id=t_products.category_id
  JOIN t_attributes ON t_attributes.product_id=t_products.id
  JOIN t_variations ON t_variations.attribute_id=t_attributes.id
  WHERE t_products.status=1
  GROUP BY t_products.id
  ORDER BY rand() LIMIT 300';

  $other_products = DB::select($sql);
  $prod_arr = array();$a=0; $onsale = false; $addsale = 0;
  foreach($other_products as $op) {
      $saleresponse = checkifsale($op->id, date('Y-m-d'), date('Y-m-d'), $i = 'product');
      if(!empty($saleresponse)){
        //get the additional discount, this is to be added on product savings
        foreach($saleresponse as $sr){
          if(!empty($sr->percentage) and $sr->percentage!=0){
            $addsale = $sr->percentage;
            $onsale = true;
          }
        }
      }
      $productprice = round(trim($op->price),0);
      $totalsavings = $op->ProductSavings;
      $srp = round(trim($op->retail_price),0);

      if($onsale==true){
        $price = round(trim($op->price),0);
        $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
        $additionaldisc = round($salepercent * $price,0);
        $productprice = $price - $additionaldisc;
        $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
      }

      $product_details[$a] = array(
        'application'=>$op->application,
        'retail_price'=>number_format($srp,2),
        'price'=>number_format($productprice,2),
        'discount'=>$op->discount,
        'images'=>$op->images);

      $prodid = taiga_crypt($op->id,'e');
      $prod_arr[$a] = array(
        'ProductName'=>$op->ProductName,
        'ProductID'=>$prodid,
        'CategoryID'=>$op->categoryid,
        'OnSale'=>$onsale,
        'ProductSavings'=>$totalsavings,
        'ProductDetails'=>array($product_details[$a])
      );
      $a++;
      $addsale=0;
    }
    $col = new Collection($prod_arr); //convert array into laravel collection
    //$col = $col->sortBy('ProductName');
    //Slice array based on the items wish to show on each page and the current page number
    $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();
    //$col = unset($col['data']);
    //Save the Pagination in a variable which will be passed to view
    $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

    return $prod_arr;
}]);

Route::get('updateemail',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");
  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
  $email = isset($_GET['email']) ? $_GET['email'] : "";

  $user = \App\User::where('remember_token',$uid)->get();
  $user_id="";
  foreach($user as $u){
    if(!empty($u->id)){
      $user_id = $u->id;
    }
  }

  $response = array('status'=>'error','message'=>'Unable to update email address.','data'=>'');
  if(!empty($user_id)){
    $update_email = DB::table('users')
      ->where('uid',$uid)
      ->update(['email'=>$email]);

    $response = array('status'=>'success','message'=>'Successfully updated email address.','data'=>'');
  }

  return $response;
}]);

Route::get('updatemobile',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
  $mobile = isset($_GET['mobile_no']) ? $_GET['mobile_no'] : "";

  $user = \App\User::where('remember_token',$uid)->get();
  $user_id="";
  foreach($user as $u){
    if(!empty($u->id)){
      $user_id = $u->id;
    }
  }

  $response = array('status'=>'error','message'=>'Unable to update mobile no.','data'=>'');
  if(!empty($user_id)){
    $update_mobile = DB::table('users')
      ->where('uid',$uid)
      ->update(['mobile_no'=>$mobile]);

    $response = array('status'=>'success','message'=>'Successfully updated mobile no.','data'=>'');
  }

  return $response;
}]);

Route::post('registervendor',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");
  $v_type = isset($_POST['vendor_type']) ? $_POST['vendor_type'] : "";

  /*if(!empty($v_type)){
    if($v_type=='sp'){
      $_POST['name'];
      $_POST['address'];
      $_POST['contact_name'];
      $_POST['position'];
      $_POST['contact_number'];
      $_POST['viber_number'];
      $_POST['email'];
      $_POST['products_to_sell'];
      $_POST['years'];
      $_POST['warehouse'];
      $_POST['profile_image'];
    }

  }*/

}]);

Route::get('register',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $email = isset($_GET['email']) ? $_GET['email'] : "";
  //$pass = isset($_GET['password']) ? $_GET['password'] : "";
  $name = isset($_GET['name']) ? $_GET['name'] : "";
  $accesstoken = isset($_GET['accesstoken']) ? $_GET['accesstoken'] : "";
  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
  $mobile = isset($_GET['mobile_no']) ? $_GET['mobile_no'] : "";

  //rules
  //pass and token cannot be empty at the same time
  //email should not exist yet,else proceed to login

  //confirm uid and accesstoken validity
  //confirm if email already exist,if not,then register/add
  //if registered, return success message
  //if no need to register and valid uid and accesstoken, return success message


  //check if email or uid already exist
  /*$user_email = \App\User::where('email',$email)->get();
  $emailres = "";
  foreach($user_email as $ue){
    if(!empty($ue->email)){
      $emailres = $ue->email;
      $name = $ue->name;
      $id = $ue->id;
    }
  }*/
  $emailres = ""; $mobileres = "";
  if(!empty($email) or !empty($mobile)){
    $user_email = DB::table('users')
        ->where('email','=',$email)
        ->orWhere('mobile_no','=',$mobile)
        ->first();


    foreach($user_email as $ue){
      if(!empty($ue->email) or !empty($ue->mobile_no)){
        $emailres = $ue->email;
        $mobileres= $ue->mobile_no;
        $name = $ue->name;
        $id = $ue->id;
      }
    }
  }


  if(empty($emailres)){ //if email still not exists
    //$pass = $pass!="" ? Hash::make($pass) : "";
    /*$response = User::create([
        'name' => $name,
        'email' => $email,
        'remember_token' => $uid,
        'role_id'=>5,
        'last_logged'=> date('Y-m-d h:i:s'),
    ]);*/

    //check if mobile no already exist
    //check if uid exist
    $uid_reg = DB::table('users')
        ->where('remember_token','=',$uid)
        ->first();

    if(empty($uid_reg)){
      $reg = new User;
      $reg->name = $name;
      $reg->email = $email;
      $reg->mobile_no = $mobile;
      $reg->subscribed = 1;
      $reg->remember_token = $uid;
      $reg->role_id = 5;
      $reg->last_logged = date("Y-m-d h:i:s");
      $reg->save();

      $response = array('status'=>'success','message='=>'Successfully added new user.','ID'=>$reg->id,'UID'=>$uid);
    }
    else{
      $response = array('status'=>'success','message='=>'Successfully verified user.');
    }

  }
  else{
    $response = array('status'=>'success','message='=>'No update has been made.','ID'=>$id,'UID'=>$uid);
  }

  return $response;
}]);

Route::get('getvendorbysku',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

    $sku = isset($_GET['sku']) ? $_GET['sku'] : ""; //"ICIAP10000001"

    $product_result = DB::table('t_variations')
          ->select('t_products.vendor_code','users.name','users.email','users.vendor_name')
          ->where('t_variations.sku','=',$sku)
          ->join('t_attributes', 't_attributes.id', '=', 't_variations.attribute_id')
          ->join('t_products','t_products.id','=','t_attributes.product_id')
          ->join('users','users.vendor_code','=','t_products.vendor_code')
          ->get();

    return $product_result;
}]);

Route::get('deleteall',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $cartid = isset($_GET['cart_id']) ? $_GET['cart_id'] : "";
  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";

  //get id using uid
  $user_email = \App\User::where('remember_token',$uid)->get();

  $id = "";
  $emailres = "";
  foreach($user_email as $ue){
    if(!empty($ue->email)){
        $emailres = $ue->email;
        $id = $ue->id;
    }
  }

  $cart_id="";
  $cart = \App\Carts::where('users_id',$id)->where('id',$cartid)->where('status',0)->get();
  foreach($cart as $c){
    if(!empty($c->id)){
      $cart_id = $c->id;
    }
  }

  if(!empty($cart_id)){
    $result_qty_update = DB::table('t_product_orders')
      ->where('cart_id',$cartid)
      ->where('status',1)
      ->update(['status'=>0]);

      $response = array('status'=>'success','message'=>'Successfully removed all items from cart.','data'=>'');
  }
  else{
    $response = array('status'=>'error','message'=>'Cart is does not exist on user.','data'=>'');
  }

  return $response;
}]);

Route::get('updateqty',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $qty = isset($_GET['qty']) ? $_GET['qty'] : "";
  $sku = isset($_GET['sku']) ? $_GET['sku'] : "";
  $cartid = isset($_GET['cart_id']) ? $_GET['cart_id'] : "";
  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";

  //get id using uid
  $user_email = \App\User::where('remember_token',$uid)->get();

  $id = "";
  $emailres = "";
  foreach($user_email as $ue){
    if(!empty($ue->email)){
        $emailres = $ue->email;
        $id = $ue->id;
    }
  }

  if($qty>=1){ //verify qty is not zero

    //verify uid owns the cart
    $cart_id="";
    $cart = \App\Carts::where('users_id',$id)->where('status',0)->get();
    foreach($cart as $c){
      if(!empty($c->id)){
        $cart_id = $c->id;
      }
    }

    if(!empty($cart_id) and $cart_id==$cartid){
      $result_qty_update = DB::table('t_product_orders')
        ->where('sku',$sku)
        ->where('cart_id',$cartid)
        ->where('status',1)
        ->update(['quantity'=>$qty]);

        $response = array('status'=>'success','message'=>'Successfully updated the item qty.','data'=>'');
    }
    else{
        $response = array('status'=>'error','message'=>'Cart does not exists.','data'=>'');
    }

  }
  else{
    $response = array('status'=>'error','message'=>'Quantity should be more than 0.','data'=>'');
  }

  return $response;
}]);

Route::get('addtocartmobile',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $sku = isset($_GET['sku']) ? $_GET['sku'] : "";
  $qty = isset($_GET['qty']) ? $_GET['qty'] : "";
  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
  $price = isset($_GET['price']) ? $_GET['price'] : 0;
  $onsale = isset($_GET['onsale']) ? $_GET['onsale'] : "";

  if(!empty($sku) and !empty($qty) and !empty($uid)){
    //select('tag')
    //get id using uid
    $user_email = \App\User::where('remember_token',$uid)->get();

    $id = "";
    $emailres = "";
    foreach($user_email as $ue){
      if(!empty($ue->email)){
          $emailres = $ue->email;
          $id = $ue->id;
      }
    }

    //check if user has a cart status 0 (means not proceeding to payment yet)
    //0 is when user has not yet placed the order
    if($id!=0 and !empty($id)){

        //verify first if item is active and not a motorcycle
        $sqlchecksku = 'SELECT
        t_products.name,
        t_products.type
        FROM t_variations
        JOIN t_attributes ON t_variations.attribute_id=t_attributes.id
        JOIN t_products ON t_products.id=t_attributes.product_id
        WHERE t_variations.sku="'.$sku.'"
        AND t_products.status=1
        AND t_products.type=0';

        $resskucheck = DB::select($sqlchecksku);
        $product_exists = "false";
        foreach($resskucheck as $rc){
          if(!empty($rc->name)){
            $product_exists = "true";
          }
        }

        if($product_exists=="true"){
            $cart_id="";
            $cart = \App\Carts::where('users_id',$id)->where('status',0)->get();
            foreach($cart as $c){
              if(!empty($c->id)){
                $cart_id = $c->id;
              }
            }

            if(empty($cart_id) and !empty($id)){ //cart id does not exist, then create a cart
              $create_cart = new Carts;
              $create_cart->users_id = $id;
              $create_cart->status = 0;
              $create_cart->date_time = date('Y-m-d h:i:s');
              $create_cart->save();
              $cart_id = $create_cart->id;
            }

            //if sku and uid already exist, just add the qty
            $sku_order = "false";$sku_qty=0;
            $checksku = \App\ProductOrders::where('sku',$sku)->where('cart_id',$cart_id)->where('status',1)->get();
            foreach($checksku as $cs){
              if(!empty($cs->id)){
                $sku_order = "true";
                $sku_qty = $cs->quantity;
              }
            }

            if($sku_order=="false"){ //sku not yet on user's cart
              $orders = new ProductOrders;
              $orders->sku = $sku;
              $orders->cart_id = $cart_id;
              $orders->quantity = $qty;
              $orders->price = $price;
              $orders->onsale = $onsale;
              $orders->status = 1;
              $orders->save();

              $response = array('status'=>'success','cartid'=>$cart_id,'message'=>'Item successfully added to cart.');
            }
            else{
              $sku_qty = $sku_qty+$qty;
              $sql_str = 'UPDATE t_product_orders SET quantity="'.$sku_qty.'" WHERE sku="'.$sku.'" AND cart_id='.$cart_id.' AND status=1';

              $result_qty_update = DB::table('t_product_orders')
                ->where('sku',$sku)
                ->where('cart_id',$cart_id)
                ->where('status',1)
                ->update(['quantity'=>$sku_qty]);

              $response = array('status'=>'success','cartid'=>$cart_id,'message'=>'Item quantity successfully updated.');
            }
        }
        else{
          $response = array('status'=>'error','message'=>'Product does not exists.'.$product_exists);
        }

    }
    else{
      $response = array('status'=>'error','message'=>'Unable to add item to cart.');
    }
  }
  else{
    $response = array('status'=>'error','message'=>'Unable to add item to cart. Incorrect parameters.','sku'=>$sku,'uid'=>$uid,'qty'=>$qty);
  }

  return $response;
}]);

Route::post('addtocart',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $sku = isset($_POST['sku']) ? $_POST['sku'] : "";
  $qty = isset($_POST['qty']) ? $_POST['qty'] : "";
  $uid = isset($_POST['uid']) ? $_POST['uid'] : "";
  $price = isset($_POST['price']) ? $_POST['price'] : 0;
  $onsale = isset($_POST['onsale']) ? $_POST['onsale'] : "";
  $pricez = $price;

  if(!empty($sku) and !empty($qty) and !empty($uid)){
    //select('tag')
    //get id using uid
    $user_email = \App\User::where('remember_token',$uid)->get();

    $id = "";
    $emailres = "";
    foreach($user_email as $ue){
      if(!empty($ue->email)){
          $emailres = $ue->email;
          $id = $ue->id;
      }
    }

    //check if user has a cart status 0 (means not proceeding to payment yet)
    //0 is when user has not yet placed the order
    if($id!=0 and !empty($id)){

        //verify first if item is active and not a motorcycle
        $sqlchecksku = 'SELECT
        t_products.name,
        t_products.type
        FROM t_variations
        JOIN t_attributes ON t_variations.attribute_id=t_attributes.id
        JOIN t_products ON t_products.id=t_attributes.product_id
        WHERE t_variations.sku="'.$sku.'"
        AND t_products.status=1
        AND t_products.type=0';

        $resskucheck = DB::select($sqlchecksku);
        $product_exists = "false";
        foreach($resskucheck as $rc){
          if(!empty($rc->name)){
            $product_exists = "true";
          }
        }

        if($product_exists=="true"){
            $cart_id="";
            $cart = \App\Carts::where('users_id',$id)->where('status',0)->get();
            foreach($cart as $c){
              if(!empty($c->id)){
                $cart_id = $c->id;
              }
            }

            if(empty($cart_id) and !empty($id)){ //cart id does not exist, then create a cart
              $create_cart = new Carts;
              $create_cart->users_id = $id;
              $create_cart->status = 0;
              $create_cart->date_time = date('Y-m-d h:i:s');
              $create_cart->save();
              $cart_id = $create_cart->id;
            }

            //if sku and uid already exist, just add the qty
            $sku_order = "false";$sku_qty=0;
            $checksku = \App\ProductOrders::where('sku',$sku)->where('cart_id',$cart_id)->where('status',1)->get();
            foreach($checksku as $cs){
              if(!empty($cs->id)){
                $sku_order = "true";
                $sku_qty = $cs->quantity;
              }
            }

            if($sku_order=="false"){ //sku not yet on user's cart
              $orders = new ProductOrders;
              $orders->sku = $sku;
              $orders->cart_id = $cart_id;
              $orders->quantity = $qty;
              $orders->price = $price;
              $orders->onsale = $onsale;
              $orders->status = 1;
              $orders->save();

              $response = array('status'=>'success','cartid'=>$cart_id,'message'=>'Item successfully added to cart.'.$pricez);
            }
            else{
              $sku_qty = $sku_qty+$qty;
              $sql_str = 'UPDATE t_product_orders SET quantity="'.$sku_qty.'" WHERE sku="'.$sku.'" AND cart_id='.$cart_id.' AND status=1';

              $result_qty_update = DB::table('t_product_orders')
                ->where('sku',$sku)
                ->where('cart_id',$cart_id)
                ->where('status',1)
                ->update(['quantity'=>$sku_qty]);

              $response = array('status'=>'success','cartid'=>$cart_id,'message'=>'Item quantity successfully updated.'.$pricez);
            }
        }
        else{
          $response = array('status'=>'error','message'=>'Product does not exists.'.$product_exists);
        }

    }
    else{
      $response = array('status'=>'error','message'=>'Unable to add item to cart.');
    }
  }
  else{
    $response = array('status'=>'error','message'=>'Unable to add item to cart. Incorrect parameters');
  }

  return $response;
}]);

Route::get('bestdeals',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

    $query_str = 'SELECT
    t_variations.prod_type as ProductType,
    t_attributes.product_id as ProductID,
    concat(t_brands.brand," ",t_products.name) as ProductName,
    t_categories.category,
    t_categories.id as categoryid,
    t_variations.application as Application,
    t_variations.description as Description,
    t_variations.short_description as ShortDescription,
    t_variations.sku as SKU,
    t_variations.retail_price as RetailPrice,
    t_variations.price as Price,
    round((((t_variations.retail_price - t_variations.price) / t_variations.retail_price) * 100),0) as PercentSavings,
    t_variations.images as Images
    FROM t_variations
    JOIN t_attributes on t_attributes.id=t_variations.attribute_id
    JOIN t_products on t_products.id=t_attributes.product_id
    JOIN t_categories ON t_categories.id=t_products.category_id
    JOIN t_brands on t_brands.id=t_products.brand_id
    WHERE round((((retail_price - price) / retail_price) * 100),0)>=21 and round((((retail_price - price) / retail_price) * 100),0)<=29
    GROUP BY t_attributes.product_id
    ORDER BY rand()';

    $best_deals_result = DB::select($query_str);
    $a=0;$product_details=array();$onsale = false; $addsale = 0;
    foreach($best_deals_result as $bdr){

      $saleresponse = checkifsale($bdr->ProductID, date('Y-m-d'), date('Y-m-d'), $i = 'product');
      if(!empty($saleresponse)){
        //get the additional discount, this is to be added on product savings
        foreach($saleresponse as $sr){
          if(!empty($sr->percentage) and $sr->percentage!=0){
            $addsale = $sr->percentage;
            $onsale = true;
          }
        }
      }

      $productprice = round(trim($bdr->Price),0);
      $totalsavings = $bdr->PercentSavings;
      $srp = round(trim($bdr->RetailPrice),0);

      if($onsale==true){
        $price = round(trim($bdr->Price),0);
        $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
        $additionaldisc = round($salepercent * $price,0);
        $productprice = $price - $additionaldisc;
        $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
      }

      $product_id = taiga_crypt($bdr->ProductID,'e');
      $product_details[$a] = array(
        'application'=>$bdr->Application,
        'prod_type'=>$bdr->ProductType,
        'retail_price'=>number_format($srp,2),
        'price'=>number_format($productprice,2),
        'images'=>$bdr->Images);

      $best_deals[$a] = array(
        'ProductName'=>$bdr->ProductName,
        'ProductID'=>$product_id,
        'CategoryID'=>$bdr->categoryid,
        'Category'=>$bdr->category,
        'OnSale'=>$onsale,
        'ProductSavings'=>$totalsavings,
        'ProductDetails'=>array($product_details[$a]));
      $a++;
      $addsale=0;
    }

    $col = new Collection($best_deals); //convert array into laravel collection
    //$col = $col->sortBy('ProductName');
    //Slice array based on the items wish to show on each page and the current page number
    $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();
    //$col = unset($col['data']);
    //Save the Pagination in a variable which will be passed to view
    $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);


    return $prod_arr;
}]);

Route::get('computeshipping',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
  $cartid = isset($_GET['cart_id']) ? $_GET['cart_id'] : "";
  $freight = isset($_GET['freight_id']) ? $_GET['freight_id'] : 0;

  //get user id using uid
  $sql = 'SELECT id from users where remember_token="'.$uid.'"';
  $uinfo = DB::select($sql);
  $userid = "";
  foreach($uinfo as $u){
    if(!empty($u->id)){
      $userid = $u->id;
    }
  }

  if(!empty($userid) and !empty($cartid)){
    //first part, get the complete address of the user
    $query_str = 'SELECT
      t_users_info.users_id,
      t_users_info.unit_st_brgy,
      t_municipality_city.municipality_city,
      t_municipality_city.oda_fee,
      t_province.province,
      t_regions.region,
      t_regions.main_island_id
      FROM `t_users_info`
      JOIN t_municipality_city ON t_municipality_city.id=t_users_info.municipality_city_id
      JOIN t_province ON t_province.id=t_municipality_city.province_id
      JOIN t_regions ON t_regions.id=t_province.region_id
      WHERE t_users_info.users_id='.$userid.' AND t_users_info.set_default=1';

    //get user's region and main island
    $complete_address = DB::select($query_str);
    $region = array(); $oda_fee=0;
    foreach($complete_address as $ca){
      $region = array('Region'=>$ca->region,'MainIsland'=>$ca->main_island_id);

      //gets the oda fee
      if($ca->oda_fee>0 and $ca->oda_fee!=NULL and !empty($ca->oda_fee)){
        $oda_fee = $ca->oda_fee;
      }

    }

  //$region['Region']="Visayas";
  //second part, get the discount based on location

    //check if region is under NCR
    $is_ncr = "false";
    if($region['Region']=="NCR"){
      $is_ncr = "true";
      $result = array('Response'=>'NCR');
    }
    //else check what region and on what main island

    //query product orders based on cart id
    $query_prod_orders = 'SELECT
    t_product_orders.sku,
    t_product_orders.quantity,
    t_variations.cost,
    t_variations.price,
    t_products.shipping_ncr * t_product_orders.quantity AS total_shipping_ncr_per_item,
    t_products.shipping_luzon * t_product_orders.quantity AS total_shipping_luzon_per_item,
    t_products.shipping_visayas * t_product_orders.quantity AS total_shipping_visayas_per_item,
    t_products.shipping_mindanao * t_product_orders.quantity AS total_shipping_mindanao_per_item,
    t_products.shipping_ncr,
    t_products.shipping_luzon,
    t_products.shipping_visayas,
    t_products.shipping_mindanao,
    t_products.shipping,
    t_products.id,
    t_categories.category,
    t_categories.id as categoryid,
    t_product_orders.quantity * ROUND(t_variations.price,0) AS totalprice2,
    t_product_orders.price AS totalprice
    FROM t_product_orders
    JOIN t_variations ON t_variations.sku=t_product_orders.sku
    JOIN t_attributes ON t_attributes.id=t_variations.attribute_id
    JOIN t_products ON t_products.id=t_attributes.product_id
    JOIN t_categories ON t_categories.id=t_products.category_id
    WHERE cart_id='.$cartid.' and t_product_orders.status=1';

    $productorders = DB::select($query_prod_orders);

    $totalshipping=0;$discount=0;$totalprice=0;
    if($is_ncr=="true"){ //for ncr

      //gets total shipping of fixed shipping items
      $a=0;$totalprice=0;$fixedshippingprodid=array();
      //collect products with fix shipping
      //get shipping fee
      foreach($productorders as $po){
        if(!empty($po->sku)){
          $totalprice += str_replace(",","",$po->totalprice) * $po->quantity;

          if($po->shipping=="Fix" and !in_array($po->id,$fixedshippingprodid)){
            $totalshipping += trim($po->shipping_ncr);
            $fixedshippingprodid[$a] = $po->id;
          }
          elseif($po->shipping!="Fix" and !in_array($po->id,$fixedshippingprodid)){
            if($po->quantity>=2){ //computes if qty ordered is equal or more than 2
              $totalshipping += trim($po->shipping_ncr) + (trim($po->shipping_ncr)/trim($po->quantity));
            }
            else{
              $totalshipping += trim($po->shipping_ncr);
            }
          }

          $a++;
        }
      }

    }
    else{ //if location not ncr
      //get total price first
      $totalshippingluzon=0;$totalshippingvisayas=0;$totalshippingmindanao=0;$a=0;$fixedshippingprodid=array();
      foreach($productorders as $po){
        $totalprice += str_replace(",","",$po->totalprice) * $po->quantity;

        if($po->shipping=="Fix" and !in_array($po->id,$fixedshippingprodid)){
          $totalshippingluzon += trim($po->shipping_luzon);
          $totalshippingvisayas += trim($po->shipping_visayas);
          $totalshippingmindanao += trim($po->shipping_mindanao);

		      /*$totalshippingluzon += trim($po->total_shipping_luzon_per_item);
          $totalshippingvisayas += trim($po->total_shipping_visayas_per_item);
          $totalshippingmindanao += trim($po->total_shipping_mindanao_per_item);*/

          $fixedshippingprodid[$a] = $po->id;
        }
        elseif($po->shipping!="Fix" and !in_array($po->id,$fixedshippingprodid)){
          /*$totalshippingluzon += trim($po->shipping_luzon);
          $totalshippingvisayas += trim($po->shipping_visayas);
          $totalshippingmindanao += trim($po->shipping_mindanao);*/

		      $totalshippingluzon += trim($po->total_shipping_luzon_per_item);
          $totalshippingvisayas += trim($po->total_shipping_visayas_per_item);
          $totalshippingmindanao += trim($po->total_shipping_mindanao_per_item);
        }

        $a++;
      }

      $s = array('TotalPrice'=>$totalprice,'Loc'=>$region['MainIsland']);

      //determine range of total price
      $price_range = array();
      if($totalprice<=5000){
        $price_range=array('LuzonDiscount'=>'.12','VisayasDiscount'=>'.07','MindanaoDiscount'=>'.05');
      }
      elseif($totalprice>=5001 and $totalprice<=20000){
        $price_range=array('LuzonDiscount'=>'.17','VisayasDiscount'=>'.12','MindanaoDiscount'=>'.10');
      }
      elseif($totalprice>=20001 and $totalprice<=30000){
        $price_range=array('LuzonDiscount'=>'.22','VisayasDiscount'=>'.17','MindanaoDiscount'=>'.15');
      }
      elseif($totalprice>=30001 and $totalprice<=50000){
        $price_range=array('LuzonDiscount'=>'.27','VisayasDiscount'=>'.22','MindanaoDiscount'=>'.20');
      }
      elseif($totalprice>=50001 and $totalprice<=80000){
        $price_range=array('LuzonDiscount'=>'.32','VisayasDiscount'=>'.27','MindanaoDiscount'=>'.25');
      }
      elseif($totalprice>=80001){
        $price_range=array('LuzonDiscount'=>'.37','VisayasDiscount'=>'.32','MindanaoDiscount'=>'.30');
      }


      if($region['MainIsland']==1){ //if user is within luzon
        $discount = $price_range['LuzonDiscount'];
        $totalshipping = $totalshippingluzon;
      }
      elseif($region['MainIsland']==2){ //if user is within visayas
        $discount = $price_range['VisayasDiscount'];
        $totalshipping = $totalshippingvisayas;
      }
      elseif($region['MainIsland']==3){ //if user is within mindanao
        $discount = $price_range['MindanaoDiscount'];
        $totalshipping = $totalshippingmindanao;
      }

      //here, will impose the ODA
      $totalshipping += trim($oda_fee);

    }

	$gg=round(($discount * $totalshipping),0);

    $discountedshipping = $totalshipping - round(($discount * $totalshipping),0);

    $shipping = array(
      'TotalPrice'=>round($totalprice,0),
      'TotalShippingFee'=>0,
      'ShippingDiscount'=>0,
      'DiscountedShipping'=>0,
      'OdaFee'=>0,
      'TotalIncludingShipping'=>round($totalprice,0));

    if($freight==0 or $freight==""){
      $shipping = array(
        'TotalPrice'=>round($totalprice,0),
        'TotalShippingFee'=>round($totalshipping,0),
        'ShippingDiscount'=>$discount,
        'DiscountedShipping'=>round($discountedshipping,0),
        'OdaFee'=>round($oda_fee,0),
        'TotalIncludingShipping'=>round($totalprice+$discountedshipping,0));
    }

    $response = array('status'=>'success','message'=>'Returning shipping computation.','data'=>$shipping);
  }
  else{
    $response = array('status'=>'error','message'=>'Unable to request shipping computation.','data'=>'');
  }


  return $response;
}]);

Route::get('saleproducts',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

  $query_prod_sale = 'SELECT
  t_variations.prod_type AS ProductType,
  t_attributes.product_id AS ProductID,
  CONCAT(t_brands.brand," ",t_products.name) AS ProductName,
  t_categories.category,
  t_categories.id as categoryid,
  t_brands.brand AS ProductBrand,
  t_variations.retail_price AS ProductRetailPrice,
  t_variations.price AS ProductPrice,
  round((((retail_price-price)/retail_price)*100),0) AS ProductDiscount,
  t_variations.application as ProductApplication,
  t_variations.images AS ProductImages
  FROM `t_variations`
  JOIN t_attributes ON t_attributes.id=t_variations.attribute_id
  JOIN t_products ON t_products.id=t_attributes.product_id
  JOIN t_categories ON t_categories.id=t_products.category_id
  JOIN t_brands ON t_brands.id=t_products.brand_id
  WHERE (((retail_price-price)/retail_price)*100)>=30
  GROUP BY t_attributes.product_id
  ORDER BY rand()';

  $productsales = DB::select($query_prod_sale);

  $a=0;$prod_sales=array();$product_details=array(); $onsale = false; $addsale = 0;
  foreach($productsales as $bdr){

    $saleresponse = checkifsale($bdr->ProductID, date('Y-m-d'), date('Y-m-d'), $i = 'product');
    if(!empty($saleresponse)){
      //get the additional discount, this is to be added on product savings
      foreach($saleresponse as $sr){
        if(!empty($sr->percentage) and $sr->percentage!=0){
          $addsale = $sr->percentage;
          $onsale = true;
        }
      }
    }

    $productprice = round(trim($bdr->ProductPrice),0);
    $totalsavings = $bdr->ProductDiscount;
    $srp = round(trim($bdr->ProductRetailPrice),0);

    if($onsale==true){
      $price = round(trim($bdr->ProductPrice),0);
      $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
      $additionaldisc = round($salepercent * $price,0);
      $productprice = $price - $additionaldisc;
      $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
    }

    $product_id = taiga_crypt($bdr->ProductID,'e');
    $product_details[$a] = array(
      'application'=>$bdr->ProductApplication,
      'prod_type'=>$bdr->ProductType,
      'retail_price'=>number_format($srp,2),
      'price'=>number_format($productprice,2),
      'images'=>$bdr->ProductImages);

    $prod_sales[$a] = array(
      'ProductName'=>$bdr->ProductName,
      'ProductID'=>$product_id,
      'CategoryID'=>$bdr->categoryid,
      'Category'=>$bdr->category,
      'OnSale'=>$onsale,
      'ProductSavings'=>$totalsavings,
      'ProductDetails'=>array($product_details[$a]));
    $a++;
    $addsale=0;
  }

  $col = new Collection($prod_sales); //convert array into laravel collection
  //$col = $col->sortBy('ProductName');
  //Slice array based on the items wish to show on each page and the current page number
  $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();
  //$col = unset($col['data']);
  //Save the Pagination in a variable which will be passed to view
  $productsales = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

  return $productsales;
}]);

Route::get('bikesmotorcycles',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

  /*$query_prod_bikes = 'SELECT
  concat(t_brands.brand," ",t_products.name) AS ProductName,
  t_products.id AS ProductID,
  round((((t_variations.retail_price-t_variations.price)/t_variations.retail_price)*100),0) AS ProductSavings,
  t_variations.application AS ProductApplication,
  t_variations.prod_type AS ProductType,
  t_variations.retail_price AS ProductRetailPrice,
  t_variations.price AS ProductPrice,
  t_variations.images AS ProductImages
  FROM t_tags
  JOIN t_products ON t_products.id=t_tags.product_id
  JOIN t_brands ON t_brands.id=t_products.brand_id
  JOIN t_attributes ON t_attributes.product_id=t_products.id
  JOIN t_variations ON t_variations.attribute_id=t_attributes.id
  WHERE tag LIKE "%motorcycle%" OR tag LIKE "%bike%"
  GROUP BY t_products.id
  ORDER BY rand()';*/

  $query_prod_bikes = 'SELECT
  concat(t_brands.brand," ",t_products.name) AS ProductName,
  t_categories.category,
  t_categories.id as categoryid,
  t_products.id AS ProductID,
  round((((t_variations.retail_price-t_variations.price)/t_variations.retail_price)*100),0) AS ProductSavings,
  t_variations.application AS ProductApplication,
  t_variations.prod_type AS ProductType,
  t_variations.retail_price AS ProductRetailPrice,
  t_variations.price AS ProductPrice,
  t_variations.images AS ProductImages
  FROM t_products
  JOIN t_categories ON t_categories.id=t_products.category_id
  JOIN t_brands ON t_brands.id=t_products.brand_id
  JOIN t_attributes ON t_attributes.product_id=t_products.id
  JOIN t_variations ON t_variations.attribute_id=t_attributes.id
  WHERE t_products.tags LIKE "%motorcycle%" OR t_products.tags LIKE "%bike%"
  GROUP BY t_products.id
  ORDER BY rand()';

  $product_bikes = DB::select($query_prod_bikes);
  $a=0;$prod_sales=array();$product_details=array(); $onsale = false; $addsale = 0;
  foreach($product_bikes as $bdr){

    //fetch here if the category is on sale
    $saleresponse = checkifsale($bdr->ProductID, date('Y-m-d'), date('Y-m-d'), $i = 'product');
    if(!empty($saleresponse)){
      //get the additional discount, this is to be added on product savings
      foreach($saleresponse as $sr){
        if(!empty($sr->percentage) and $sr->percentage!=0){
          $addsale = $sr->percentage;
          $onsale = true;
        }
      }
    }

    $productprice = round(trim($bdr->ProductPrice),0);
    $totalsavings = $bdr->ProductSavings;
    $srp = round(trim($bdr->ProductRetailPrice),0);

    if($onsale==true){
      $price = round(trim($bdr->ProductPrice),0);
      $salepercent = strlen($addsale)>=2 ? ".".$addsale : ".0".$addsale;
      $additionaldisc = round($salepercent * $price,0);
      $productprice = $price - $additionaldisc;
      $totalsavings = round((($srp - $productprice) / $srp) * 100,0);
    }

    $product_id = taiga_crypt($bdr->ProductID,'e');
    $product_details[$a] = array(
      'application'=>$bdr->ProductApplication,
      'prod_type'=>$bdr->ProductType,
      'retail_price'=>number_format($srp,2),
      'price'=>number_format($productprice,2),
      'images'=>$bdr->ProductImages);

    $prod_sales[$a] = array(
      'ProductName'=>$bdr->ProductName,
      'ProductID'=>$product_id,
      'CategoryID'=>$bdr->categoryid,
      'Category'=>$bdr->category,
      'OnSale'=>$onsale,
      'ProductSavings'=>$totalsavings,
      'ProductDetails'=>array($product_details[$a]));
    $a++;

    $addsale = 0;
  }

  $col = new Collection($prod_sales); //convert array into laravel collection
  //$col = $col->sortBy('ProductName');
  //Slice array based on the items wish to show on each page and the current page number
  $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();
  //$col = unset($col['data']);
  //Save the Pagination in a variable which will be passed to view
  $productbikes = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

  return $productbikes;

}]);



Route::post('submitapplication',['middleware'=>'cors',function(Request $request){
  date_default_timezone_set("Asia/Singapore");
  $destinationPath = public_path('../../images/submitted-applications/');
  if(!empty(request('profile-image'))){
    $profimage = request('profile-image');
    $fileext2 = $profimage->getClientOriginalExtension();
    $proimg = "motors-image-".str_replace(" ","",request('first-name')).str_replace(" ","",request('last-name')).date("Ymdhis").'.'.$fileext2;
    $profimage->move($destinationPath, $proimg);
  }


  if(Input::hasFile('valid_id')){
    $images1=array();
    $files1 = $request->file('valid_id');
      foreach($files1 as $file){
          $name1 = "motors-id-".str_replace(" ","",request('first-name')).str_replace(" ","",request('last-name')).date("Ymdhis").'.'.$file->getClientOriginalName();
          $images1[]=$name1;
          $file->move($destinationPath,$name1);
      }
  }

  $prod_arr = array('status'=>'error','message'=>'Unable to submit application.');
        $sa = new SubmittedApplications;
        $sa->first_name = request('first-name');
        $sa->middle_name = request('middle-name');
        $sa->last_name = request('last-name');
        $sa->birthdate = request('birth-date');
        $sa->birthplace = request('birth-place');
        $sa->gender = request('gender');
        $sa->sss_gsis = request('sss-gsis');
        $sa->tin = request('tin');
        $sa->marital_status = request('marital-status');
        $sa->address = request('address');
        $sa->province = request('province');
        $sa->ownership = request('residence-ownership');
        $sa->ownership_others = request('residence-ownership-other');
        $sa->yrs_of_stay = request('years_of_stay');
        $sa->spouse = request('spouse-name');
        $sa->no_of_children = request('no-of-children');
        $sa->no_of_dependents = request('no-of-dependents');
        $sa->mother_maiden_name = request('mothers-maiden-name');
        $sa->source_of_income = request('source-of-income');
        $sa->source_of_income_other = request('source-of-income-other');
        $sa->monthly_gross_income = request('monthly-gross-income');
        $sa->yrs_in_current_employer = request('years-of-employment');
        $sa->company = request('company-business');
        $sa->position = request('position');
        $sa->employment_status = request('employment-status');
        $sa->employment_status_other = request('employment-status-other');
        $sa->mobile_no = request('personal-mobile');
        $sa->landline = request('home-landline');
        $sa->email = request('email');
        $sa->office_no = request('office-no');
        $sa->fb_link = request('facebook-id');
        $sa->ref_name1 = request('reference-name-1');
        $sa->ref_address1 = request('reference-address-1');
        $sa->ref_contact1 = request('reference-contact-1');
        $sa->ref_name2 = request('reference-name-2');
        $sa->ref_address2 = request('reference-address-2');
        $sa->ref_contact2 = request('reference-contact-2');
        $sa->product_id = taiga_crypt(request('product-id'), 'd');
        $sa->qty = request('product-qty');
        $sa->color = request('product-color');
        $sa->terms_id = request('product-terms');
        $sa->user_id = 1;
        $sa->store_id = request('store-id');

        //$sa->user_form_no = request('store-id').''.$sa->id;


        if(!empty(request('profile-image'))){
          $sa->uploaded_picture = $proimg;
        }

        $sa->valid_id = implode(",", $images1);

        $sa->save();
    if(!empty($sa->id)){

      $to = request('main-branch-email').','.request('main-branch-email').',motorcycle@taiga.com.ph';
      $from = 'sales@taiga.com.ph';
      $subject = 'You have an order from Motorcycle Store. E-Application Form No. (' . $sa->id . ')';

      // To send HTML mail, the Content-type header must be set
      $headers[] = 'MIME-Version: 1.0';
      $headers[] = 'Content-type: text/html; charset=iso-8859-1';

      // Additional headers
      $headers[] = 'To: <'.$to.'>';
      $headers[] = 'From: Taiga.com.ph <'.$from.'>';

      $body = '
      <table>
        <tr>
          <th colspan="2" style="text-center">'.request('email-product-name').'<th>
        </tr>
        <tr>
          <th>Product Image</th><td><img src="'.request('email-product-image').'" width="200" alt=""></td>
        </tr>
        <tr>
          <th>Brand</th><td>'.request('email-product-brand').'</td>
        </tr>
        <tr>
          <th>Color</th><td>'.request('product-color').'</td>
        </tr>
        <tr>
          <th>Qty</th><td>'.request('product-qty').'</td>
        </tr>
        <tr>
          <th>Installment Plan</th><td>'.request('product-terms').' month/s</td>
        </tr>
        <tr>
          <th>Full Name</th><td>'.request('first-name').' '.request('middle-name').' '.request('last-name').'</td>
        </tr>
        <tr>
          <th>Permanent Address</th><td>'.request('address').' '.App\Province::find(request('province'))->province.'</td>
        </tr>
        <tr>
          <th>Contact Details</th><td>'.request('personal-mobile').'/'.request('home-landline').'</td>
        </tr>
        <tr>
          <th>Link</th><td><a href="http://dev-cms.taiga.com.ph/viewapplicationdetails/'.$sa->id.'">E-Application Form No.' .$sa->id.'</a></td>
        </tr>
      </table>
      ';
      mail($to, $subject, $body, implode("\r\n", $headers));
      $prod_arr = array('status'=>'success','message'=>'Successfully submitted application.');
    }
    else{
      $prod_arr = array('status'=>'error','message'=>$err_msg);
    }
   return $prod_arr;
}]);



Route::get('motordefaultlist',['middleware'=>'cors',function(){
  date_default_timezone_set("Asia/Singapore");

  //get all products that has a type=0 and status=1
  //return only product name, lowest price, image, id

  $prod_arr = array('status'=>'error','message'=>'Nothing to fetch.');

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

  //query to fetch all needed infos
  //should add to condition product stocks
  $sql='SELECT
        t_products.id,
        t_products.name,
        t_brands.brand,
        t_categories.category,
        t_variations.application,
        t_variations.prod_type,
        t_variations.retail_price,
        t_variations.price,
        t_variations.images
        FROM t_products
        LEFT JOIN t_brands ON t_brands.id=t_products.brand_id
        JOIN t_categories ON t_categories.id=t_products.category_id
        JOIN t_attributes ON t_attributes.product_id=t_products.id
        JOIN t_variations ON t_variations.attribute_id=t_attributes.id
        WHERE t_products.type=0 AND t_products.status<>0
        GROUP BY t_products.id
        ORDER BY RAND()';

  $result = DB::select($sql);
  $prod_arr = array();$ProductDetails=array();$a=0;
  foreach($result as $r){
    $product_id = taiga_crypt($r->id,'e');

    $difference = (double)$r->retail_price - (double)$r->price;
    $retail_price = (integer)$r->retail_price;

    $savings = 0;
    if($retail_price>0 and $difference>0){
        $savings = ($difference / $retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
    }

    $savings = round($savings,0);

    $ProductDetails[$a] = array(
              'application'=>$r->application,
              'prod_type'=>$r->prod_type,
              'retail_price'=>number_format(round($r->retail_price,0),2),
              'price'=>number_format(round($r->price,0),2),
              'images'=>$r->images
            );

    $prod_arr[$a] = array(
      'ProductName'=>$r->brand.' '.$r->name,
      'ProductID'=>$product_id,
      'ProductCategory'=>$r->category,
      'ProductSavings'=>$savings,
      'ProductDetails'=>array($ProductDetails[$a]));

    $a++;
  }


    $col = new Collection($prod_arr); //convert array into laravel collection

    //Slice array based on the items wish to show on each page and the current page number
    $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

    //Save the Pagination in a variable which will be passed to view
    //second param will be changed by variable $a instead of count($col)
    $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);


    return $prod_arr;
}]);

Route::get('filtermotorproducts',['middleware'=>'cors',function(Request $request){
  $prod_arr = array('status'=>'error','message'=>'Nothing to fetch.');

  //get all product id that has province and municipality id
  $search = isset($_GET['q']) ? $_GET['q'] : "";
  $limit = isset($_GET['count']) ? ' ORDER BY RAND() LIMIT '.$_GET['count'] : " ORDER BY RAND()";

  if($search!="" or !empty(request('province')) or !empty(request('municipality'))){ //if filtering or searching with/without filter
    if($search==""){ //if not searching but only filtering

        if(!empty(request('province')) and (empty(request('municipality')) or request('municipality')=="null")){
          //if province is the only available filter

            $sql = 'SELECT
                    t_stores.vendor_code_main,
                    t_store_products.id,
                    t_store_products.store_id,
                    t_store_products.frame,
                    t_store_products.profile_image,
                    t_store_products.status,
                    t_store_products.price,
                    t_store_products.stock_allocation,
                    t_store_products.terms_id,
                    t_store_products.monthly,
                    t_store_products.monthly2,
                    t_store_products.monthly3,
                    t_store_products.monthly4,
                    t_store_products.monthly5,
                    t_store_products.downpayment,
                    t_store_products.downpayment2,
                    t_store_products.downpayment3,
                    t_store_products.downpayment4,
                    t_store_products.downpayment5,
                    t_submitted_products.prod_type,
                    t_submitted_products.brand_name,
                    t_submitted_products.product_name,
                    t_submitted_products.short_description,
                    t_submitted_products.description,
                    t_submitted_products.tags,
                    t_submitted_products.image,
                    t_submitted_products.product_condition
                    FROM t_stores
                    INNER JOIN t_store_products ON t_store_products.store_id=t_stores.id
                    INNER JOIN t_submitted_products ON t_submitted_products.id=t_store_products.product_id
                    WHERE t_stores.province_id='.request('province').'
                    OR t_stores.vendor_code_main = "RPL"
                    AND t_store_products.status=1'. $limit;
        }
        elseif(!empty(request('province')) and !empty(request('municipality'))){
          $sql = 'SELECT
                  t_stores.vendor_code_main,
                  t_store_products.id,
                  t_store_products.store_id,
                  t_store_products.frame,
                  t_store_products.profile_image,
                  t_store_products.status,
                  t_store_products.price,
                  t_store_products.stock_allocation,
                  t_store_products.terms_id,
                  t_store_products.monthly,
                  t_store_products.monthly2,
                  t_store_products.monthly3,
                  t_store_products.monthly4,
                  t_store_products.monthly5,
                  t_store_products.downpayment,
                  t_store_products.downpayment2,
                  t_store_products.downpayment3,
                  t_store_products.downpayment4,
                  t_store_products.downpayment5,
                  t_submitted_products.prod_type,
                  t_submitted_products.brand_name,
                  t_submitted_products.product_name,
                  t_submitted_products.short_description,
                  t_submitted_products.description,
                  t_submitted_products.tags,
                  t_submitted_products.image,
                  t_submitted_products.product_condition
                  FROM t_stores
                  INNER JOIN t_store_products ON t_store_products.store_id=t_stores.id
                  INNER JOIN t_submitted_products ON t_submitted_products.id=t_store_products.product_id
                  WHERE t_stores.province_id='.request('province').'
                  AND t_stores.municipality_id='.request('municipality').'
                  OR t_stores.vendor_code_main = "RPL"
                  AND t_store_products.status=1'.$limit;
        }

        $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
        $perPage = 25; //set number of records per page

        //need to check how to use this
        //$product_id = taiga_crypt($r->id,'e');

        $prod_arr = DB::select($sql);$pa_arr=array();$aa=0;
        foreach($prod_arr as $pa){
          $pa_arr[$aa]['id'] = taiga_crypt($pa->id,'e');
          $pa_arr[$aa]['store_id'] = $pa->store_id;
          $pa_arr[$aa]['frame'] = $pa->frame;
          $pa_arr[$aa]['profile_image'] = $pa->profile_image;
          $pa_arr[$aa]['vendor_code_main'] = $pa->vendor_code_main;
          $pa_arr[$aa]['status'] = $pa->status;
          $pa_arr[$aa]['price'] = $pa->price;
          $pa_arr[$aa]['stock_allocation'] = $pa->stock_allocation;
          $pa_arr[$aa]['product_condition'] = $pa->product_condition;
          $pa_arr[$aa]['terms_id'] = $pa->terms_id;
          $pa_arr[$aa]['monthly'] = $pa->monthly;
          $pa_arr[$aa]['monthly2'] = $pa->monthly2;
          $pa_arr[$aa]['monthly3'] = $pa->monthly3;
          $pa_arr[$aa]['monthly4'] = $pa->monthly4;
          $pa_arr[$aa]['monthly5'] = $pa->monthly5;
          $pa_arr[$aa]['downpayment'] = $pa->downpayment;
          $pa_arr[$aa]['downpayment2'] = $pa->downpayment2;
          $pa_arr[$aa]['downpayment3'] = $pa->downpayment3;
          $pa_arr[$aa]['downpayment4'] = $pa->downpayment4;
          $pa_arr[$aa]['downpayment5'] = $pa->downpayment5;
          $pa_arr[$aa]['prod_type'] = $pa->prod_type;
          $pa_arr[$aa]['brand_name'] = $pa->brand_name;
          $pa_arr[$aa]['product_name'] = $pa->product_name;
          $pa_arr[$aa]['short_description'] = $pa->short_description;
          $pa_arr[$aa]['description'] = $pa->description;
          $pa_arr[$aa]['tags'] = $pa->tags;
          $pa_arr[$aa]['image'] = $pa->image;
          $aa++;
        }

        $col = new Collection($pa_arr); //convert array into laravel collection

        //Slice array based on the items wish to show on each page and the current page number
        $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

        //Save the Pagination in a variable which will be passed to view
        //second param will be changed by variable $a instead of count($col)
        $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

    }
    else{ //if searching with filter
        //$sql = 'SELECT id as product_id FROM t_products WHERE tags like "%'.$search.'%" AND type=1 AND status<>0';

        $sql = 'SELECT
                t_stores.vendor_code_main,
                t_store_products.id,
                t_store_products.store_id,
                t_store_products.frame,
                t_store_products.profile_image,
                t_store_products.status,
                t_store_products.price,
                t_store_products.stock_allocation,
                t_store_products.terms_id,
                t_store_products.monthly,
                t_store_products.monthly2,
                t_store_products.monthly3,
                t_store_products.monthly4,
                t_store_products.monthly5,
                t_store_products.downpayment,
                t_store_products.downpayment2,
                t_store_products.downpayment3,
                t_store_products.downpayment4,
                t_store_products.downpayment5,
                t_submitted_products.prod_type,
                t_submitted_products.brand_name,
                t_submitted_products.product_name,
                t_submitted_products.short_description,
                t_submitted_products.description,
                t_submitted_products.tags,
                t_submitted_products.image,
                t_submitted_products.product_condition
                FROM t_stores
                INNER JOIN t_store_products ON t_store_products.store_id=t_stores.id
                INNER JOIN t_submitted_products ON t_submitted_products.id=t_store_products.product_id
                WHERE t_submitted_products.tags like "%'.$search.'%"
                AND t_stores.vendor_code_main = "RPL"
                AND t_store_products.status=1'.$limit;

        $add_cond = '';

        if(!empty($search) and !empty(request('province')) and empty(request('municipality'))){
            $add_cond = 'AND t_stores.province_id='.request('province');
        }
        elseif(!empty($search) and empty(request('province')) and !empty(request('municipality'))){
            $add_cond = 'AND t_stores.municipality_id='.request('municipality');
        }
        elseif(!empty($search) and !empty(request('province')) and !empty(request('municipality'))){
            $add_cond = 'AND t_stores.province_id='.request('province').'
                        AND t_stores.municipality_id='.request('municipality');
        }

        $sql = $sql.$add_cond;

        $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
        $perPage = 25; //set number of records per page

        $prod_arr = DB::select($sql); $pa_arr=array();$aa=0;
        foreach($prod_arr as $pa){
          $pa_arr[$aa]['id'] = taiga_crypt($pa->id,'e');
          $pa_arr[$aa]['store_id'] = $pa->store_id;
          $pa_arr[$aa]['frame'] = $pa->frame;
          $pa_arr[$aa]['profile_image'] = $pa->profile_image;
          $pa_arr[$aa]['vendor_code_main'] = $pa->vendor_code_main;
          $pa_arr[$aa]['status'] = $pa->status;
          $pa_arr[$aa]['price'] = $pa->price;
          $pa_arr[$aa]['stock_allocation'] = $pa->stock_allocation;
          $pa_arr[$aa]['product_condition'] = $pa->product_condition;
          $pa_arr[$aa]['terms_id'] = $pa->terms_id;
          $pa_arr[$aa]['monthly'] = $pa->monthly;
          $pa_arr[$aa]['monthly2'] = $pa->monthly2;
          $pa_arr[$aa]['monthly3'] = $pa->monthly3;
          $pa_arr[$aa]['monthly4'] = $pa->monthly4;
          $pa_arr[$aa]['monthly5'] = $pa->monthly5;
          $pa_arr[$aa]['downpayment'] = $pa->downpayment;
          $pa_arr[$aa]['downpayment2'] = $pa->downpayment2;
          $pa_arr[$aa]['downpayment3'] = $pa->downpayment3;
          $pa_arr[$aa]['downpayment4'] = $pa->downpayment4;
          $pa_arr[$aa]['downpayment5'] = $pa->downpayment5;
          $pa_arr[$aa]['prod_type'] = $pa->prod_type;
          $pa_arr[$aa]['brand_name'] = $pa->brand_name;
          $pa_arr[$aa]['product_name'] = $pa->product_name;
          $pa_arr[$aa]['short_description'] = $pa->short_description;
          $pa_arr[$aa]['description'] = $pa->description;
          $pa_arr[$aa]['tags'] = $pa->tags;
          $pa_arr[$aa]['image'] = $pa->image;
          $aa++;
        }

        $col = new Collection($pa_arr); //convert array into laravel collection

        //Slice array based on the items wish to show on each page and the current page number
        $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

        //Save the Pagination in a variable which will be passed to view
        //second param will be changed by variable $a instead of count($col)
        $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);
    }

  }
  else{ //default listing
    $prod_arr = motordefaultlist($limit);
  }


    return $prod_arr;
}]);

function motordefaultlist($limit){
  date_default_timezone_set("Asia/Singapore");

  //get all products that has a type=0 and status=1
  //return only product name, lowest price, image, id

  $prod_arr = array('status'=>'error','message'=>'Nothing to fetch.');

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

  //query to fetch all needed infos
  //should add to condition product stocks
  $sql = 'SELECT
          t_stores.vendor_code_main,
          t_store_products.id,
          t_store_products.store_id,
          t_store_products.frame,
          t_store_products.profile_image,
          t_store_products.status,
          t_store_products.price,
          t_store_products.stock_allocation,
          t_store_products.terms_id,
          t_store_products.monthly,
          t_store_products.monthly2,
          t_store_products.monthly3,
          t_store_products.monthly4,
          t_store_products.monthly5,
          t_store_products.downpayment,
          t_store_products.downpayment2,
          t_store_products.downpayment3,
          t_store_products.downpayment4,
          t_store_products.downpayment5,
          t_submitted_products.prod_type,
          t_submitted_products.brand_name,
          t_submitted_products.product_name,
          t_submitted_products.short_description,
          t_submitted_products.description,
          t_submitted_products.tags,
          t_submitted_products.image,
          t_submitted_products.product_condition
          FROM t_stores
          INNER JOIN t_store_products ON t_store_products.store_id=t_stores.id
          INNER JOIN t_submitted_products ON t_submitted_products.id=t_store_products.product_id
          WHERE t_store_products.status=1'. $limit;

  $prod_arr = DB::select($sql);$pa_arr=array();$aa=0;
  foreach($prod_arr as $pa){
    $pa_arr[$aa]['id'] = taiga_crypt($pa->id,'e');
    $pa_arr[$aa]['store_id'] = $pa->store_id;
    $pa_arr[$aa]['frame'] = $pa->frame;
    $pa_arr[$aa]['profile_image'] = $pa->profile_image;
    $pa_arr[$aa]['vendor_code_main'] = $pa->vendor_code_main;
    $pa_arr[$aa]['status'] = $pa->status;
    $pa_arr[$aa]['price'] = $pa->price;
    $pa_arr[$aa]['stock_allocation'] = $pa->stock_allocation;
    $pa_arr[$aa]['product_condition'] = $pa->product_condition;
    $pa_arr[$aa]['terms_id'] = $pa->terms_id;
    $pa_arr[$aa]['monthly'] = $pa->monthly;
    $pa_arr[$aa]['monthly2'] = $pa->monthly2;
    $pa_arr[$aa]['monthly3'] = $pa->monthly3;
    $pa_arr[$aa]['monthly4'] = $pa->monthly4;
    $pa_arr[$aa]['monthly5'] = $pa->monthly5;
    $pa_arr[$aa]['downpayment'] = $pa->downpayment;
    $pa_arr[$aa]['downpayment2'] = $pa->downpayment2;
    $pa_arr[$aa]['downpayment3'] = $pa->downpayment3;
    $pa_arr[$aa]['downpayment4'] = $pa->downpayment4;
    $pa_arr[$aa]['downpayment5'] = $pa->downpayment5;
    $pa_arr[$aa]['prod_type'] = $pa->prod_type;
    $pa_arr[$aa]['brand_name'] = $pa->brand_name;
    $pa_arr[$aa]['product_name'] = $pa->product_name;
    $pa_arr[$aa]['short_description'] = $pa->short_description;
    $pa_arr[$aa]['description'] = $pa->description;
    $pa_arr[$aa]['tags'] = $pa->tags;
    $pa_arr[$aa]['image'] = $pa->image;
    $aa++;
  }

  $col = new Collection($pa_arr); //convert array into laravel collection

  //Slice array based on the items wish to show on each page and the current page number
  $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

  //Save the Pagination in a variable which will be passed to view
  //second param will be changed by variable $a instead of count($col)
  $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);


  return $prod_arr;
}
