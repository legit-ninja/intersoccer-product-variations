#!/bin/bash
# usage: GUARD_WT=<mounted worktree> GUARD_HEAD=<sha prefix> worktree-guard.sh <tag>  (defaults: pv-sibling-session-race / babd4ca072f4)
W=${GUARD_WT:-/home/jlee/projects/underdog/intersoccer/_wt/pv-sibling-session-race}; E=${GUARD_HEAD:-babd4ca072f4}; L=${GUARD_LOGDIR:-/tmp/repro-r5}
H=$(git -C $W rev-parse HEAD); S=$(git -C $W status --porcelain | wc -l)
mkdir -p $L; echo "$(date '+%H:%M:%S %Z') guard $1 W=$(basename $W) HEAD=$H dirty_lines=$S" | tee -a $L/guard.log
[[ $H == $E* && $S == 0 ]]
