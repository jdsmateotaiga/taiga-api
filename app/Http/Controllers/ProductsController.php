<?php

namespace App\Http\Controllers;
use DB;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use App\Categories;
use App\Sub_Category;
use App\Category_Subcategory;
use App\Brands;
use App\User;
use App\Products;
use App\Attributes;
use App\Variations;
use App\Tags;
//use Illuminate\Support\Facades\Hash;

class ProductsController extends Controller
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

    public function getsubcategories($cat_id){
      $subcategorylist = \App\Sub_Category::where('category_id',$cat_id)->get();

      return response($subcategorylist)->header("Access-Control-Allow-Headers","*");
    }

    public function featuredproducts(){ //not being used anymore, migrated to web routes
        $n = $_GET['prod'];
        $categories = \App\Categories::select('id','category')->get();
        $cat_arr = array(); $a=0;
        // $r = array('category'=>);
        $no_products = array();$c=array();
        foreach($categories as $cats){
            $subcats = \App\Sub_Category::where('category_id',$cats->id)->get();
            $new_subcat = array();
            $b=0;$total=0;
            foreach($subcats as $sc){
                $no_products[$b] = \App\Products::where('sub_category_id',$sc->id)->get();
                $c[$b] = count($no_products[$b]); //count number of products under that sub category

                $new_subcat[$b] = array('id'=>$sc->id,'sub_category'=>$sc->sub_category,'productcount'=>$c[$b]);

                $total += $c[$b];
                $b++;
            }

            $cat_arr['Categories'][$a] = array('Category'=>$cats->category,'id'=>$cats->id,'totalproducts'=>$total,'SubCategories'=>$new_subcat);

         $a++;
        }

        //return $no_products;
        return response($cat_arr)->header("Access-Control-Allow-Headers","*");
    }

    public function getrelatedproducts($keyword){

      $product_id_result = DB::table('t_products')
              ->where('t_tags.tag','like','%'.$keyword.'%')
              ->groupBy('t_products.name')
              ->join('t_tags', 't_products.id', '=', 't_tags.product_id')
              ->join('t_categories','t_products.category_id','=','t_categories.id')
              ->get();

      return response($product_id_result)->header("Access-Control-Allow-Headers","*");
    }

    //public function getproducts(){

        /*$categories = \App\categories::where('category',$h[20])->get(); //get if category exists
        if($categories->isEmpty()){
          $categories = new categories;
          $categories->category = $h[20];
          $categories->save();
          $cat_id = $categories->id;
        }*/

    //}

}
