<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Categories extends Model
{
  /**
   * The table associated with the model.
   *
   * @var string
   */
       /* public function mymenu() {
//        return $this->hasMany('\App\Category_Subcategory', 't_category_subcategory', 'id', 'categories_id');
        return 'test';
    }*/
  protected $table = 't_categories';



}
