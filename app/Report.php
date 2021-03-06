<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $table = 'reports';

    protected $fillable = [
        'filename', 'from', 'to'
    ];

    const REPORT_PATH = 'assets/app/reports/';
}
