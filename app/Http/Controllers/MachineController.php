<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Company;
use App\AlarmType;
use App\DeviceData;
use App\Machine;
use App\Location;
use App\Zone;
use App\Device;
use App\DowntimePlan;
use DB;

class MachineController extends Controller
{
	private $num_chunks = 12;

	public function index() {
    	$companies = Company::orderBy('name')->get();

		return response()->json(compact('companies'));
	}

	public function getMachines() {
		$machines = Machine::all();

		return response()->json(compact('machines'));
	}

	/*
		Get all configurations
	*/
	public function getAllConfigurations() {
		$configurations = Machine::select('id', 'name')->get();

		return response()->json(compact('configurations'));
	}

	/*
		Get general information of product
		They are Name, Serial number, Software build, and version
		return: Object
	*/
	public function getProductOverview($id) {
		$product = Device::where('serial_number', $id)->first();

		if(!$product) {
			return response()->json([
				'message' => 'Device Not Found'
			], 404);
		}

		$configuration = $product->configuration;

		if(!$configuration) {
			return response()->json([
				'message' => 'Device Not Configured'
			], 404);
		}

		// product version
		if($version_object = DeviceData::where('machine_id', $configuration->id)->where('tag_id', 4)->latest('timestamp')->first()) {
			$product->version = json_decode($version_object->values)[0] / 10;
		}

		// software build
		if($software_build_object = DeviceData::where('machine_id', $configuration->id)->where('tag_id', 5)->latest('timestamp')->first()) {
			$product->software_build = json_decode($software_build_object->values)[0];
		}

		// serial number
		$serial_year = "";
		$serial_month = "";
		$serial_unit = "";

		if($serial_year_object = DeviceData::where('machine_id', $configuration->id)->where('tag_id', 7)->latest('timestamp')->first()) {
			$serial_year = json_decode($serial_year_object->values)[0];
		}
		if($serial_month_object = DeviceData::where('machine_id', $configuration->id)->where('tag_id', 6)->latest('timestamp')->first()) {
			$serial_month = chr(json_decode($serial_month_object->values)[0] + 65);
		}
		if($serial_unit_object = DeviceData::where('machine_id', $configuration->id)->where('tag_id', 8)->latest('timestamp')->first()) {
			$serial_unit = json_decode($serial_unit_object->values)[0];
		}

		$product->serial = $serial_year . $serial_month . $serial_unit;

		return response()->json([
			"overview" => $product
		]);
	}

	/*
		Get inventories
		L30_0_8_HopInv and L30_16_23_FracInv are grouped together
		return: array
	*/
	public function getInventories($id) {
		$product = Device::where('serial_number', $id)->first();

		if(!$product) {
			return response()->json([
				'message' => 'Device Not Found'
			], 404);
		}

		$configuration = $product->configuration;

		if(!$configuration) {
			return response()->json([
				'message' => 'Device Not Configured'
			], 404);
		}

		$hop_inventory = DeviceData::where('machine_id', $configuration->id)->where('tag_id', 15)->orderBy('timestamp', 'desc')->first();
		$actual_inventory = DeviceData::where('machine_id', $configuration->id)->where('tag_id', 16)->orderBy('timestamp', 'desc')->first();

		$inventories = array();

		if($hop_inventory && $actual_inventory) {
			$inventory_values = json_decode($actual_inventory['values']);
			for($i = 0; $i < count($inventory_values); $i ++) {
				if (!$inventory_values[$i]) {
					$inv2 = sprintf('%01d', $inventory_values[$i]);
				} else {
					$inv2 = sprintf('%03d', $inventory_values[$i]);
				}
				$inv = strval(json_decode($hop_inventory['values'])[$i]) . '.' . $inv2;
				array_push($inventories, $inv);
			}
		} else {
			$inventories = [];
		}
		
		return response()->json(compact('inventories'));
	}

	public function getProductWeight($id) {
		$targetValues = DB::table('device_data')
						->where('device_id', $id)
						->where('tag_id', 13)
						->orderBy('timestamp', 'desc')
						->first();
		$actualValues = DB::table('device_data')
						->where('device_id', $id)
						->where('tag_id', 14)
						->orderBy('timestamp', 'desc')
						->first();

		$targets = json_decode($targetValues['values']);
		$actuals = json_decode($actualValues['values']);

		return response()->json(compact('targets', 'actuals'));
	}

	/*
		Get last recipe values
	*/
	public function getProductRecipe($id) {
		$product = Device::where('serial_number', $id)->first();

		if(!$product) {
			return response()->json([
				'message' => 'Device Not Found'
			], 404);
		}

		$configuration = $product->configuration;

		if(!$configuration) {
			return response()->json([
				'message' => 'Device Not Configured'
			], 404);
		}

		$last_recipe = DeviceData::where('machine_id', $configuration->id)->where('tag_id', 10)->orderBy('timestamp', 'desc')->first();

		$recipe_values = json_decode($last_recipe['values']);

		return response()->json(compact('recipe_values'));
	}

	/*
		Get product utilization series
		return: Utilization Series Array
	*/
	public function getProductUtilization(Request $request) {
		$product = Device::where('serial_number', $request->id)->first();

		if(!$product) {
			return response()->json([
				'message' => 'Device Not Found'
			], 404);
		}

		$configuration = $product->configuration;

		if(!$configuration) {
			return response()->json([
				'message' => 'Device Not Configured'
			], 404);
		}

		$from = $this->getFromTo($request->timeRange)["from"];
		$to = $this->getFromTo($request->timeRange)["to"];

		$utilizations_object = DeviceData::where('device_id', $request->id)
										->where('tag_id', 2)
										->where('timestamp', '>', $from)
										->where('timestamp', '<', $to)
										->orderBy('timestamp')
										->get();

		$utilizations = array_map(function($utilization_object) {
			return [$utilization_object['timestamp'] * 1000, json_decode($utilization_object['values'])[0] / 10];
		}, $utilizations_object->toArray());

		return response()->json(compact('utilizations'));
	}

	/*
		Get energy consumption series
		return: Energy consumption series array
	*/
	public function getEnergyConsumption(Request $request) {
		$product = Device::where('serial_number', $request->id)->first();

		if(!$product) {
			return response()->json([
				'message' => 'Device Not Found'
			], 404);
		}

		$configuration = $product->configuration;

		if(!$configuration) {
			return response()->json([
				'message' => 'Device Not Configured'
			], 404);
		}

		$from = $this->getFromTo($request->timeRange)["from"];
		$to = $this->getFromTo($request->timeRange)["to"];

		$energy_consumptions_object = DeviceData::where('device_id', $request->id)
										->where('tag_id', 3)
										->where('timestamp', '>', $from)
										->where('timestamp', '<', $to)
										->orderBy('timestamp')
										->get();

		$energy_consumption = array_map(function($energy_consumption_object) {
			return [$energy_consumption_object['timestamp'] * 1000, json_decode($energy_consumption_object['values'])[0]];
		}, $energy_consumptions_object->toArray());

		return response()->json(compact('energy_consumption'));
	}

	/*
		Get running hours of weekdays
		return: 7 length array
	*/
	public function getWeeklyRunningHours($id) {
		$ret = [0, 0, 0, 0, 0, 0, 0];

		$running_values = DB::table('device_data')
							->where('machine_id', $id)
							->where('tag_id', 9)
							->get()
							->toArray();

		$count = count($running_values);
		
		if($count <= 0)
			return $ret;

		$current_object = [
			"timestamp" => time(),
			"values" => $running_values[$count - 1]->values,
		];

		array_push($running_values, (object)$current_object);

		$start_timestamp = $running_values[0]->timestamp;
		$end_timestamp = $running_values[$count]->timestamp;

		for ($i = $start_timestamp; $i < $end_timestamp; $i += 3600) { 
			$weekday = date('N', $i); //1, 2, 3, 4, 5, 6, 7
			$ret[$weekday - 1] += 1;
		}
		// foreach ($running_values as $key => $item) {
		// 	$weekday = date('N', $item->timestamp); //1, 2, 3, 4, 5, 6, 7
		// 	$seconds_diff = $item->timestamp - $running_values[$key - 1]->timestamp;
		// 	$hours_diff = round($seconds_diff / 3600, 1);
		// 	$is_previous_running = json_decode($running_values[$key - 1]->values)[0] == 1;
		// 	if ($is_previous_running) {
		// 		$ret[$weekday - 1] += $hours_diff;
		// 	}
		// }
		return response()->json([
			'hours' => $ret
		]);
	}

	public function initProductPage(Request $request) {
		$id = $request->machineId;
		$machine = Machine::where('id', $id)->select('id', 'name')->first();

		$notes = $machine->notes;
		
		if($id == MACHINE_BD_Batch_Blender) {
			$num_points = 12;
			$fromWeight = $this->getFromTo($request->weightTimeRange)["from"];
			$toWeight = $this->getFromTo($request->weightTimeRange)["to"];

			$energy_consumption_values = DeviceData::where('machine_id', $id)
							->where('tag_id', 3)
							->pluck('values');

			// $energy_consumption = $this->parseValid1($energy_consumption_values);

			$targetValues = DB::table('device_data')
							->where('machine_id', $id)
							->where('tag_id', 13)
							->orderBy('timestamp', 'desc')
							->first();
			$actualValues = DB::table('device_data')
							->where('machine_id', $id)
							->where('tag_id', 14)
							->orderBy('timestamp', 'desc')
							->first();

			$running_values = DB::table('device_data')
							->where('machine_id', $id)
							->where('tag_id', 9)
							->get();

			$targets = json_decode($targetValues->values);
			$actuals = json_decode($actualValues->values);
			// $targets = $this->parseValidWithTime($targetValues, $request->param, $fromWeight, $toWeight);
			// $actuals = $this->parseValidWithTime($actualValues, $request->param, $fromWeight, $toWeight);
			// $weekly_running_hours = $this->weeklyRunningHours($running_values);
			$total_running_percentage = $this->totalRunningPercentage($running_values);

			$alarm_types = AlarmType::where('machine_id', $id)->get();
			// $alarms = DeviceData::where('machine_id', $id)
			// 			->whereIn('tag_id', [27, 28, 31, 32, 33, 34, 35, 36, 37, 38])
			// 			// ->where('timestamp', '>', $duration)
			// 			->orderBy('timestamp')
			// 			->get();
			// $recipe_values = json_decode($recipe_values->values);

			return response()->json(
				compact(
					// 'energy_consumption',
					'targets',
					'actuals',
					'alarm_types',
					// 'alarms',
					// 'weekly_running_hours',
					'total_running_percentage',
					// 'recipe_values',
					'notes'
				)
			);
		} else if($id == MACHINE_Accumeter_Ovation_Continuous_Blender) {
			$energy_consumption_values = DeviceData::where('machine_id', $id)
							->where('tag_id', 3)
							->pluck('values');
			$energy_consumption = $this->parseValid1($energy_consumption_values);

			$alarm_types = AlarmType::where('machine_id', $id)->get();
			$alarms = [];

			return response()->json(
				compact(
					'energy_consumption',
					'alarm_types',
					'alarms',
					'notes'
				)
			);
		} else if($id == MACHINE_GH_Gravimetric_Extrusion_Control_Hopper) {
			$energy_consumption_values = DeviceData::where('machine_id', $id)
							->where('tag_id', 3)
							->pluck('values');
			$energy_consumption = $this->parseValid1($energy_consumption_values);

			$hopper_inventory_values = DeviceData::where('machine_id', $id)
							->where('tag_id', 23)
							->orderBy('timestamp')
							->pluck('values');
			$hopper_inventories = $this->parseValid1($hopper_inventory_values);

			$hauloff_length_values = DeviceData::where('machine_id', $id)
							->where('tag_id', 24)
							->orderBy('timestamp')
							->pluck('values');
			$hauloff_lengths = $this->parseValid1($hauloff_length_values);

			$actual_points_values = DeviceData::where('machine_id', $id)
							->where('tag_id', 20)
							->pluck('values');
			$actual_points = $this->parseValid1($actual_points_values);

			$set_points_values = DeviceData::where('machine_id', $id)
							->where('tag_id', 21)
							->pluck('values');
			$set_points = $this->parseValid1($set_points_values);

			$alarm_types = AlarmType::where('machine_id', $id)->get();
			// $alarms = DeviceData::where('machine_id', $id)
			// 			->where('tag_id', 30)
			// 			->orderBy('timestamp')
			// 			->get();

			return response()->json(
				compact(
					'energy_consumption',
					'hopper_inventories',
					'hauloff_lengths',
					'alarm_types',
					// 'alarms',
					'set_points',
					'actual_points',
					'notes'
				)
			);
		} else {
			return response()->json(compact('machine'));
		}
	}

	private function parseValid($raw_array, $i) {
		try {
			$width = $raw_array->count() / $this->num_chunks + 1;
			$chunks = array_chunk(json_decode($raw_array), $width);
			return array_map(function($chunk) use ($i, $width) {
				$sum = 0; $count = 0;
				foreach ($chunk as $key => $item) {
					$sum += json_decode($item)[$i];
					$count ++;
				}
				return (int)($sum / $count);
				// return (int)($sum / $count);
			}, $chunks);
		} catch (\Exception $e) {
			return 0;
		}
	}

	private function parseValid1($raw_array) {
		try {
			$width = $raw_array->count() / $this->num_chunks + 1;
			$chunks = array_chunk(json_decode($raw_array), $width);
			return array_map(function($chunk) use ($width) {
				$sum = 0; $count = 0;
				foreach ($chunk as $key => $item) {
					$tmp = json_decode($item)[0];
					$sum += $tmp;
					$count ++;
				}

				return (int)($sum / $count);
			}, $chunks);
		} catch (\Exception $e) {
			return 0;
		}
	}

	public function parseValidWithTime($raw_array, $i, $from, $to) {
		$width = $raw_array->count() / 12 + 1;
		$chunks = array_chunk(json_decode($raw_array), $width);
		return array_map(function($chunk, $index) use ($i, $width, $from, $to) {
			$sum = 0; $count = 0;
			foreach ($chunk as $key => $item) {
				$sum += json_decode($item)[$i];
				$count ++;
			}
			return [($from + $index * ($to - $from) / 12) * 1000, (int)($sum / $count)];
		}, $chunks, array_keys($chunks));
	}

	/*
		Get total running and stopped hours
		Machine: BD Batch Blender
		params: data entries with running tag
		return: 2 length array - [0] is running hours, [1] is stopped hours
	*/
	private function totalRunningPercentage($data) {
		$ret = [0, 0];

		foreach ($data as $key => $item) {
			if($key) {
				$seconds_diff = $item->timestamp - $data[$key - 1]->timestamp;
				$hours_diff = (int)($seconds_diff / 3600);
				$is_previous_running = json_decode($data[$key - 1]->values)[0] == 1;
				if ($is_previous_running) {
					$ret[0] += $hours_diff;
				} else {
					$ret[1] += $hours_diff;
				}
			}
		}
		if($ret[0] + $ret[1])
			return $ret[0] / ($ret[0] + $ret[1]);
		else
			return 0;
	}

	public function getAcsLocationsTableData() {
		$locations = Location::orderBy('name')->get();

		foreach ($locations as $key => $location) {
			$downtime_distribution = $this->getDowntimeDistribution(1);
			$location->utilization = '32%';
			$location->color = 'green';
			$location->value = 75;
			$location->oee = '93.1%';
			$location->performance = '78%';
			$location->rate = 56;
			$location->downtimeDistribution = $downtime_distribution;
		}

		return response()->json(compact('locations'));
	}

	public function getAcsZonesTableData($location_id) {
		$location = Location::findOrFail($location_id);

		$zones = $location->zones;

		foreach ($zones as $key => $zone) {
			$downtime_distribution = $this->getDowntimeDistribution(1);
			$zone->utilization = '32%';
			$zone->color = 'green';
			$zone->value = 75;
			$zone->oee = '93.1%';
			$zone->performance = '78%';
			$zone->rate = 56;
			$zone->downtimeDistribution = $downtime_distribution;
		}

		return response()->json(compact('zones'));
	}

	public function getAcsMachinesTableData($zone_id) {
		$devices = Device::where('zone_id', $zone_id)->get();

		foreach ($devices as $key => $device) {
			$downtime_distribution = $this->getDowntimeDistribution(1);
			$device->utilization = '32%';
			$device->color = 'green';
			$device->value = 75;
			$device->oee = '93.1%';
			$device->performance = '78%';
			$device->rate = 56;
			$device->downtimeDistribution = $downtime_distribution;
		}

		return response()->json(compact('devices'));
	}

	/*
		Get downtime distribution of a machine in seconds
		Defalt start time is a week ago and default end time is now
	*/
	public function getDowntimeDistribution($id, $start = 0, $end = 0) {

		if(!$start) $start = strtotime("-7 days");
		if(!$end) $end = time();

		$ret = [0, 0, 0];	// 0 - Planned
							// 1 - Unplanned
							// 2 - Idle

		$running_values = DB::table('device_data')
							->where('machine_id', $id)
							->where('tag_id', 9)
							->where('timestamp', '>', $start)
							->where('timestamp', '<', $end)
							->orderBy('id')
							->get();

		$last_before_start = DeviceData::where('machine_id', $id)
							->where('tag_id', 9)
							->where('timestamp', '<', $start)
							->orderBy('timestamp')
							->first();

		if ($last_before_start) {
			$last_before_start_running = json_decode($last_before_start->values)[0];
		} else {
			$last_before_start_running = 1;
		}

		$count = $running_values->count();

		if(!$count && !$last_before_start) {
			return $ret;
		}

		$downtime_plans = DowntimePlan::where('machine_id', $id)->get();

		foreach ($running_values as $KEY => $item) {
			if(!$KEY) {
				if($last_before_start_running == 0) {
					// calculate downtime seconds
					$planned = 0;
					foreach ($downtime_plans as $key => $downtime_plan) {
						$planned += $this->overlapInSeconds(
							$start,
							$item->timestamp,
							strtotime($downtime_plan->dateFrom . ' ' . $downtime_plan->timeFrom),
							strtotime($downtime_plan->dateTo . ' ' . $downtime_plan->timeTo)
						);
					}
					$ret[0] += $planned;
					$ret[1] += ($item->timestamp - $start - $planned);
				}
			} else {
				if(json_decode($running_values[$KEY - 1]->values)[0] == 0) {
					$planned = 0;
					foreach ($downtime_plans as $key => $downtime_plan) {
						$planned += $this->overlapInSeconds(
							$running_values[$KEY - 1]->timestamp,
							$item->timestamp,
							strtotime($downtime_plan->dateFrom . ' ' . $downtime_plan->timeFrom),
							strtotime($downtime_plan->dateTo . ' ' . $downtime_plan->timeTo)
						);
					}
					$ret[0] += $planned;
					$ret[1] += ($item->timestamp - $running_values[$KEY - 1]->timestamp - $planned);
				}
			}
		}

		if($count && json_decode($running_values[$count - 1]->values)[0] == 0) {
			$planned = 0;
			foreach ($downtime_plans as $key => $downtime_plan) {
				$planned += $this->overlapInSeconds(
					$running_values[$count - 1]->timestamp,
					$end,
					strtotime($downtime_plan->dateFrom . ' ' . $downtime_plan->timeFrom),
					strtotime($downtime_plan->dateTo . ' ' . $downtime_plan->timeTo)
				);
			}
			$ret[0] += $planned;
			$ret[1] += ($end - $running_values[$count - 1]->timestamp - $planned);
		}
		
		return $ret;
	}

	/**
	 * What is the overlap, in seconds, of two time periods?
	 *
	 * @param $start1   string
	 * @param $end1     string
	 * @param $start2   string
	 * @param $end2     string
	 * @returns int     Overlap in seconds
	 */
	function overlapInSeconds($start1, $end1, $start2, $end2)
	{
	    // Figure out which is the later start time
	    $lastStart = $start1 >= $start2 ? $start1 : $start2;

	    // Figure out which is the earlier end time
	    $firstEnd = $end1 <= $end2 ? $end1 : $end2;

	    // Subtract the two, and round down
	    $overlap = floor($firstEnd - $lastStart);

	    // If the answer is greater than 0 use it.
	    // If not, there is no overlap.
	    return $overlap > 0 ? $overlap : 0;
	}

	public function getFromTo($data) {

		switch ($data["timeRangeOption"]) {
			case 'last30Min':
				return [
					"from" => strtotime("-30 min"),
					"to" => time()
				];
			case 'lastHour':
				return [
					"from" => strtotime("-1 hour"),
					"to" => time()
				];
			case 'last4Hours':
				return [
					"from" => strtotime("-4 hours"),
					"to" => time()
				];
			case 'last12Hours':
				return [
					"from" => strtotime("-12 hours"),
					"to" => time()
				];
			case 'last24Hours':
				return [
					"from" => strtotime("-1 day"),
					"to" => time()
				];
			case 'last48Hours':
				return [
					"from" => strtotime("-2 days"),
					"to" => time()
				];
			case 'last3Days':
				return [
					"from" => strtotime("-3 days"),
					"to" => time()
				];
			case 'last7Days':
				return [
					"from" => strtotime("-1 week"),
					"to" => time()
				];
			case 'last24Days':
				return [
					"from" => strtotime("-24 days"),
					"to" => time()
				];
			case 'custom':
				return [
					"from" => strtotime($data["dateFrom"] . ' ' . $data["timeFrom"]),
					"to" => strtotime($data["dateTo"] . ' ' . $data["timeTo"])
				];
				break;
			default:
				break;
		}
	}
}
