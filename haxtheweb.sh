#!/bin/sh
# Welcome to HAXCMS. Decentralize already.

# where am i? move to where I am. This ensures source is properly sourced
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR

# Color. The vibrant and dancing melody of the sighted.
# provide messaging colors for output to console
txtbld=$(tput bold)             # BELIEVE ME. Bold.
bldgrn=$(tput setaf 2) #  WOOT. Green.
bldred=${txtbld}$(tput setaf 1) # Booooo get off the stage. Red.
txtreset=$(tput sgr0) # uhhh what?

# cave....cave....c a ve... c      a     v         e  ....
haxecho(){
  echo "${bldgrn}$1${txtreset}"
}
# EVERYTHING IS ON FIRE
haxwarn(){
  echo "${bldred}$1${txtreset}"
}
# Time - The final boss.
timestamp(){
  date +"%s"
}
# Create a unik, uneek, unqiue id.
getuuid(){
  uuidgen -rt
}

# Time to get down to brass tacks
sudo chmod 777 _sites
sudo chmod 775 _config
sudo chmod 777 _config/sites.json
# whew that was hard work. the end.


# jk
# echo a uuid to a salt file we can use later on
touch _config/SALT.txt
echo "$(getuuid)" > _config/SALT.txt
# write private key
pk="$(getuuid)"
sed -i "s/HAXTHEWEBPRIVATEKEY/${pk}/g" _config/config.php
# enter a super user name, dun dun dun dunnnnnnn!
read -rp "Super user name:" user
sed -i "s/jeff/${user}/g" _config/config.php
# a super, scary password prompt approaches. You roll a 31 and deal a critical security hit
read -rp "Super user password:" pass
sed -i "s/jimmerson/${pass}/g" _config/config.php
# only if you actually use apache
haxecho "www user, what does apache run as? (www-data and apache are common, hit enter to ignore this setting)"
read wwwuser

if [ -z ${wwwuser} ]; then
  # I've got a bad feeling about this
  haxwarn "did nothing, make sure your web server user can write to _sites"
else
  sudo chown ${wwwuser}:${wwwuser} _sites
fi

# you get candy if you reference this
haxecho "╔✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻╗"
haxecho "║                Welcome to the decentralization.               ║"
haxecho "║                                                               ║"
haxecho "║                   H  H      AAA    X   X                      ║"
haxecho "║                   H  H     A   A    X X                       ║"
haxecho "║                   HHHH     AAAAA     X                        ║"
haxecho "║                   H  H     A   A    X X                       ║"
haxecho "║                   H  H     A   A   X   X                      ║"
haxecho "║                                                               ║"
haxecho "╟───────────────────────────────────────────────────────────────╢"
haxecho "║ If you have issues, submit them to                            ║"
haxecho "║   http://github.com/elmsln/haxcms/issues                      ║"
haxecho "╟───────────────────────────────────────────────────────────────╢"
haxecho "║ ✻NOTES✻                                                       ║"
haxecho "║ All changes should be made in the _config/config.php file     ║"
haxecho "║ which has been setup during this install routine              ║"
haxecho "║                                                               ║"
haxecho "╠───────────────────────────────────────────────────────────────╣"
haxecho "║ Use  the following to get started:                            ║"
haxecho "║  username: $user                                              ║"
haxecho "║  password: $pass                                              ║"
haxecho "║                                                               ║"
haxecho "║                        ✻ Ex  Uno Plures ✻                     ║"
haxecho "║                        ✻ From one, Many ✻                     ║"
haxecho "╚✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻╝"