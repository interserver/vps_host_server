#!/bin/bash
set -x
for i in /vz/build/*.qcow2; do
        i="$(echo "$i"|sed s#"\.qcow2"#""#g)";
        ~/cpaneldirect/templates/install_template.sh $i "$1";
done
set +x
