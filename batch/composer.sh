#!/bin/bash

BASEDIR=$(dirname $0)
source $BASEDIR/parameters
cd $BASEDIR/..

$LOCAL_PHP composer.phar $@