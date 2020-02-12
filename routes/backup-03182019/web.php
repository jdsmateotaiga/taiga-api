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




Route::get('getsubcategories/{id}/', 'ProductsController@getsubcategories');

Route::get('getrelatedproducts/{keyword}', 'ProductsController@getrelatedproducts');

Route::get('getfeaturedotherprods', 'ProductsController@getfeaturedotherprods');
Route::get('getfeaturednewarrivalprods', 'ProductsController@getfeaturednewarrivalprods');

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

    foreach($products as $p){
      $product_id = taiga_crypt($p->product_id,'e');

      $difference = (double)$p->retail_price - (double)$p->price;
      $savings = "";
      if($p->retail_price>0){
          $savings = ($difference / (integer)$p->retail_price) * 100; //((double)$difference / (double)$retail_price) * 100;
      }

      $savings = round($savings,0);

      $product_list[$p->product_id] = array(
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

    }

    return $product_list;
}]);

Route::get('productprice',['middleware'=>'cors',function(){
  $id = isset($_GET['id']) ? $_GET['id'] : "";
  $qty = isset($_GET['qty']) ? $_GET['qty'] : "";
  $attr_id = isset($_GET['attr_id']) ? $_GET['attr_id'] : "";

  if($attr_id==0){// if attr id is empty
    $getattr_id = \App\Attributes::where('product_id',$id)->get();
    foreach($getattr_id as $attr_id){
      $attr_id = $attr_id->id;
    }
  }

  $variations = \App\Variations::where('attribute_id',$attr_id)->get();
  foreach($variations as $variation){
    $price = $variation->price;
    $comm = $variation->comm;
    $cost = $variation->cost;
    $retail = $variation->retail_price;
  }

  if(!empty($price) and $price!=0){
    $total_price = $price * $qty;
  }
  else{
    $com = ".".$comm;
    $a = ($cost * $com) + $cost;
    $total_price = $a * $qty;
  }

  $product_price = array('price'=>$total_price);

  return $product_price;
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
      $id = isset($_GET['pid']) ? $_GET['pid'] : "";
      $id = taiga_crypt($id,'d'); //decrypt the product id
      //should add result if no record found

      $product = \App\Products::where('id',$id)->get();
      $prodattributes = \App\Attributes::where('product_id',$id)->get();
      $product_info=array();$a=0;$product_infos = array();
      foreach($prodattributes as $pa){ //get variations
          $variation = !empty($pa->attribute) ? $pa->attribute : "";
          $variation_value = !empty($pa->value) ? $pa->value : "";

              $product_info = \App\Variations::select(
                  'prod_type',
                  'sku',
                  'part_no',
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
                  'images')->where('attribute_id',$pa->id)->orderBy('price','asc')->get();

                foreach($product_info as $pi){
                  if(!empty($pi->sku)){
                      $product_infos[$a] = array(
                        'variation'=>$variation_value,
                        'prod_type'=>$pi->prod_type,
                        'sku'=>$pi->sku,
                        'part_no'=>$pi->part_no,
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

        foreach($product as $p){
            $brand = \App\Brands::where('id',$p->brand_id)->get();
            foreach($brand as $b){ $brand_name = $b->brand; }
        }

        $result = array(
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
                'price',
                'discount',
                'stocks',
                'in_stock',
                'images')->where('attribute_id',$v_id)->take(1)->get();

            $ProductDetails = array();
            foreach($variation_details as $vd){
                $retail_price = str_replace(",","",$vd->retail_price);
                $price = str_replace(",","",$vd->price);
                $ProductDetails = array(
                  'application'=>$vd->application,
                  'prod_type'=>$vd->prod_type,
                  'retail_price'=>number_format(round($vd->retail_price,0),2),
                  'price'=>number_format(round($vd->price,0),2),
                  'images'=>$vd->images
                );
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
              'ProductDetails'=>$variation_details);

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
  $id = !empty($_GET['cid']) ? $_GET['cid'] : "";
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
            foreach($variation_details as $vd){
                $retail_price = str_replace(",","",$vd->retail_price);
                $price = str_replace(",","",$vd->price);
                $ProductDetails = array(
                  'application'=>$vd->application,
                  'prod_type'=>$vd->prod_type,
                  'retail_price'=>number_format(round($vd->retail_price,0),2),
                  'price'=>number_format(round($vd->price,0),2),
                  'images'=>$vd->images
                );
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
              'ProductDetails'=>$variation_details);

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

            $ProductDetails = array();
            foreach($prod_details as $pd){
                $retail_price = str_replace(",","",$pd->retail_price);
                $price = str_replace(",","",$pd->price);
                $ProductDetails = array(
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
              'ProductDetails'=>$prod_details);
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


    return $prod_arr;
}]);

//Route::get('keywordsuggest', 'ProductsController@keywordsuggest');
Route::get('keywordsuggest', ['middleware'=>'cors', function(){
    $keyword = !empty($_GET['keyword']) ? $_GET['keyword'] : "";
    $tags_result = \App\Tags::select('tag')
        ->where('tag','LIKE',$keyword.'%')
        ->groupBy('tag')
        ->orderBy('tag_ranking','ASC')
        ->orderBy('tag','ASC')
        ->take(10)->get();

    return $tags_result;
}]);

Route::get('featuredproducts', ['middleware'=>'cors', function () {
    $n = $_GET['prod'];
        $prodbyfeatured = \App\Products::where('featured',$n)->orderBy('updated_at','DESC')->get();
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
                    $ProductDetails = array();
                    foreach($res as $r){
                        $retail_price = str_replace(",","",$r->retail_price);
                        $price = str_replace(",","",$r->price);
                        $ProductDetails = array(
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
                      'ProductDetails'=>$res);
                }
            }

         $a++;
        }

    return $prod_arr;
}]);


Route::get('authenticate',['middleware'=>'cors',function(){
  $email = $_GET['email'];
  $pass = $_GET['password'];

  if(Auth::attempt(['email'=>$email,'password'=>$pass])){
      $id = Auth::id();
      $r = array('authenticated'=>'true','user_id'=>$id);
  }

  return $r;
}]);

Route::get('register',['middleware'=>'cors',function(){
  $email = isset($_GET['email']) ? $_GET['email'] : "";
  $pass = isset($_GET['password']) ? $_GET['password'] : "";
  $name = isset($_GET['name']) ? $_GET['name'] : "";

  $user_email = \App\User::where('email',$email)->get();

  foreach($user_email as $ue){
    $a = $ue->email;
  }

  if(!empty($a)){ //cannot register the email since it already exists
    $auth = array('exists'=>'true');
  }
  else{
    $auth = array('exists'=>'false');
    $res = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($pass),
    ]);
  }

  return $res;
}]);

Route::get('update_address',['middleware'=>'cors',function(){
    $user_id = isset($_GET['user_id']) ? $_GET['user_id'] : "";
    /*$contact = isset($_GET['contact']) ? $_GET['contact'] : "";
    $unit = isset($_GET['unit']) ? $_GET['unit'] : "";
    $city = isset($_GET['city']) ? $_GET['city'] : "";
    $brgy = isset($_GET['brgy']) ? $_GET['brgy'] : "";
    $region = isset($_GET['region']) ? $_GET['region'] : "";*/
    $user_idx = "";
    //if user id exists, update, else insert
    $res = \App\Users_Info::where('users_id',$user_id)->get();
    foreach($res as $r){
      if(!empty($r->user_id)){
          $user_idx = $r->user_id;
      }
    }

    //check if user id already exists
    if(!empty($user_idx)){
      $result = array('exists'=>'true','ff'=>$user_idx);
    }
    else{ //insert new address
      $result = array('exists'=>'false','x'=>$user_idx);
    }

    return $result;
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



Route::get('createcart',['middleware'=>'cors', function(){
  $user_id = isset($_GET['user_id']) ? $_GET['user_id'] : "";

  $carts = new Carts;
  $carts->users_id = $user_id;
  $carts->status = 0;
  $carts->date_time = date("Y-m-d h:i:s");
  $carts->save();

  $result = array('Status'=>0,'Message'=>'Unable to create cart','CartId'=>'');
  if(!empty($carts->id)){
      $result = array('Status'=>1,'Message'=>'Created your cart.','CartId'=>$carts->id);
  }

  return $result;
}]);

Route::get('addtocart',['middleware'=>'cors',function(){
  $sku = isset($_GET['sku']) ? $_GET['sku'] : "";
  $cart_id = isset($_GET['cart_id']) ? $_GET['cart_id'] : "";
  $qty = isset($_GET['qty']) ? $_GET['qty'] : "";

  $orders = new ProductOrders;
  $orders->sku = $sku;
  $orders->cart_id = $cart_id;
  $orders->quantity = $qty;
  $orders->save();

  $result = array('Status'=>0,'Message'=>'An error occured while adding item to cart','ProductOrderId'=>'');
  if(!empty($orders->id)){
      $result = array('Status'=>1,'Message'=>'Item added to cart','ProductOrderId'=>$orders->id);
  }

  return $result;
}]);

Route::get('bestdeals',['middleware'=>'cors',function(){
  $currentPage = LengthAwarePaginator::resolveCurrentPage(); //holds the current page
  $perPage = 25; //set number of records per page

    $query_str = 'SELECT
    t_attributes.product_id as ProductId,
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
    GROUP BY t_attributes.product_id';

    $best_deals_result = DB::select($query_str);
    $a=0;
    foreach($best_deals_result as $bdr){
      $product_id = taiga_crypt($bdr->ProductId,'e');
      $best_deals[$a] = array(
        'ProductId'=>$product_id,
        'ProductName'=>$bdr->ProductName,
        'Application'=>$bdr->Application,
        'Description'=>$bdr->Description,
        'ShortDescription'=>$bdr->ShortDescription,
        'SKU'=>$bdr->SKU,
        'RetailPrice'=>number_format(round($bdr->RetailPrice,0),2),
        'Price'=>number_format(round($bdr->Price,0),2),
        'PercentSavings'=>$bdr->PercentSavings,
        'Images'=>$bdr->Images);
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
  //get user if luz,vis, or min based from municipality,province,region and where the region is (luz,vis,min)
  //get item shipping fee if luz,vis,min
  //get location discount
  //check if under oda
  //return total shipping fee


}]);

Route::get('computetotal/{cart_id}', 'CartsController@computetotal');

//Route::post('register', 'UserController@register');
