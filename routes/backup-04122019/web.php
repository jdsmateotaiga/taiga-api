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

Route::get('/', function () {
    return view('welcome');
});

Route::get('products/', 'ProductsController@index');

Route::get('getprovincials',['middleware'=>'cors',function(){
  $province = \App\Province::select('id','province')->orderBy('province','ASC')->get();
  return $province;
}]);

Route::get('getmunicipalities',['middleware'=>'cors',function(){
  $province_id = isset($_GET['id']) ? $_GET['id'] : "";
  $municipalities = \App\Municipalities::select('id','municipality_city')->where('province_id','=',$province_id)->get();
  return $municipalities;
}]);

Route::get('getrefno',['middleware'=>'cors',function(){
    //create settings table
    $merchantId = "TAIGA";
    $key        = "7n19ooLyfzv94s1";
    $host       = "test.dragonpay.ph";
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

          $soapUrl = "https://test.dragonpay.ph/DragonPayWebService/MerchantService.asmx?op=GetTxnRefNo";

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
  //create settings table
  $merchantId = "TAIGA";
  $key        = "7n19ooLyfzv94s1";
  $host       = "test.dragonpay.ph";
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

    $soapUrl = "https://test.dragonpay.ph/DragonPayWebService/MerchantService.asmx?op=GetAvailableProcessors";

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
  //create settings table
  $merchantId = "TAIGA";
  $key        = "7n19ooLyfzv94s1";
  $host       = "test.dragonpay.ph";

  $xmlstr = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <GetProcessors xmlns="http://api.dragonpay.ph/" />
  </soap:Body>
</soap:Envelope>';

    $soapUrl = "https://test.dragonpay.ph/DragonPayWebService/MerchantService.asmx?op=GetProcessors";

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
    $orderno = isset($_GET['orderno']) ? $_GET['orderno'] : "";

    $sql = 'SELECT
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

Route::post('placeorder',['middleware'=>'cors',function(){
  $cartid = isset($_POST['cart_id']) ? $_POST['cart_id'] : "";
  $uid = isset($_POST['uid']) ? $_POST['uid'] : "";
  $procid = isset($_POST['procid']) ? $_POST['procid'] : "";
  $amount = isset($_POST['amount']) ? $_POST['amount'] : "";
  $desc = isset($_POST['description']) ? $_POST['description'] : "";
  $email = isset($_POST['email']) ? $_POST['email'] : "";
  $notes = isset($_POST['order_notes']) ? $_POST['order_notes'] : "";
  //$payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : "";

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

  if($procid=="CODT"){ //if payment is COD
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
                'ship_to_st_brgy'=>$unit_st_brgy,
                'ship_to_municipality'=>$municipality_city_id,
                'ship_to_province'=>$province_id]);
  }
  else{ //if payment is thru dragonpay
    $updateorder = DB::table('t_carts')
      ->where('id',$cartid)
      ->where('users_id',$user_id)
      ->update(['order_no'=>$def_order_no,
                'order_notes'=>$notes,
                'order_description'=>$desc,
                'payment_method'=>$procid,
                'date_time'=> date("Y-m-d h:i:s"),
                'total_payment' => number_format($amount,2,'.',''),
                'ship_to_st_brgy'=>$unit_st_brgy,
                'ship_to_municipality'=>$municipality_city_id,
                'ship_to_province'=>$province_id]);
  }


    $url = "http://staging-initial.taiga.com.ph/confirm?txnid=".$def_order_no;//used for dragonpay method
    if($procid!='CODT'){
      //generate url for dragon pay
      $params = array(
            'merchantid' => 'TAIGA',
            'txnid'      => $def_order_no,
            'amount'     => number_format($amount,2,'.',''),
            'ccy'        => 'PHP',
            'description'=> $desc,
            'email'      => $email,
            'key'        => '7n19ooLyfzv94s1'
         );

       $digest = implode(':',$params);
       unset($params['key']);

       $params['digest'] = sha1($digest);
       $url = 'https://test.dragonpay.ph/Pay.aspx?'.http_build_query($params).'&procid='.$procid;
    }

     $response = array('status'=>'success','message'=>'Successfully submitted cart for order.','data'=>array('txnid'=>$def_order_no,'url'=>$url));

     return $response;
}]);

Route::get('getamountbyorderno',['middleware'=>'cors',function(){
  $orderno = isset($_GET['orderno']) ? $_GET['orderno'] : "";

  $response = array('status'=>'error','message'=>'No amount fetched.','data'=>'');
  if(!empty($orderno)){
    $data = \App\Carts::select('total_payment')->where('order_no','=',$orderno)->get();
    $response = array('status'=>'success','message'=>'Successfully fetched total amount','data'=>$data);
  }

  return $response;
}]);

Route::get('cartlist',['middleware'=>'cors',function(){
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
  //query all from product order where cart id condition
  if($cart_id!=""){
    $sql = 'SELECT
    t_products.id,
    concat(t_products.name," ",t_brands.brand) as productname,
    t_product_orders.sku,
    t_product_orders.quantity,
    t_variations.price,
    t_variations.images
    FROM t_product_orders
    JOIN t_variations ON t_variations.sku=t_product_orders.sku
    JOIN t_attributes ON t_attributes.id=t_variations.attribute_id
    JOIN t_products   ON t_products.id=t_attributes.product_id
    JOIN t_brands     ON t_brands.id=t_products.brand_id
    WHERE t_product_orders.cart_id='.$cart_id.' AND t_product_orders.status=1';

    $cart_products = DB::select($sql);

    $products = array(); $a=0;
    foreach($cart_products as $cp){
      if(!empty($cp->id)){
        //fetch variations by product id
        $sql_str = 'SELECT t_attributes.id,t_attributes.product_id,t_variations.sku
        FROM t_attributes
        JOIN t_variations ON t_variations.attribute_id=t_attributes.id
        WHERE t_attributes.product_id='.$cp->id.'
        ORDER BY cast(t_variations.price as unsigned) ASC, t_attributes.id ASC';
        $variations = DB::select($sql_str);
        $b=0;$variation_index=0;
        foreach($variations as $v){
          //if equal to $cp->sku, then get variation index
          if($v->sku==$cp->sku){
            $variation_index = $b;
          }

          $b++;
        }


        $prod_id = taiga_crypt($cp->id,'e');
        //explode images
        $img = explode(",",$cp->images);

        $products[$a] = array(
          'variation_index'=>$variation_index,
          'id'=>$prod_id,
          'productname'=>$cp->productname,
          'sku'=>$cp->sku,
          'quantity'=>$cp->quantity,
          'price'=>number_format(round($cp->price,0),2),
          'images'=>$img[0]
        );
        $a++;
      }
    }

    $response = array('status'=>'success','message'=>'Listing cart products.','cartid'=>$cart_id,'data'=>$products);
  }

  return $response;
}]);

Route::get('removefromcart',['middleware'=>'cors',function(){
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

Route::post('setdefaultaddress',['middleware'=>'cors',function(){
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

Route::post('newaddress',['middleware'=>'cors',function(){
    $uid = isset($_POST['uid']) ? $_POST['uid'] : "";
    $add_name = isset($_POST['addname']) ? $_POST['addname'] : "";
    $unit_st_brgy = isset($_POST['usb']) ? $_POST['usb'] : "";
    $municipality = isset($_POST['municipality']) ? $_POST['municipality'] : "";
    $province = isset($_POST['province']) ? $_POST['province'] : "";
    $has_existing = "false"; $default_address = 1;

    if(!empty($unit_st_brgy) and $municipality>0 and is_numeric($municipality) and $province>0 and is_numeric($province)){

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

    }else{
      $response = array('status'=>'error','message'=>'Incorrect format of address.');
    }

    return $response;
}]);

//create related products api with product id parameter
Route::get('getrelatedproducts',['middleware'=>'cors',function(){
    $product_id = isset($_GET['product_id']) ? $_GET['product_id'] : "";
    $product_id = taiga_crypt($product_id,'d');
    //get first tag using product id from tags table order by tag ranking asc
    $tag = \App\Tags::where('product_id',$product_id)->orderBy('tag_ranking','ASC')->take(1)->get();

    foreach($tag as $t){
      if(!empty($t->tag)) $is_tag = $t->tag;
    }

    //fetch product ids from tags table having the same tag
    $products = DB::table('t_tags')
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
          ->where('t_tags.product_id','<>',$product_id)
          ->join('t_products','t_products.id','=','t_tags.product_id')
          ->join('t_attributes','t_attributes.product_id','=','t_products.id')
          ->join('t_variations','t_variations.attribute_id','=','t_attributes.id')
          ->groupBy('t_tags.product_id')
          ->take(15)
          ->get();

    $x=0;
    foreach($products as $p){
      $product_id = taiga_crypt($p->product_id,'e');

      $difference = (double)$p->retail_price - (double)$p->price;
      $savings = "";
      if($p->retail_price>0){
          $savings = ($difference / (integer)$p->retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
      }

      $savings = round($savings,0);

      $product_list[$x] = array(
        'Status'=>'1',
        'Message'=>'Returned result set.',
        'ProductID'=>$product_id,
        'ProductName'=>$p->name,
        'ProductSKU'=>$p->sku,
        'ProductType'=>$p->prod_type,
        'PartNo'=>$p->part_no,
        'ShortDescription'=>$p->short_description,
        'Description'=>$p->description,
        'Application'=>$p->application,
        'RetailPrice'=>number_format(round($p->retail_price,0),2),
        'Price'=>number_format(round($p->price,0),2),
        'Savings'=>$savings,
        'Stocks'=>$p->stocks,
        'InStock'=>$p->in_stock,
        'Images'=>$p->images);
      $x++;
    }

    if(empty($product_list)){
      $product_list = array('Status'=>'0','Message'=>'No result found.');
    }

    return $product_list;
}]);

Route::get('productsbybrand', ['middleware'=>'cors',function(){
  $id = isset($_GET['brand_id']) ? $_GET['brand_id'] : "";
  $id = taiga_crypt($id,'d'); //decrypt the product id

  $prodbybrand = \App\Products::where('brand_id',$id)->get();

  return $prodbybrand;
}]);

Route::get('allcategories', ['middleware'=>'cors',function(){
  $categories = \App\Categories::select('id','category','slug')->get();
  $cat_arr = array(); $a=0;
    // $r = array('category'=>);
    $no_products = array();$c=array();
    foreach($categories as $cats){
        //get number of products under the category
        $query_str = 'select count(*) as product_count from t_products where category_id='.$cats->id;
        $product_count = DB::select($query_str);
        foreach($product_count as $pc){
          $prod_count_cat = $pc->product_count;
        }

        $subcats = \App\Sub_Category::where('category_id',$cats->id)->get();
        $new_subcat = array();
        $b=0;$total=0;
        foreach($subcats as $sc){
            $no_products[$b] = \App\Products::where('sub_category_id',$sc->id)->get();
            $c[$b] = count($no_products[$b]); //count number of products under that sub category

            $encrypted_sub_id = taiga_crypt($sc->id,'e');
            $new_subcat[$b] = array('id'=>$encrypted_sub_id,'sub_category'=>$sc->sub_category,'slug'=>$sc->slug,'productcount'=>$c[$b]);

            $total += $c[$b];
            $b++;
        }

        $encrypted_cat_id = taiga_crypt($cats->id,'e');
        $cat_arr['Categories'][$a] = array('Category'=>$cats->category,'id'=>$encrypted_cat_id,'slug'=>$cats->slug,'totalproducts'=>$prod_count_cat,'SubCategories'=>$new_subcat);

     $a++;
    }

    return $cat_arr;
}]);

Route::get('productdetails', ['middleware'=>'cors',function(){
      $pid = isset($_GET['pid']) ? $_GET['pid'] : "";
      $id = taiga_crypt($pid,'d'); //decrypt the product id
      //should add result if no record found

      $product = \App\Products::where('id',$id)->get();
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

      $product_info=array();$a=0;$product_infos = array();
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
                        'retail_price'=>number_format(round($pi->retail_price,0),2),
                        'price'=>number_format(round($pi->price,0),2),
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

        $brand_name = "";
        foreach($product as $p){
            $brand = \App\Brands::where('id',$p->brand_id)->get();
            foreach($brand as $b){
              if(!empty($b->brand)){
                $brand_name = $b->brand;
              }
            }
        }

        //check if has related products
        $tag = \App\Tags::where('product_id',$id)->orderBy('tag_ranking','ASC')->take(1)->get();
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

        $rp = '0';
        if(!empty($related_products)){
          $rp = count($related_products);
        }

        $result = array(
          'RelatedProducts'=>$rp,
          'ProductName'=>$brand_name.' '.$p->name,
          'ProductBrand'=>$brand_name,
          'ProductId'=>$p->id,
          'VendorCode'=>$p->vendor_code,
          'VariationBy'=>ucfirst($variation),
          'Variations'=>$product_infos);

        return $result;
}]);

Route::get('productsbycategory', ['middleware'=>'cors',function(){
  $id = !empty($_GET['cid']) ? $_GET['cid'] : "";
  $prod_arr = array('Status'=>'Nothing to fetch.');

    if(!empty($id)){
      $id = taiga_crypt($id,'d');
      $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
      $perPage = 25; //set number of records per page

      $prodbycategory = \App\Products::where('category_id',$id)->get();
      $prod_arr = array(); $a=0;
        foreach($prodbycategory as $prods){

            $variation = \App\Attributes::where('product_id',$prods->id)->take(1)->get();
            foreach($variation as $v){
                $v_id = $v->id;
            }

            //get product brand
            $brands = \App\Brands::where('id',$prods->brand_id)->take(1)->get();
            $brand = "";
            foreach($brands as $b){
              if(!empty($b->brand)){
                $brand = $b->brand;
              }
            }

            //get product category
            $category = \App\Categories::where('id',$prods->category_id)->get();
            if(!empty($category)){
                foreach($category as $cat){
                    $category = $cat->category;
                }
            }

            $variation_details = \App\Variations::select(
                'application',
                'prod_type',
                'cost',
                'retail_price',
                'price',
                'discount',
                'stocks',
                'in_stock',
                'images')->where('attribute_id',$v_id)->take(1)->get();

            $ProductDetails = array(); $x=0;
            foreach($variation_details as $vd){
                $retail_price = str_replace(",","",$vd->retail_price);
                $price = str_replace(",","",$vd->price);
                $ProductDetails[$x] = array(
                  'application'=>$vd->application,
                  'prod_type'=>$vd->prod_type,
                  'retail_price'=>number_format(round($vd->retail_price,0),2)."",
                  'price'=>number_format(round($vd->price,0),2)."",
                  'images'=>$vd->images
                );
                $x++;
            }

            $difference = (double)$retail_price - (double)$price;
            $savings = "";
            if($retail_price>0){
                $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
            }

            $savings = round($savings,0);
            $product_id = taiga_crypt($prods->id,'e');
            $prod_arr[$a] = array(
              'ProductName'=>$brand.' '.$prods->name,
              'ProductID'=>$product_id,
              'ProductCategory'=>$category,
              'ProductSavings'=>$savings,
              'ProductDetails'=>$ProductDetails);

         $a++;
        }

        $col = new Collection($prod_arr); //convert array into laravel collection

        //Slice array based on the items wish to show on each page and the current page number
        $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();

        //Save the Pagination in a variable which will be passed to view
        $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);
    }

    return $prod_arr;
}]);

//Route::get('productsbysubcategory/{id}', 'ProductsController@productsbysubcategory');
Route::get('productsbysubcategory', ['middleware'=>'cors',function(){
  $id = !empty($_GET['sid']) ? $_GET['sid'] : "";
    $prod_arr = array('Status'=>'Nothing to fetch.');

    if(!empty($id)){
      $id = taiga_crypt($id,'d');
      $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
      $perPage = 25; //set number of records per page

      $prodbysubcategory = \App\Products::where('sub_category_id',$id)->get();
      $prod_arr = array(); $a=0;
        foreach($prodbysubcategory as $prods){

            $variation = \App\Attributes::where('product_id',$prods->id)->take(1)->get();
            foreach($variation as $v){
                $v_id = $v->id;
            }

            //get product brand
            $brands = \App\Brands::where('id',$prods->brand_id)->take(1)->get();
            foreach($brands as $b){
                $brand = $b->brand;
            }

            //get product category
            $category = \App\Categories::where('id',$prods->category_id)->get();
            if(!empty($category)){
                foreach($category as $cat){
                    $category = $cat->category;
                }
            }

            $variation_details = \App\Variations::select(
              'application',
              'prod_type',
              'cost',
              'retail_price',
              'price','discount',
              'stocks',
              'in_stock',
              'images')->where('attribute_id',$v_id)->take(1)->get();

            $ProductDetails = array();
            $x=0;
            foreach($variation_details as $vd){
                $retail_price = str_replace(",","",$vd->retail_price);
                $price = str_replace(",","",$vd->price);
                $ProductDetails[$x] = array(
                  'application'=>$vd->application,
                  'prod_type'=>$vd->prod_type,
                  'retail_price'=>number_format(round($vd->retail_price,0),2)."",
                  'price'=>number_format(round($vd->price,0),2)."",
                  'images'=>$vd->images
                );
                $x++;
            }

            $difference = (double)$retail_price - (double)$price;
            $savings = "";
            if($retail_price>0){
                $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
            }

            $savings = round($savings,0);
            $product_id = taiga_crypt($prods->id,'e');
            $prod_arr[$a] = array(
              'ProductName'=>$brand.' '.$prods->name,
              'ProductID'=>$product_id,
              'ProductCategory'=>$category,
              'ProductSavings'=>$savings,
              'ProductDetails'=>$ProductDetails);

         $a++;
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
Route::get('productsearch',['middleware'=>'cors',function(){
  $keyword = !empty($_GET['keyword']) ? $_GET['keyword'] : "";

  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

    $product_result = DB::table('t_products')
          ->where('t_tags.tag','like','%'.$keyword.'%')
          ->groupBy('t_products.id')
          ->orderBy('t_products.name','ASC')
          ->join('t_tags', 't_tags.product_id', '=', 't_products.id')
          ->get();

    $prod_arr = array(); $a=0;
    foreach($product_result as $pr){

        //get product brand
        $brands = \App\Brands::where('id',$pr->brand_id)->get();
        $brand = "";
        if(!empty($brands)){
            foreach($brands as $b){
                $brand = $b->brand;
            }
        }

        //get product category
        $category = \App\Categories::where('id',$pr->category_id)->get();
        if(!empty($category)){
            foreach($category as $cat){
                $category = $cat->category;
            }
        }

        $v_id = "";
        $variation = \App\Attributes::where('product_id',$pr->product_id)->get();
        if(!empty($variation)){
            foreach($variation as $v){
                $v_id = $v->id;
            }
        }

        if(!empty($v_id)){ //because the product should have atleast one variation
            $prod_details = \App\Variations::where('attribute_id',$v_id)->get();

            $ProductDetails = array(); $x=0;
            foreach($prod_details as $pd){
                $retail_price = str_replace(",","",$pd->retail_price);
                $price = str_replace(",","",$pd->price);
                $ProductDetails[$x] = array(
                  'prod_type'=>$pd->prod_type,
                  'sku'=>$pd->sku,
                  'part_no'=>$pd->part_no,
                  'made_in'=>$pd->made_in,
                  'short_description'=>$pd->short_description,
                  'description'=>$pd->description,
                  'application'=>$pd->application,
                  'retail_price'=>number_format(round($pd->retail_price,0),2),
                  'price'=>number_format(round($pd->price,0),2),
                  'images'=>$pd->images);
            }

            $difference = (double)$retail_price - (double)$price;
            $savings = "";
            if($retail_price>0){
                $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
            }

            $savings = round($savings,0);

            $prod_id = taiga_crypt($pr->product_id,'e'); //encrypt the product id
            $prod_arr[$a] = array(
              'ProductID'=>$prod_id,
              'ProductName'=>$brand.' '.$pr->name,
              'ProductCategory'=>$category,
              'ProductSavings'=>$savings,
              'ProductDetails'=>$ProductDetails);
        }

      $a++;
    }

    $col = new Collection($prod_arr); //convert array into laravel collection
    //$col = $col->sortBy('ProductName');
    //Slice array based on the items wish to show on each page and the current page number
    $currentPageSearchResults = $col->slice(($currentPage - 1) * $perPage, $perPage)->all();
    //$col = unset($col['data']);
    //Save the Pagination in a variable which will be passed to view
    $prod_arr = new LengthAwarePaginator($currentPageSearchResults, count($col), $perPage);

    //save the search keyword
    /*$keyword
    $search = new SearchKeywords;
    $carts->users_id = $user_id;
    $carts->status = 0;
    $carts->date_time = date("Y-m-d h:i:s");
    $carts->save();*/
    //$keyword;
    //$product_result


    return $prod_arr;
}]);

//Route::get('keywordsuggest', 'ProductsController@keywordsuggest');
Route::get('keywordsuggest', ['middleware'=>'cors', function(){
    $keyword = !empty($_GET['keyword']) ? $_GET['keyword'] : "";
    $tags_result = \App\Tags::select('tag')
        ->where('tag','LIKE',$keyword.'%')
        ->orderBy('tag_ranking','ASC')
        ->orderBy('tag','ASC')
        ->take(10)->get();

        /*$tags_result = \App\Tags::select('tag')
            ->where('tag','LIKE',$keyword.'%')
            ->groupBy('tag')
            ->orderBy('tag_ranking','ASC')
            ->orderBy('tag','ASC')
            ->take(10)->get();*/

    return $tags_result;
}]);

Route::get('featuredproducts', ['middleware'=>'cors', function () {
    $n = isset($_GET['prod']) ? $_GET['prod'] : "";
      if($n!=4){
        if($n==1){
          $sql = 'SELECT
          t_products.id,
          concat(t_brands.brand," ",t_products.name) as ProductName,
          t_categories.category,
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
          JOIN t_brands ON t_brands.id=t_products.brand_id
          JOIN t_categories ON t_categories.id=t_products.category_id
          JOIN t_attributes ON t_attributes.product_id=t_products.id
          JOIN t_variations ON t_variations.attribute_id=t_attributes.id
          GROUP BY t_products.vendor_code
          ORDER BY t_products.created_at DESC LIMIT 5';

          $other_products = DB::select($sql);
          $prod_arr = array();$a=0;
          foreach($other_products as $op){
            $product_details[$a] = array(
              'prod_type'=>$op->prod_type,
              'sku'=>$op->sku,
              'part_no'=>$op->part_no,
              'made_in'=>$op->made_in,
              'short_description'=>$op->short_description,
              'description'=>$op->description,
              'application'=>$op->application,
              'retail_price'=>number_format(round($op->retail_price,0),2),
              'price'=>number_format(round($op->price,0),2),
              'discount'=>$op->discount,
              'images'=>$op->images);

            $prodid = taiga_crypt($op->id,'e');
            $prod_arr[$a] = array(
              'ProductName'=>$op->ProductName,
              'ProductID'=>$prodid,
              'ProductCategory'=>$op->category,
              'ProductSavings'=>$op->ProductSavings,
              'ProductDetails'=>array($product_details[$a])
            );
            $a++;
          }
        }
        else{
          $prodbyfeatured = \App\Products::where('featured',$n)->orderBy('name','DESC')->get();
          $prod_arr = array(); $a=0;
          foreach($prodbyfeatured as $prods){

              //get category
              $category = \App\Categories::where('id',$prods->category_id)->take(1)->get();
              foreach($category as $ca){
                  $productcat = $ca->category;
              }

              //get product brand
              $brands = \App\Brands::where('id',$prods->brand_id)->take(1)->get();
              foreach($brands as $b){
                  $brand = $b->brand;
              }

              $variation = \App\Attributes::where('product_id',$prods->id)->take(1)->get();
              foreach($variation as $v){
                  if(!empty($v->id)){
                      $v_id = $v->id;
                      //$variation = array('here'=>$v_id);

                      $res = \App\Variations::where('attribute_id',$v_id)->take(1)->get();
                      $ProductDetails = array(); $x=0;
                      foreach($res as $r){
                          $retail_price = str_replace(",","",$r->retail_price);
                          $price = str_replace(",","",$r->price);
                          $ProductDetails[$x] = array(
                            'id'=>$r->id,
                            'attribute_id'=>$r->attribute_id,
                            'prod_type'=>$r->prod_type,
                            'sku'=>$r->sku,
                            'part_no'=>$r->part_no,
                            'made_in'=>$r->made_in,
                            'short_description'=>$r->short_description,
                            'description'=>$r->description,
                            'application'=>$r->application,
                            'retail_price'=>number_format(round($r->retail_price,0),2),
                            'price'=>number_format(round($r->price,0),2),
                            'discount'=>$r->discount,
                            'images'=>$r->images);
                      }

                      $difference = (double)$retail_price - (double)$price;
                      $savings = "";
                      if($retail_price>0){
                          $savings = ($difference / (integer)$retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
                      }

                      $savings = round($savings,0);
                      $product_id = taiga_crypt($prods->id,'e');
                      $prod_arr[$a] = array(
                        'ProductName'=>$brand.' '.$prods->name,
                        'ProductID'=>$product_id,
                        'ProductCategory'=>$productcat,
                        'ProductSavings'=>$savings,
                        'ProductDetails'=>$ProductDetails);
                  }
              }

           $a++;
          }
        }
      } //end of checking if featured not equal to 4
      else{
        $sql = 'SELECT
        t_products.id,
        concat(t_brands.brand," ",t_products.name) as ProductName,
        t_categories.category,
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
        JOIN t_brands ON t_brands.id=t_products.brand_id
        JOIN t_categories ON t_categories.id=t_products.category_id
        JOIN t_attributes ON t_attributes.product_id=t_products.id
        JOIN t_variations ON t_variations.attribute_id=t_attributes.id
        GROUP BY t_products.name
        ORDER BY rand() LIMIT 60';

        $other_products = DB::select($sql);
        $prod_arr = array();$a=0;
        foreach($other_products as $op){
          $product_details[$a] = array(
            'prod_type'=>$op->prod_type,
            'sku'=>$op->sku,
            'part_no'=>$op->part_no,
            'made_in'=>$op->made_in,
            'short_description'=>$op->short_description,
            'description'=>$op->description,
            'application'=>$op->application,
            'retail_price'=>number_format(round($op->retail_price,0),2),
            'price'=>number_format(round($op->price,0),2),
            'discount'=>$op->discount,
            'images'=>$op->images);

          $prodid = taiga_crypt($op->id,'e');
          $prod_arr[$a] = array(
            'ProductName'=>$op->ProductName,
            'ProductID'=>$prodid,
            'ProductCategory'=>$op->category,
            'ProductSavings'=>$op->ProductSavings,
            'ProductDetails'=>array($product_details[$a])
          );
          $a++;
        }

      }

    //$prod_arrs = shuffle($prod_arr);

    return $prod_arr;
}]);

Route::get('updatemobile',['middleware'=>'cors',function(){
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


Route::get('register',['middleware'=>'cors',function(){
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
  $user_email = \App\User::where('email',$email)->get();
  $emailres = "";
  foreach($user_email as $ue){
    if(!empty($ue->email)){
      $emailres = $ue->email;
      $name = $ue->name;
      $id = $ue->id;
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
    $response = array('status'=>'success','message='=>'No update has been made.','ID'=>$id,'UID'=>$uid);
  }

  /*$query_str = 'SELECT * FROM users where ';
  $best_deals_result = DB::select($query_str);*/

  return $response;
}]);


Route::get('getvendorbysku',['middleware'=>'cors',function(){
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

Route::post('addtocart',['middleware'=>'cors',function(){
  $sku = isset($_POST['sku']) ? $_POST['sku'] : "";
  $qty = isset($_POST['qty']) ? $_POST['qty'] : "";
  $uid = isset($_POST['uid']) ? $_POST['uid'] : "";

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
        $orders->status = 1;
        $orders->save();

        $response = array('status'=>'success','cartid'=>$cart_id,'message'=>'Item successfully added to cart.');
      }
      else{
        $sku_qty = $sku_qty+$qty;
        $sql_str = 'UPDATE t_product_orders set quantity="'.$sku_qty.'" WHERE sku="'.$sku.'" AND cart_id='.$cart_id.' AND status=1';

        $result_qty_update = DB::table('t_product_orders')
          ->where('sku',$sku)
          ->where('cart_id',$cart_id)
          ->where('status',1)
          ->update(['quantity'=>$sku_qty]);

        $response = array('status'=>'success','cartid'=>$cart_id,'message'=>'Item quantity successfully updated.');
      }

  }
  else{
    $response = array('status'=>'error','message'=>'Unable to add item to cart.');
  }

  return $response;
}]);


Route::get('bestdeals',['middleware'=>'cors',function(){
  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

    $query_str = 'SELECT
    t_variations.prod_type as ProductType,
    t_attributes.product_id as ProductID,
    concat(t_brands.brand," ",t_products.name) as ProductName,
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
    JOIN t_brands on t_brands.id=t_products.brand_id
    WHERE round((((retail_price - price) / retail_price) * 100),0)>=21 and round((((retail_price - price) / retail_price) * 100),0)<=29
    GROUP BY t_attributes.product_id
    ORDER BY rand()';

    $best_deals_result = DB::select($query_str);
    $a=0;$product_details=array();
    foreach($best_deals_result as $bdr){
      $product_id = taiga_crypt($bdr->ProductID,'e');
      $product_details[$a] = array('application'=>$bdr->Application,'prod_type'=>$bdr->ProductType,'retail_price'=>number_format(round($bdr->RetailPrice,0),2),'price'=>number_format(round($bdr->Price,0),2),'images'=>$bdr->Images);
      $best_deals[$a] = array(
        'ProductName'=>$bdr->ProductName,
        'ProductID'=>$product_id,
        'ProductSavings'=>$bdr->PercentSavings,
        'ProductDetails'=>array($product_details[$a]));
      $a++;
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
  $uid = isset($_GET['uid']) ? $_GET['uid'] : "";
  $cartid = isset($_GET['cart_id']) ? $_GET['cart_id'] : "";

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
      join t_municipality_city on t_municipality_city.id=t_users_info.municipality_city_id
      join t_province on t_province.id=t_municipality_city.province_id
      join t_regions on t_regions.id=t_province.region_id
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
    t_products.shipping_ncr,
    t_products.shipping_luzon,
    t_products.shipping_visayas,
    t_products.shipping_mindanao,
    t_products.shipping,
    t_products.id,
    (t_product_orders.quantity * FORMAT(ROUND(t_variations.price,0),2)) AS totalprice
    FROM t_product_orders
    JOIN t_variations ON t_variations.sku=t_product_orders.sku
    JOIN t_attributes ON t_attributes.id=t_variations.attribute_id
    JOIN t_products ON t_products.id=t_attributes.product_id
    WHERE cart_id='.$cartid.' and t_product_orders.status=1';

    $productorders = DB::select($query_prod_orders);

    $totalshipping=0;$discount=0;$totalprice=0;
    if($is_ncr=="true"){ //for ncr

      //gets total shipping of fixed shipping items
      $a=0;$totalprice=0;$fixedshippingprodid=array();
      //collect products with fix shipping
      //get shipping fee
      foreach($productorders as $po){
        $totalprice += $po->totalprice; //totals the product price

        if($po->shipping=="Fix" and !in_array($po->id,$fixedshippingprodid)){
          $totalshipping += $po->shipping_ncr;
          $fixedshippingprodid[$a] = $po->id;
        }
        elseif($po->shipping!="Fix" and !in_array($po->id,$fixedshippingprodid)){
          if($po->quantity>=2){ //computes if qty ordered is equal or more than 2
            $totalshipping += $po->shipping_ncr + ($po->shipping_ncr/$po->quantity);
          }
          else{
            $totalshipping += $po->shipping_ncr;
          }
        }

        $a++;
      }

    }
    else{ //if location not ncr
      //get total price first
      $totalshippingluzon=0;$totalshippingvisayas=0;$totalshippingmindanao=0;$a=0;$fixedshippingprodid=array();
      foreach($productorders as $po){
        $totalprice += $po->totalprice;

        if($po->shipping=="Fix" and !in_array($po->id,$fixedshippingprodid)){
          $totalshippingluzon += $po->shipping_luzon;
          $totalshippingvisayas += $po->shipping_visayas;
          $totalshippingmindanao += $po->shipping_mindanao;
          $fixedshippingprodid[$a] = $po->id;
        }
        elseif($po->shipping!="Fix" and !in_array($po->id,$fixedshippingprodid)){
          $totalshippingluzon += $po->shipping_luzon;
          $totalshippingvisayas += $po->shipping_visayas;
          $totalshippingmindanao += $po->shipping_mindanao;
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
      $totalshipping += $oda_fee;

    }

    $discountedshipping = $totalshipping - round(($discount * $totalshipping),0);

    $shipping = array(
      'TotalPrice'=>round($totalprice,0),
      'TotalShippingFee'=>round($totalshipping,0),
      'ShippingDiscount'=>$discount,
      'DiscountedShipping'=>round($discountedshipping,0),
      'OdaFee'=>round($oda_fee,0),
      'TotalIncludingShipping'=>round($totalprice+$totalshipping,0));
    $response = array('status'=>'success','message'=>'Returning shipping computation.','data'=>$shipping);
  }
  else{
    $response = array('status'=>'error','message'=>'Unable to request shipping computation.','data'=>'');
  }


  return $response;
}]);

Route::get('saleproducts',['middleware'=>'cors',function(){
  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

  $query_prod_sale = 'SELECT
t_variations.prod_type AS ProductType,
t_attributes.product_id AS ProductID,
CONCAT(t_brands.brand," ",t_products.name) AS ProductName,
t_brands.brand AS ProductBrand,
t_variations.retail_price AS ProductRetailPrice,
t_variations.price AS ProductPrice,
round((((retail_price-price)/retail_price)*100),0) AS ProductDiscount,
t_variations.application as ProductApplication,
t_variations.images AS ProductImages
FROM `t_variations`
join t_attributes on t_attributes.id=t_variations.attribute_id
join t_products on t_products.id=t_attributes.product_id
join t_brands on t_brands.id=t_products.brand_id
where (((retail_price-price)/retail_price)*100)>=30
GROUP by t_attributes.product_id
ORDER BY rand()';

  $productsales = DB::select($query_prod_sale);

  $a=0;$prod_sales=array();$product_details=array();
  foreach($productsales as $bdr){
    $product_id = taiga_crypt($bdr->ProductID,'e');
    $product_details[$a] = array('application'=>$bdr->ProductApplication,'prod_type'=>$bdr->ProductType,'retail_price'=>number_format(round($bdr->ProductRetailPrice,0),2),'price'=>number_format(round($bdr->ProductPrice,0),2),'images'=>$bdr->ProductImages);
    $prod_sales[$a] = array(
      'ProductName'=>$bdr->ProductName,
      'ProductID'=>$product_id,
      'ProductSavings'=>$bdr->ProductDiscount,
      'ProductDetails'=>array($product_details[$a]));
    $a++;
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
  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

  $query_prod_bikes = 'SELECT
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
ORDER BY rand()';

$product_bikes = DB::select($query_prod_bikes);

$a=0;$prod_sales=array();$product_details=array();
foreach($product_bikes as $bdr){
  $product_id = taiga_crypt($bdr->ProductID,'e');
  $product_details[$a] = array('application'=>$bdr->ProductApplication,'prod_type'=>$bdr->ProductType,'retail_price'=>number_format(round($bdr->ProductRetailPrice,0),2),'price'=>number_format(round($bdr->ProductPrice,0),2),'images'=>$bdr->ProductImages);
  $prod_sales[$a] = array(
    'ProductName'=>$bdr->ProductName,
    'ProductID'=>$product_id,
    'ProductSavings'=>$bdr->ProductSavings,
    'ProductDetails'=>array($product_details[$a]));
  $a++;
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
