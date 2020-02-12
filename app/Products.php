<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Products extends Model
{
  /**
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 't_products';

  public function tags(){
    return $this->hasMany('App\Tags','product_id');
  }

}
