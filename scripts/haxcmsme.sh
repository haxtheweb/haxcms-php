#!/bin/bash
# Quick install: clone haxcms-php and start it with DDEV.
#   curl -fsSL https://raw.githubusercontent.com/haxtheweb/haxcms-php/master/scripts/haxcmsme.sh -o haxcmsme.sh && bash haxcmsme.sh
unameOut="$(uname -s)"
case "${unameOut}" in
    Linux*)     machine=Linux;;
    Darwin*)    machine=Mac;;
    CYGWIN*)    machine=Cygwin;;
    MINGW*)     machine=MinGw;;
    *)          machine="UNKNOWN:${unameOut}"
esac

# DDEV runs PHP, Node and Composer inside its containers, so nothing else
# (node, yarn, docker-compose) needs installing on the host.
windows_ddev() {
	# make sure chocolatey is installed
	if ! command -v choco;then
		echo "Please install Chocolatey then run this script again"
		echo "(https://chocolatey.org/install)"
		exit 1
	fi
	# --requirechecksums refuses any download the package does not checksum
	choco install ddev git --requirechecksums -y
}

unix_ddev() {
	# install docker if not installed
	if ! command -v docker;then
		curl -fsSL https://get.docker.com -o get-docker.sh
		sudo sh get-docker.sh
	fi
	if ! command -v ddev;then
		# pinned to ddev v1.25.4 (script from that tag commit, installing that version)
		curl -LO https://raw.githubusercontent.com/ddev/ddev/5da91aeb9ebab0b0e66171c450b72099308d332c/scripts/install_ddev.sh && bash install_ddev.sh v1.25.4
	fi
}

if [ "${machine}" == "Cygwin" ] || [ "${machine}" == "MinGw" ]; then
	windows_ddev
else
	unix_ddev
fi

if ! command -v git;then
	echo "Install git then run this script again"
	exit 1
fi

git clone https://github.com/haxtheweb/haxcms-php.git
cd haxcms-php/ || exit 1

# ddev start runs the .ddev post-start hooks, which seed the admin / admin user
ddev start
echo "HAXcms is running at https://haxcms.ddev.site (admin / admin; change this after first login)"
