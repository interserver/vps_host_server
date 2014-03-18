#!/usr/bin/php -q
<?php

/**
 * xml2array() will convert the given XML text to an array in the XML structure.
 * Link: http://www.bin-co.com/php/scripts/xml2array/
 * Arguments : $contents - The XML text
 *                $get_attributes - 1 or 0. If this is 1 the function will get the attributes as well as the tag values - this results in a different array structure in the return value.
 *                $priority - Can be 'tag' or 'attribute'. This will change the way the resulting array sturcture. For 'tag', the tags are given more importance.
 * Return: The parsed XML in an array form. Use print_r() to see the resulting array structure.
 * Examples: $array =  xml2array(file_get_contents('feed.xml'));
 *              $array =  xml2array(file_get_contents('feed.xml', 1, 'attribute'));
 */
function xml2array($contents, $get_attributes=1, $priority = 'tag') {
    if(!$contents) return array();

    if(!function_exists('xml_parser_create')) {
        //print "'xml_parser_create()' function not found!";
        return array();
    }

    //Get the XML parser of PHP - PHP must have this module for the parser to work
    $parser = xml_parser_create('');
    xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8"); # http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
    xml_parse_into_struct($parser, trim($contents), $xml_values);
    xml_parser_free($parser);

    if(!$xml_values) return;//Hmm...

    //Initializations
    $xml_array = array();
    $parents = array();
    $opened_tags = array();
    $arr = array();

    $current = &$xml_array; //Refference

    //Go through the tags.
    $repeated_tag_index = array();//Multiple tags with same name will be turned into an array
    foreach($xml_values as $data) {
        unset($attributes,$value);//Remove existing values, or there will be trouble

        //This command will extract these variables into the foreach scope
        // tag(string), type(string), level(int), attributes(array).
        extract($data);//We could use the array by itself, but this cooler.

        $result = array();
        $attributes_data = array();
        
        if(isset($value)) {
            if($priority == 'tag') $result = $value;
            else $result['value'] = $value; //Put the value in a assoc array if we are in the 'Attribute' mode
        }

        //Set the attributes too.
        if(isset($attributes) and $get_attributes) {
            foreach($attributes as $attr => $val) {
                if($priority == 'tag') $attributes_data[$attr] = $val;
                else $result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
            }
        }

        //See tag status and do the needed.
        if($type == "open") {//The starting of the tag '<tag>'
            $parent[$level-1] = &$current;
            if(!is_array($current) or (!in_array($tag, array_keys($current)))) { //Insert New tag
                $current[$tag] = $result;
                if($attributes_data) $current[$tag. '_attr'] = $attributes_data;
                $repeated_tag_index[$tag.'_'.$level] = 1;

                $current = &$current[$tag];

            } else { //There was another element with the same tag name

                if(isset($current[$tag][0])) {//If there is a 0th element it is already an array
                    $current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;
                    $repeated_tag_index[$tag.'_'.$level]++;
                } else {//This section will make the value an array if multiple tags with the same name appear together
                    $current[$tag] = array($current[$tag],$result);//This will combine the existing item and the new item together to make an array
                    $repeated_tag_index[$tag.'_'.$level] = 2;
                    
                    if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well
                        $current[$tag]['0_attr'] = $current[$tag.'_attr'];
                        unset($current[$tag.'_attr']);
                    }

                }
                $last_item_index = $repeated_tag_index[$tag.'_'.$level]-1;
                $current = &$current[$tag][$last_item_index];
            }

        } elseif($type == "complete") { //Tags that ends in 1 line '<tag />'
            //See if the key is already taken.
            if(!isset($current[$tag])) { //New Key
                $current[$tag] = $result;
                $repeated_tag_index[$tag.'_'.$level] = 1;
                if($priority == 'tag' and $attributes_data) $current[$tag. '_attr'] = $attributes_data;

            } else { //If taken, put all things inside a list(array)
                if(isset($current[$tag][0]) and is_array($current[$tag])) {//If it is already an array...

                    // ...push the new element into that array.
                    $current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;
                    
                    if($priority == 'tag' and $get_attributes and $attributes_data) {
                        $current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
                    }
                    $repeated_tag_index[$tag.'_'.$level]++;

                } else { //If it is not an array...
                    $current[$tag] = array($current[$tag],$result); //...Make it an array using using the existing value and the new value
                    $repeated_tag_index[$tag.'_'.$level] = 1;
                    if($priority == 'tag' and $get_attributes) {
                        if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well
                            
                            $current[$tag]['0_attr'] = $current[$tag.'_attr'];
                            unset($current[$tag.'_attr']);
                        }
                        
                        if($attributes_data) {
                            $current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
                        }
                    }
                    $repeated_tag_index[$tag.'_'.$level]++; //0 and 1 index is already taken
                }
            }

        } elseif($type == 'close') { //End of tag '</tag>'
            $current = &$parent[$level-1];
        }
    }
    
    return($xml_array);
}  

	/**
	 * get_vps_list()
	 * 
	 * @return
	 */
	function get_vps_list()
	{
		$url = 'https://myvps2.interserver.net/vps_queue.php';
		$curl_cmd = '';
		if (!file_exists('/usr/sbin/vzctl'))
		{
			$out = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh list --all | grep -v -e "State\$" -e "------\$" -e "^\$" | awk "{ print \\\$2 \" \" \\\$3 }"`);
			$lines = explode("\n", $out);
			$servers = array();
			$cmd = '';
			foreach ($lines as $serverline)
			{
				if (trim($serverline) != '')
				{
					$parts = explode(' ', $serverline);
					$name = $parts[0];
					$veid = str_replace(array('windows', 'linux'), array('', ''), $name);
					$status = $parts[1];
					$out = `export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh dumpxml $name`;
					$xml = xml2array($out);
					$server = array(
						'veid' => $veid, 
						'status' => $status,
						'name' => $name,
						'hostname' => $name, 
						'kmemsize' => $xml['domain']['memory'], 
						'mac' => $xml['domain']['devices']['interface']['mac_attr']['address'],
						'vnc' => $xml['domain']['devices']['graphics_attr']['port']
					);
					if ($status == 'running')
					{
/*
						$disk = trim(`/root/cpaneldirect/vps_kvm_disk_usage.sh $name`);
						if ($disk != '')
						{
							$dparts = explode(':', $disk);
							$server['diskused'] = $dparts[2];
							$server['diskmax'] = $dparts[1];
						}
*/
						$port = (integer)$xml['domain']['devices']['graphics_attr']['port'];
						if ($port >= 5900)
						{
							//echo "Port:" . $xml['domain']['devices']['graphics_attr']['port'] . "\n";
							$vncdisplay = (integer)abs($port - 5900);
							$cmd .= "function shot_${port} { touch shot_${port}.started;/root/cpaneldirect/vncsnapshot -compresslevel 9 -quality 100 -vncQuality 9 -allowblank -count 1 -fps 5 -quiet 127.0.0.1:${vncdisplay} shot1_${port}.jpg >/dev/null 2>&1; convert shot1_${port}.jpg -quality 75 shot_${port}.gif; rm -f shot_${port}.started; };\n shot_${port} &\n";
							$curl_cmd .= " -F shot".$port."=@shot_".$port.".gif";
							//$cmd .= "/root/cpaneldirect/vps_kvm_screenshot.sh $vncdisplay '$url?action=screenshot&name=$name' &\n";
						}
					}
					$servers[$veid] = $server;
				}
			}
			$cmd .= 'while [ -e "shot_*.started" ]; do sleep 1s; done;'."\n";
			//echo "CMD:$cmd\n";
			echo `$cmd`;
		}
		else
		{
			$out = `export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -a -o veid,numproc,status,ip,hostname,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H`;
			preg_match_all('/\s+(?P<veid>[^\s]+)\s+(?P<numproc>[^\s]+)\s+(?P<status>[^\s]+)\s+(?P<ip>[^\s]+)\s+(?P<hostname>[^\s]+)\s+(?P<kmemsize>[^\s]+)\s+(?P<kmemsize_f>[^\s]+)\s+(?P<lockedpages>[^\s]+)\s+(?P<lockedpages_f>[^\s]+)\s+(?P<privvmpages>[^\s]+)\s+(?P<privvmpages_f>[^\s]+)\s+(?P<shmpages>[^\s]+)\s+(?P<shmpages_f>[^\s]+)\s+(?P<numproc_f>[^\s]+)\s+(?P<physpages>[^\s]+)\s+(?P<physpages_f>[^\s]+)\s+(?P<vmguarpages>[^\s]+)\s+(?P<vmguarpages_f>[^\s]+)\s+(?P<oomguarpages>[^\s]+)\s+(?P<oomguarpages_f>[^\s]+)\s+(?P<numtcpsock>[^\s]+)\s+(?P<numtcpsock_f>[^\s]+)\s+(?P<numflock>[^\s]+)\s+(?P<numflock_f>[^\s]+)\s+(?P<numpty>[^\s]+)\s+(?P<numpty_f>[^\s]+)\s+(?P<numsiginfo>[^\s]+)\s+(?P<numsiginfo_f>[^\s]+)\s+(?P<tcpsndbuf>[^\s]+)\s+(?P<tcpsndbuf_f>[^\s]+)\s+(?P<tcprcvbuf>[^\s]+)\s+(?P<tcprcvbuf_f>[^\s]+)\s+(?P<othersockbuf>[^\s]+)\s+(?P<othersockbuf_f>[^\s]+)\s+(?P<dgramrcvbuf>[^\s]+)\s+(?P<dgramrcvbuf_f>[^\s]+)\s+(?P<numothersock>[^\s]+)\s+(?P<numothersock_f>[^\s]+)\s+(?P<dcachesize>[^\s]+)\s+(?P<dcachesize_f>[^\s]+)\s+(?P<numfile>[^\s]+)\s+(?P<numfile_f>[^\s]+)\s+(?P<numiptent>[^\s]+)\s+(?P<numiptent_f>[^\s]+)\s+(?P<diskspace>[^\s]+)\s+(?P<diskspace_s>[^\s]+)\s+(?P<diskspace_h>[^\s]+)\s+(?P<diskinodes>[^\s]+)\s+(?P<diskinodes_s>[^\s]+)\s+(?P<diskinodes_h>[^\s]+)\s+(?P<laverage>[^\s]+)/', $out, $matches);
			//print_r($matches);
			$servers = array();
			// build a list of servers, and then send an update command to make usre that the server has information on all servers
			foreach ($matches['veid'] as $key => $id)
			{
				$server = array(
					'veid' => $id, 
					'numproc' => $matches['numproc'][$key], 
					'status' => $matches['status'][$key], 
					'ip' => $matches['ip'][$key], 
					'hostname' => $matches['hostname'][$key], 
					'kmemsize' => $matches['kmemsize'][$key], 
					'kmemsize_f' => $matches['kmemsize_f'][$key], 
					'lockedpages' => $matches['lockedpages'][$key], 
					'lockedpages_f' => $matches['lockedpages_f'][$key], 
					'privvmpages' => $matches['privvmpages'][$key], 
					'privvmpages_f' => $matches['privvmpages_f'][$key], 
					'shmpages' => $matches['shmpages'][$key], 
					'shmpages_f' => $matches['shmpages_f'][$key], 
					'numproc_f' => $matches['numproc_f'][$key], 
					'physpages' => $matches['physpages'][$key], 
					'physpages_f' => $matches['physpages_f'][$key], 
					'vmguarpages' => $matches['vmguarpages'][$key], 
					'vmguarpages_f' => $matches['vmguarpages_f'][$key], 
					'oomguarpages' => $matches['oomguarpages'][$key], 
					'oomguarpages_f' => $matches['oomguarpages_f'][$key], 
					'numtcpsock' => $matches['numtcpsock'][$key], 
					'numtcpsock_f' => $matches['numtcpsock_f'][$key], 
					'numflock' => $matches['numflock'][$key], 
					'numflock_f' => $matches['numflock_f'][$key], 
					'numpty' => $matches['numpty'][$key], 
					'numpty_f' => $matches['numpty_f'][$key], 
					'numsiginfo' => $matches['numsiginfo'][$key], 
					'numsiginfo_f' => $matches['numsiginfo_f'][$key], 
					'tcpsndbuf' => $matches['tcpsndbuf'][$key], 
					'tcpsndbuf_f' => $matches['tcpsndbuf_f'][$key], 
					'tcprcvbuf' => $matches['tcprcvbuf'][$key], 
					'tcprcvbuf_f' => $matches['tcprcvbuf_f'][$key], 
					'othersockbuf' => $matches['othersockbuf'][$key], 
					'othersockbuf_f' => $matches['othersockbuf_f'][$key], 
					'dgramrcvbuf' => $matches['dgramrcvbuf'][$key], 
					'dgramrcvbuf_f' => $matches['dgramrcvbuf_f'][$key], 
					'numothersock' => $matches['numothersock'][$key], 
					'numothersock_f' => $matches['numothersock_f'][$key], 
					'dcachesize' => $matches['dcachesize'][$key], 
					'dcachesize_f' => $matches['dcachesize_f'][$key], 
					'numfile' => $matches['numfile'][$key], 
					'numfile_f' => $matches['numfile_f'][$key], 
					'numiptent' => $matches['numiptent'][$key], 
					'numiptent_f' => $matches['numiptent_f'][$key], 
					'diskspace' => $matches['diskspace'][$key], 
					'diskspace_s' => $matches['diskspace_s'][$key], 
					'diskspace_h' => $matches['diskspace_h'][$key], 
					'diskinodes' => $matches['diskinodes'][$key], 
					'diskinodes_s' => $matches['diskinodes_s'][$key], 
					'diskinodes_h' => $matches['diskinodes_h'][$key], 
					'laverage' => $matches['laverage'][$key], 
				);
				$servers[$id] = $server;
			}
			foreach ($servers as $id => $server)
			{
				$out = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzquota stat $id 2>/dev/null | grep blocks | awk '{ print $2 " " $3 }'`);
				if ($out != '')
				{
					$disk = explode(' ', $out);
					$servers[$id]['diskused'] = $disk[0];
					$servers[$id]['diskmax'] = $disk[1];
				}
			}
		}
		//print_r($servers);
		$cmd = 'curl --connect-timeout 60 --max-time 240 -k -F action=serverlist -F servers="' . base64_encode(gzcompress(serialize($servers), 9)) . '" ' . $curl_cmd . ' "' . $url . '" 2>/dev/null;';
		//echo "CMD: $cmd\n";
		echo trim(`$cmd`);
	}


	get_vps_list();
?>
