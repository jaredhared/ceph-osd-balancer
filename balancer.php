#!/usr/bin/php
<?php

# ========================== Balancer v.1.02 =========================== #
#              Ceph OSD rebalance by utilization script                  #
#                 By Pavel Astakhov, jared@host4.biz                     #
#                               Oct 2020                                 #
# ====================================================================== #


// ================ Default options ==================

// Minimal delta in % between OSD utilization and average utilization to launch reweight for this OSD. 
$min_delta = 5;

// Maximum weight change in % of current weight
$max_change = 5;

// ============== End default options ================


$result_cmd="";


// Getting and parsing options

$opts = getopt("h::q::", ["apply::","apply-while-rebuild::","class:","min-delta:","max-change:","decrease-only::","increase-only::","help::","quiet::","set-default-weights::"]);

$opt_apply = (isset($opts["apply"])) ? true : false;
$opt_apply_rebuild = (isset($opts["apply-while-rebuild"])) ? true : false;
$opt_decrease = (isset($opts["decrease-only"])) ? true : false;
$opt_increase = (isset($opts["increase-only"])) ? true : false;
$opt_set_default_weights = (isset($opts["set-default-weights"])) ? true : false;
$opt_class = (isset($opts["class"])) ? $opts["class"] : false;
$opt_delta = (isset($opts["min-delta"])) ? $opts["min-delta"] : false;
$opt_max_change = (isset($opts["max-change"])) ? $opts["max-change"] : false;
$opt_help = (isset($opts["help"]) || isset($opts["h"])) ? true : false;
$opt_quiet = (isset($opts["quiet"]) || isset($opts["q"])) ? true : false;

if(is_numeric($opt_delta)) { $min_delta = abs($opt_delta); }
if(is_numeric($opt_max_change)) { $max_change = abs($opt_max_change); }
if($opt_help) { 
    echo "Usage: ".$argv[0]." [options]

  Available options (all are optional)

  --apply			Send generated commands to Ceph cluster. Works only if there are no misplaced or degraded objects. Default: false
  --apply-while-rebuild		Send generated commands to Ceph cluster even if there are misplaced or degraded objects in cluster. Default: false
  --decrease-only		Only decrease most loaded OSD weights. Default: false
  --increase-only		Only increase less loaded OSD weights. Default: false
  --set-default-weights		Generate commands to restore original OSD weights based on their size. Default: false
  --min-delta=value		Minimal difference in % between OSD utilization and average OSD utilization to generate reweight command. Default: 5
  --max-change=value		Maximum weight change in %. If the delta is higher, weight will be changed by this value only. Default: 5
  --class=osd_classname		Reweight only OSDs of that class. Default: all classes
  --quiet|-q			Quiet output, useful for Cron execution. Default: false
  --help|-h			This help page
";
    exit(0); 
};


// Getting drives info
$class_str="";
if ($opt_class) $class_str = ' and .device_class == "'.$opt_class.'"';

$jstr='{"0":['.`ceph -f json osd df tree | jq '.nodes[] | select ( .type == "osd" $class_str)' | sed 's/^}/},/' | sed -e '\$s/,\$//'`.']}';

$drives = json_decode($jstr, true);



// Collecting stats
$numdrives=0;
$avg_utilization=0;
foreach ($drives[0] as &$drive)
{
    $numdrives++;
    $avg_utilization += $drive['utilization'];
    $drive['default_weight'] = $drive['kb'] / 1073741824;
}

$avg_utilization = $avg_utilization / $numdrives;

if (!$opt_quiet) {
    echo "Minimal utilization delta: ".$min_delta."%\n";
    echo "Maximal weight change: ".$max_change."%\n";
    echo "Average utilization: ".$avg_utilization."\n\n";
}



// Setting default weights
if ($opt_set_default_weights) {
    foreach ($drives[0] as &$drive)
    {
	$result_cmd .= "ceph osd crush reweight ".$drive['name']." ".$drive['default_weight']."\n";
    }
}



// Calculating new weights
if (!$opt_quiet) { echo "Proposed reweights\n\n"; }

$drv_update_cnt=0;
if(!$opt_set_default_weights) {
    foreach ($drives[0] as &$drive)
    {
        $delta = $avg_utilization - $drive['utilization'];
        if (abs($delta) > $min_delta) {
        	$change = $drive['crush_weight'] * $delta / 100;
        	
        	if(abs($delta)>$max_change) {
        	    $change = $drive['crush_weight'] * ( $delta / abs($delta) ) * $max_change / 100;
        	}

		$modify = ( ($opt_decrease && $change<0) || ($opt_increase && $change>0) || (!$opt_increase && !$opt_decrease) ) ? true : false;
#		echo $modify."\n";

		if ($modify) {
		    $drv_update_cnt++;
        	    $new_weight = $drive['crush_weight'] + $change;
        	    if (!$opt_quiet) { echo $drive['name']."\t".$drive['crush_weight']." -> ".$new_weight."\t Utilization:".$drive['utilization']."\tDelta: ".$delta."%\n"; }
        	    $result_cmd .= "ceph osd crush reweight ".$drive['name']." ".$new_weight."\n";
		}
        }
    }
}



// Checking if there is rebuild/recovery in progress
if ( $opt_apply || $opt_apply_rebuild ) {
    $ok_to_apply=false;
    $misplaced = (int)`ceph status --format json-pretty | jq '.pgmap.misplaced_objects' | awk '{s=$1+0} END {print s}'`;
    $degraded = (int) `ceph status --format json-pretty | jq '.pgmap.degraded_objects' | awk '{s=$1+0} END {print s}'`;
    if($misplaced+$degraded > 0) {
        if($opt_apply_rebuild) {
        	$ok_to_apply=true;
        } elseif ($opt_apply) {
        	if (!$opt_quiet) { echo "\nCan not apply changes - rebuild/recovery is running!\n"; }
        }
    } else {
        $ok_to_apply=true;
    }
}


// Applying or printing reweights
if ( ($opt_apply || $opt_apply_rebuild) && $ok_to_apply) {
    $fh=fopen("/tmp/ceph-rebalance.cmd","w");
    fwrite($fh,$result_cmd);
    fclose($fh);

    exec('/bin/sh /tmp/ceph-rebalance.cmd');
}
elseif (!$opt_quiet) {
    echo "\n\nProposed reweight commands (".$drv_update_cnt." OSDs):\n\n";
    echo $result_cmd;
}

?>