<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MeetController extends Controller
{
    public function meets() 
    {
        return view('meet.list');
    }
}
