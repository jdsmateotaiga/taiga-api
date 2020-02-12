<?php

namespace App\Http\Controllers;
use DB;
use Illuminate\Http\Request;
use App\User;
//use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

require __DIR__.'\vendor\autoload.php';

use Kreait\Firebase;
use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Database;
use Firebase\Auth\Token\Exception\InvalidToken;

class UserController extends Controller
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

    public function testfirebase(){
      $serviceAccount = ServiceAccount::fromJsonFile(__DIR__.'\taiga-a87c0-firebase-adminsdk-n9zzm-44f17e0a5c.json');
      $firebase = (new Factory)->withServiceAccount($serviceAccount);
      $accesstoken = "eyJhbGciOiJSUzI1NiIsImtpZCI6ImZiMDEyZTk5Y2EwYWNhODI2ZTkwODZiMzIyM2JiOTYwZGFhOTFmODEiLCJ0eXAiOiJKV1QifQ.eyJuYW1lIjoiTUhvbiBSb21lcm8iLCJwaWN0dXJlIjoiaHR0cHM6Ly9ncmFwaC5mYWNlYm9vay5jb20vMjY3ODAxMjcwODg4MDQxMC9waWN0dXJlIiwiaXNzIjoiaHR0cHM6Ly9zZWN1cmV0b2tlbi5nb29nbGUuY29tL3RhaWdhLWE4N2MwIiwiYXVkIjoidGFpZ2EtYTg3YzAiLCJhdXRoX3RpbWUiOjE1NTM2Nzg3NDgsInVzZXJfaWQiOiJES01LamRBVVZjY0xXc1hjVkloRHlBQXB2ekMyIiwic3ViIjoiREtNS2pkQVVWY2NMV3NYY1ZJaER5QUFwdnpDMiIsImlhdCI6MTU1MzY3ODc0OCwiZXhwIjoxNTUzNjgyMzQ4LCJlbWFpbCI6Im1ob24uYWxiZXJ0MThAZ21haWwuY29tIiwiZW1haWxfdmVyaWZpZWQiOmZhbHNlLCJmaXJlYmFzZSI6eyJpZGVudGl0aWVzIjp7ImZhY2Vib29rLmNvbSI6WyIyNjc4MDEyNzA4ODgwNDEwIl0sImVtYWlsIjpbIm1ob24uYWxiZXJ0MThAZ21haWwuY29tIl19LCJzaWduX2luX3Byb3ZpZGVyIjoiZmFjZWJvb2suY29tIn19.QgDY6ANwZJQG3qxORnkdJVXd8wp_DwHrQWd2DbEXzr51mUoOcmHOKXQ1iPvbB9q29lUT5DUj5NADLGUlgLbJDSEJdewM2puPIhAb8jfkkSHlwnkVxyM2CUFk-gg4B0CCKonBuZC0mxVv_pjWUVeCZHlIvOz5QrQ4k31KsuvQmT7RGOQgVAS7uQmWwGGgsQ_y2GQCXhn7N0s01izfw3KszWN1OnLrAsteO75BdtlsjBRvpot31iCCZ3tbzwDgCFnoU2Aant7Zt86x3XIsFrtS5tLuahopnGyP-bx0ZgT_MlYq50Gig_ei45IBzRsog_yIoPLn_luJ59KkVF7cqzlb_Q";

      $uid = "DKMKjdAUVccLWsXcVIhDyAApvzC2";

      try{
          $verifiedIdToken = $firebase->getAuth()->verifyIdToken($accesstoken);
      }catch(InvalidToken $e){
        echo $e->getMessage();
      }

      echo "Firebase test";
    }

    public function login($email,$pass){

        //$result = array('username'=>$email,'password'=>$pass);
        $result = \App\User::select('email','password')->where([['email','=',$email],['password','=',Hash::check($pass)]])->get();


        return response($result)->header("Access-Control-Allow-Headers","*");
    }

    public function authenticate($email,$pass){

        //$result = array('username'=>$email,'password'=>$pass);
        //$result = \App\User::select('email','password')->where([['email','=',$email],['password','=',Hash::check($pass)]])->get();

        //$credentials = $request->only('email','password');
        $r = array('authenticated'=>'false');
        if(Auth::attempt(['email'=>$email,'password'=>$pass])){
            $id = Auth::id();


            $r = array('authenticated'=>'true','user_id'=>$id);

        }

        return response($r)->header("Access-Control-Allow-Headers","*");
    }

    public function register(Request $request){
        return $request->all();
    }

    private function taiga_crypt( $string, $action = 'e' ) {
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

}
