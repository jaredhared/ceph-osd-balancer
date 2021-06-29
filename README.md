# Ceph OSD balancer
While Ceph Octopus internal balancing works not as good as expected, I decided to write my own balancing script to adjust OSD weights.

## Requirements
- jq - JSON parser
- PHP engine with JSON and Getopt functionality enabled

## Launch
By default, with no parameters provided it does nothing - just shows average OSD utilization and OSDs that are filled higher or lower of that value by 5%. Also, it generates commands to reweight OSDs (with 5% maximum increase/decrease).

Also, the balancer is designed with the possibility to be executed from Cron with minimal output, but I personally prefer to control my cluster in full and to run the balancer only manually.

## Available options
```
./balancer.php -h
Usage: ./balancer.php [options]

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

```
