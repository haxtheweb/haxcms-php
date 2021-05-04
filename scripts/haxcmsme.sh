#!/bin/bash
unameOut="$(uname -s)"
case "${unameOut}" in
    Linux*)     machine=Linux;;
    Darwin*)    machine=Mac;;
    CYGWIN*)    machine=Cygwin;;
    MINGW*)     machine=MinGw;;
    *)          machine="UNKNOWN:${unameOut}"
esac

# make sure node is installed
if ! command -v node;then
	echo "Install node and npm first then re-run script"
	echo "Go to https://nodejs.org/en/download/ to download and install"
	exit
fi

# if yarn isn't installed install it
if ! command -v yarn;then
	npm -g install yarn
fi

git clone https://github.com/elmsln/HAXcms.git
cd HAXcms/

# install docker if not installed
if ! command -v docker;then
	curl -fsSL https://get.docker.com -o get-docker.sh
	sudo sh get-docker.sh
fi

windows_ddev() {
	# make sure chocolatey is installed
	if ! command -v choco;then
		echo "Please install Chocolatey then run again script again"
		echo "(https://chocolatey.org/install)"
	else
		choco install ddev git
	fi
}

linux_ddev() {
	if ! command -v ddev;then
		curl -LO https://raw.githubusercontent.com/drud/ddev/master/scripts/install_ddev.sh && bash install_ddev.sh
	fi
}

if [ "${machine}" == "Cygwin" ]; then
	windows_ddev
elif [ "${machine}" == "MinGw" ]; then
	windows_ddev
else
	if ! command -v docker-compose;then
		sudo curl -L "https://github.com/docker/compose/releases/download/1.29.1/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
		sudo chmod +x /usr/local/bin/docker-compose
		sudo ln -s /usr/local/bin/docker-compose /usr/bin/docker-compose
	fi
	linux_ddev
fi