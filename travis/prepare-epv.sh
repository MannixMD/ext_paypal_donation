#!/bin/bash
#
# PayPal Donation extension for the phpBB Forum Software package.
#
# @copyright (c) 2016 Matt Friedman
# @license GNU General Public License, version 2 (GPL-2.0)
#
set -e
set -x

EPV=$1
NOTESTS=$2

if [ "$EPV" == "1" -a "$NOTESTS" == "1" ]
then
	cd phpBB
	composer require phpbb/epv:dev-master --dev --no-interaction
	cd ../
fi
