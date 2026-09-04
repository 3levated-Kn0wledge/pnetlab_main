<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helpers\Request\Checker;
use App\Helpers\Request\Query;
use App\Helpers\Request\Reply;
use Illuminate\Support\Facades\Auth;


class PackagesController extends Controller
{



    function __construct()
    {
        parent::__construct();
    }

}