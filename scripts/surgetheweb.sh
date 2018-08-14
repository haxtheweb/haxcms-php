#!/bin/sh
# Get surge.sh setup

# where am i? move to where I am. This ensures source is properly sourced
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
# move back to install root
cd ../
# run install to ensure we have it
npm install --global surge

#email
email=$1
#pwd
password=$2

cat <<EOF | surge login
$email
$password
EOF
projectname=$3
#domain
domain="${projectname}.surge.sh"
surge "_sites/${projectname}" $domain
