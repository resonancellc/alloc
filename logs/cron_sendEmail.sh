#!/bin/sh
#
# This script will trigger allocs daily task email summaries.
# Alex Lance <alla@cyber.com.au>
#

# path to cron and log files
PREFIX=`dirname $0`"/"

# wget the php script
wget -q -O ${PREFIX}sendEmail_log.new -P ${PREFIX} http://alloc/person/sendEmail.php

