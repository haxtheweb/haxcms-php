#!/bin/sh
# Welcome to HAXCMS. Decentralize already.

# where am i? Who am i? These, r the critical questions we will ponder in this sh
# This ensures source is properly sourced
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
  echo "$1" >> $installlog
}
# EVERYTHING IS ON FIRE
haxwarn(){
  echo "${bldred}$1${txtreset}"
  echo "$1" >> $installlog
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
chmod 777 _sites
chmod 775 _config
chmod 775 _config/sites.json
# whew that was hard work. the end.



# jk
# echo a uuid to a salt file we can use later on
touch _config/SALT.txt
echo "$(getuuid)" > _config/SALT.txt
# write private key
pk = $(getuuid)
sed -i "s/HAXTHEWEBPRIVATEKEY/${pk}/g" _config/config.php
# enter a super user name, dun dun dun dunnnnnnn!
haxecho "Super user name: "
read user
sed -i "s/jeff/${user}/g" _config/config.php
# a super, scary password prompt approaches. You roll a 31 and deal a critical security hit
haxecho "Super user password: "
read pass
sed -i "s/jimmerson/${pass}/g" _config/config.php
# only if you actually use apache
haxecho "www user, what does apache run as? (www-data and apache are common, hit enter to ignore this setting)"
read wwwuser

if [ -z wwwuser ]; then
  # I've got a bad feeling about this
else
  chown wwwuser:wwwuser _sites
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
haxecho "║  password: $pass                                          ║"
haxecho "║                                                               ║"
haxecho "║                        ✻ Ex  Uno Plures ✻                     ║"
haxecho "║                        ✻ From one, Many ✻                     ║"
haxecho "╚✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻✻╝"