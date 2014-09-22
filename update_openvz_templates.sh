#!/bin/bash
export VZTPL="http://download.openvz.org/template/precreated"; \
export TEMPLATE_REPOS="${VZTPL} ${VZTPL}/contrib ${VZTPL}/unsupported"; \
unset VZTPL; \
if [ ! -e /usr/sbin/vztmpl-dl ]; then
 echo "No vztmpl-dl, bailing";
else
 vztmpl-dl --update-all;
fi; \
if [ ! -e /usr/bin/xz ]; then 
 yum -y install xz; 
fi; \
for i in /vz/template/cache/*.xz; do  
 if [ -e "$i" ]; then 
  f="$(echo "$i" | sed s#"\.xz$"#""#g)";  \
  mod=$(stat "$i" -c "%Y"); \
  update=1; \
  if [ -e ~/.xz_template_modified.txt ] && [ "$(grep "^${i}:" ~/.xz_template_modified.txt 2>/dev/null)" != "" ]; then
   oldmod="$(grep "^${i}:" ~/.xz_template_modified.txt 2>/dev/null)";
   if [ "$oldmod" =! "$mod" ]; then
    sed s#"^\(${i}\):.*$"#"\1:$mod"#g -i ~/.xz_template_modified.txt;
   else
    update=0;
   fi;
  else
   echo "$i:$mod" >> ~/.xz_template_modified.txt;
   update=2;
  fi;
  if [ $update -ge 1 ]; then
   if [ ! -e "${f}.gz" ] || [ $update -eq 2 ]; then 
    if [ $update -eq 2 ]; then 
     /bin/rm -f "$f.gz";
    fi;
    echo "UnXZing $i"; 
    xz -k -d "$i"; 
    echo "GZing $f"; 
    gzip -9 "$f"; 
   else 
    echo ".gz template already exists, skipping"; 
   fi; 
  else 
   echo "no such file(s) $i"; 
  fi; 
 fi;
done;
