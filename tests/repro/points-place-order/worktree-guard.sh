#!/bin/bash
W=/home/jlee/projects/underdog/intersoccer/_wt/pv-sibling-session-race
H=$(git -C $W rev-parse HEAD); S=$(git -C $W status --porcelain | wc -l)
echo "$(date '+%H:%M:%S %Z') guard $1 HEAD=$H dirty_lines=$S" | tee -a /tmp/repro-r5/guard.log
[[ $H == babd4ca072f4* && $S == 0 ]]
