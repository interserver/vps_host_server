#!/bin/bash
local=192.168.10.0/24
main=216.158.229.66
iptables -A FORWARD -s 192.168.10.0/24 -j ACCEPT
iptables -A FORWARD -d 192.168.10.0/24 -j ACCEPT
iptables -t nat -A POSTROUTING -s 192.168.10.0.24/ SNAT --to ${main}
