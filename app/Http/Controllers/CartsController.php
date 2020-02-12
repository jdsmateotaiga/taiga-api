<?php

namespace App\Http\Controllers;
use DB;
use Illuminate\Http\Request;
use App\Categories;
use App\Sub_Category;
use App\Category_Subcategory;
use App\Brands;
use App\User;
use App\Products;
use App\Attributes;
use App\Variations;
use App\Tags;
use App\Carts;
use App\ProductOrders;
//use Illuminate\Support\Facades\Hash;

class CartsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    /*public function __construct()
    {
        $this->middleware('auth');
    }*/

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $data = Products::all();
        return $data;
    }

    public function createcart($user_id){

        /*$carts = new Carts;
        $carts->users_id = $user_id;
        $carts->status = 0;
        $carts->date_time = date("Y-m-d h:i:s");
        $carts->save();

        if(!empty($carts->id)){
            $result = array('Status'=>1,'Message'=>'Created your cart.','CartId'=>$carts->id);
        }
        else{
            //logs should be set here
            $result = array('Status'=>0,'Message'=>'Unable to create cart','CartId'=>$carts->id);
        }


        return response($result)->header("Access-Control-Allow-Headers","*");*/
    }

    /*public function updatecartpaymentmethod($cart_id,$method){

    }*/

    public function addtocart($cart_id,$sku,$qty){

        $orders = new ProductOrders;
        $orders->sku = $sku;
        $orders->cart_id = $cart_id;
        $orders->quantity = $qty;
        $orders->save();

        if(!empty($orders->id)){
            $result = array('Status'=>1,'Message'=>'Item added to cart','ProductOrderId'=>$orders->id);
        }
        else{
            //logs should be set here
            $result = array('Status'=>0,'Message'=>'An error occured while adding item to cart','ProductOrderId'=>$orders->id);
        }


        return response($result)->header("Access-Control-Allow-Headers","*");
    }

    public function computetotal($cart_id){

        //fetch products in carts

        $po = \App\ProductOrders::where('cart_id',$cart_id)->get();
        $details = array(); $a=0; $complete_details = array();
        foreach($po as $p){
            $details[$a] = \App\Variations::select(
                        'attribute_id',
                        'prod_type',
                        'retail_price',
                        'price',
                        'stocks',
                        'images',
                        'shipping_ncr',
                        'shipping_luzon',
                        'shipping_visayas',
                        'shipping_mindanao')->where('sku',$p->sku)->get();

            //get product name and brand
            foreach($details[$a] as $da){
                $att = $da->attribute_id;

                $product_result = DB::table('t_products')
                  ->where('t_attributes.id','=',$att)
                  ->join('t_attributes', 't_products.id', '=', 't_attributes.product_id')
                  ->join('t_brands','t_products.brand_id','=','t_brands.id')
                  ->get();
            }

            foreach($product_result as $pr){
                $product_name[$a] = $pr->brand.' '.$pr->name;
            }

            $complete_details[$a] = array('product_name'=>$product_name[$a],'sku'=>$p->sku,'quantity'=>$p->quantity,'product_details'=>$details[$a]);
            $a++;
        }

        //fetch product name




        return response($complete_details)->header("Access-Control-Allow-Headers","*");
    }


}
