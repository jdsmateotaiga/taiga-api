<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Category_Subcategory extends Model
{
  /**
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 't_category_subcategory';
    
    /*public function relmenu() {
        return $this->hasOne('\App\Sub_Category', 't_sub_category', 'id', 'categories_id');
    }*/
}
