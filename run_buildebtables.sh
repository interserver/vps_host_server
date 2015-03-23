#!/bin/bash
base="$(readlink -f "$(dirname "$0")")";
IFS="
";
${base}/buildebtablesrules 2>>/tmp/buildeb.err | bash 2>>/tmp/buildeb.err| grep -v -e '^$' > /tmp/buildeb.out;
echo "Running ${base}/buildebtables|bash  $(cat /tmp/buildeb.err | tr "\n" " ")";
if [ -z "$(grep Limiting /tmp/buildeb.out|cut -d" " -f2-|tr "\n" ' ')" ]; then
  echo " \- Limiting $(grep Limiting /tmp/buildeb.out|cut -d" " -f2-|tr "\n" ' ')";
fi;
if [ ! -z "$(grep 'does not exist' /tmp/buildeb.out | cut -d" " -f1 | tr "\n" " ")" ]; then
  echo " \- Does not exist $(grep 'does not exist' /tmp/buildeb.out | cut -d" " -f1 | tr "\n" " ")";
fi;
if [ ! -z "$(grep 'not running' /tmp/buildeb.out | cut -d" " -f1 | tr "\n" " ")" ]; then
  echo " \- Not Running $(grep 'not running' /tmp/buildeb.out | cut -d" " -f1 | tr "\n" " ")";
fi;
if [ ! -z "$(grep 'Blocking smtp' /tmp/buildeb.out | awk '{ print $5 " (" $7 "),  " }' | tr "\n" ' ')" ]; then
  echo " \- Blocking SMTP on $(grep 'Blocking smtp' /tmp/buildeb.out | awk '{ print $5 " (" $7 "),  " }' | tr "\n" ' ') done";
fi;
/bin/rm -f /tmp/buildeb.out /tmp/buildeb.err;
