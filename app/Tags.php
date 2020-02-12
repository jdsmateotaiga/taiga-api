<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Tags extends Model
{
  /**
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 't_tags';

  public function products(){
    return $this->belongsTo('App\Products','product_id');
  }

}
