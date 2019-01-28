#!/bin/bash

# set some defaults for publishing on the box
cat >/home/.ssh/config <<EOL

host github.com
  HostName github.com
  IdentityFile /var/www/html/_config/.ssh/haxyourweb
  User git

EOL
