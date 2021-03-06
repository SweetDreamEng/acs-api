<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
//use App\Events\AlarmCreatedEvent;
//use Rennokki\QueryCache\Traits\QueryCacheable;

class DeviceData extends Model
{
    protected $table = 'device_data';

    protected $fillable = [
        'device_id',
        'tag_id',
        'timestamp',
        'values',
        'machine_id',
        'timedata',
        'serial_number',
        'created_at',
    ];

    public $timestamps = false;

    public static function getIventoryParserValues($hop_inventory = null, $actual_inventory = null)
    {
        $inventories = [];

        if ($hop_inventory && $actual_inventory) {
            $inventory_values = json_decode($actual_inventory->values);
            for ($i = 0; $i < count($inventory_values); $i++) {

                if (!$inventory_values[$i]) {
                    $inv2 = sprintf('%01d', $inventory_values[$i]);
                } else {
                    $inv2 = sprintf('%03d', $inventory_values[$i]);
                }

                $inventories[] = floatval(strval(json_decode($hop_inventory->values)[$i]) . '.' . $inv2);
            }
        }

        return $inventories;
    }
}
